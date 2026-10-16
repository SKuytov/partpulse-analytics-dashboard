<?php
declare(strict_types=1);

require_once __DIR__ . '/Normalizer.php';

/**
 * Imports one normalized PartPulse package as a dated, immutable snapshot,
 * merges the PartPulse change log into the permanent history and classifies
 * every order against the previous snapshot.
 */
final class OrderImporter
{
    /** Fields compared between snapshots. */
    public const TRACKED = [
        'status', 'priority', 'building_code', 'cost_center_id', 'category', 'item_description', 'quantity',
        'unit_price', 'total_price', 'supplier_name', 'requester_name', 'assigned_user_id',
        'date_needed', 'expected_delivery_date', 'approval_status', 'po_number',
    ];
    public const CLOSED = ['Delivered', 'Cancelled'];

    public function __construct(private PDO $pdo, private array $config) {}

    /**
     * Upload entry point. Accepts .sql (sanitized on the fly, raw dump discarded)
     * or a normalized .zip package.
     * @return array<string,mixed> import summary
     */
    public function importUpload(array $file, ?string $snapshotOverride = null): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Check the PHP upload limits (upload_max_filesize / post_max_size) and retry.');
        $max = (int)($this->config['max_upload_bytes'] ?? 25 * 1024 * 1024);
        if (($file['size'] ?? 0) < 1 || $file['size'] > $max) throw new RuntimeException('The file is empty or larger than the configured limit.');
        $name = basename((string)$file['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $tmp = (string)$file['tmp_name'];
        if ($snapshotOverride !== null && $snapshotOverride !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotOverride)) throw new RuntimeException('Invalid snapshot date.');

        $hash = hash_file('sha256', $tmp);
        $this->assertNewHash($hash);

        if ($ext === 'sql') {
            if (!($this->config['allow_sql_upload'] ?? true)) throw new RuntimeException('Direct SQL uploads are disabled. Use the PartPulse Normalizer and upload the ZIP.');
            $normalizer = new PartPulseNormalizer($this->config['source_timezone'] ?? 'UTC', $this->config['timezone'] ?? 'Europe/Sofia');
            $dataset = $normalizer->fromSqlFile($tmp, $snapshotOverride ?: null, $name);
            $sourceType = 'sql';
            // Only the sanitized package is archived; the raw dump is never stored.
            $stored = $this->storePackage($normalizer, $dataset);
        } elseif ($ext === 'zip') {
            $dataset = PartPulseNormalizer::readZip($tmp);
            if ($snapshotOverride) $dataset['manifest']['snapshot_date'] = $snapshotOverride;
            $sourceType = 'normalized';
            $stored = $this->storeFile($tmp, (string)($dataset['manifest']['snapshot_date'] ?? date('Y-m-d')), 'zip');
        } else {
            throw new RuntimeException('Order-level imports accept .sql (PartPulse export) or .zip (normalized package).');
        }
        try {
            return $this->import($dataset, [
                'original_name' => $name,
                'stored_name' => basename($stored),
                'file_hash' => $hash,
                'file_size' => (int)$file['size'],
                'source_type' => $sourceType,
            ]);
        } catch (Throwable $e) {
            @unlink($stored);
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function import(array $dataset, array $meta): array
    {
        $m = $dataset['manifest'];
        $t = $dataset['tables'];
        $snapshotDate = (string)($m['snapshot_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate)) throw new RuntimeException('The package has no valid snapshot date.');
        $orders = $t['orders.csv'] ?? [];
        if (!$orders) throw new RuntimeException('The package contains no orders.');
        $seen = [];
        foreach ($orders as $o) {
            $id = (int)$o['order_id'];
            if ($id <= 0) throw new RuntimeException('orders.csv contains an invalid order_id.');
            if (isset($seen[$id])) throw new RuntimeException("Duplicate order_id {$id} in orders.csv.");
            $seen[$id] = true;
        }

        $this->pdo->beginTransaction();
        try {
            $this->assertNewHash($meta['file_hash']);
            $ins = $this->pdo->prepare('INSERT INTO imports (snapshot_date, original_name, stored_name, file_hash, file_size, uploaded_by, source_type, source_generated_at, order_count) VALUES (?,?,?,?,?,?,?,?,?)');
            $ins->execute([
                $snapshotDate, $meta['original_name'], $meta['stored_name'], $meta['file_hash'], $meta['file_size'],
                (string)($_SESSION['admin_user'] ?? 'admin'), $meta['source_type'], $this->nullable($m['source_generated_at'] ?? null), count($orders),
            ]);
            $importId = (int)$this->pdo->lastInsertId();

            $this->upsertDimensions($t);
            $this->insertSnapshot($importId, $orders);
            $historyAdded = $this->mergeHistory($importId, $t['order_history.csv'] ?? []);
            $this->mergeAssignments($importId, $t['assignments.csv'] ?? []);
            $diff = $this->classify($importId, $snapshotDate);

            $summary = [
                'orders' => count($orders),
                'history_added' => $historyAdded,
                'previous_import_id' => $diff['previous'],
                'events' => $diff['counts'],
            ];
            $this->pdo->prepare('UPDATE imports SET history_added = ?, summary_json = ? WHERE id = ?')
                ->execute([$historyAdded, json_encode($summary, JSON_UNESCAPED_UNICODE), $importId]);
            $this->pdo->commit();
            return ['import_id' => $importId, 'snapshot_date' => $snapshotDate] + $summary;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function assertNewHash(string $hash): void
    {
        $q = $this->pdo->prepare('SELECT snapshot_date FROM imports WHERE file_hash = ? LIMIT 1');
        $q->execute([$hash]);
        $date = $q->fetchColumn();
        if ($date) throw new RuntimeException("This exact file was already imported (snapshot {$date}).");
    }

    private function storePackage(PartPulseNormalizer $normalizer, array $dataset): string
    {
        $dir = $this->uploadDir();
        $path = $dir . DIRECTORY_SEPARATOR . sprintf('%s_%s_%s_normalized.zip', $dataset['manifest']['snapshot_date'], date('His'), bin2hex(random_bytes(4)));
        $normalizer->writeZip($dataset, $path);
        return $path;
    }

    private function storeFile(string $tmp, string $date, string $ext): string
    {
        $path = $this->uploadDir() . DIRECTORY_SEPARATOR . sprintf('%s_%s_%s.%s', preg_replace('/[^0-9-]/', '', $date), date('His'), bin2hex(random_bytes(4)), $ext);
        $ok = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $path) : copy($tmp, $path);
        if (!$ok) throw new RuntimeException('The uploaded package could not be stored.');
        return $path;
    }

    private function uploadDir(): string
    {
        $dir = rtrim((string)$this->config['upload_dir'], DIRECTORY_SEPARATOR);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Could not create the uploads directory.');
        return $dir;
    }

    private function upsertDimensions(array $t): void
    {
        $sets = [
            'suppliers.csv' => ['dim_suppliers', ['supplier_id', 'name', 'country', 'is_eu', 'active']],
            'users.csv' => ['dim_users', ['user_id', 'display_name', 'role', 'building_code', 'active']],
            'buildings.csv' => ['dim_buildings', ['building_code', 'name', 'active']],
            'cost_centers.csv' => ['dim_cost_centers', ['cost_center_id', 'building_code', 'code', 'name', 'active']],
        ];
        foreach ($sets as $file => [$table, $cols]) {
            $rows = $t[$file] ?? [];
            if (!$rows) continue;
            $updates = implode(',', array_map(static fn($c) => "{$c}=VALUES({$c})", array_slice($cols, 1)));
            $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ") ON DUPLICATE KEY UPDATE {$updates}";
            $q = $this->pdo->prepare($sql);
            foreach ($rows as $r) {
                $vals = [];
                foreach ($cols as $c) {
                    $v = (string)($r[$c] ?? '');
                    if (in_array($c, ['is_eu', 'active'], true)) $v = $v === '' ? 1 : (int)$v;
                    $vals[] = $c === 'country' ? mb_substr($v, 0, 2) : $v;
                }
                if ($vals[0] === '') continue;
                $q->execute($vals);
            }
        }
    }

    public static function rowHash(array $o): string
    {
        $parts = [];
        foreach (self::TRACKED as $f) $parts[] = self::normValue($f, $o[$f] ?? '');
        return sha1(implode("\x1F", $parts));
    }

    public static function normValue(string $field, mixed $v): string
    {
        $v = trim((string)($v ?? ''));
        if (in_array($field, ['unit_price', 'total_price'], true)) return $v === '' ? '' : number_format((float)$v, 2, '.', '');
        if (in_array($field, ['quantity', 'cost_center_id', 'assigned_user_id'], true)) return $v === '' ? '' : (string)(int)$v;
        return $v;
    }

    private function insertSnapshot(int $importId, array $orders): void
    {
        $cols = ['import_id', 'order_id', 'submitted_at', 'updated_at', 'status', 'priority', 'building_code', 'cost_center_id', 'category',
            'item_description', 'part_number', 'quantity', 'unit_price', 'total_price', 'supplier_id', 'supplier_name', 'requester_id',
            'requester_name', 'assigned_user_id', 'date_needed', 'expected_delivery_date', 'delivery_confirmed_at', 'approval_status', 'po_number', 'row_hash'];
        $nullable = ['submitted_at', 'updated_at', 'cost_center_id', 'quantity', 'unit_price', 'total_price', 'supplier_id', 'requester_id',
            'assigned_user_id', 'date_needed', 'expected_delivery_date', 'delivery_confirmed_at'];
        $placeholder = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        foreach (array_chunk($orders, 250) as $chunk) {
            $vals = [];
            foreach ($chunk as $o) {
                foreach ($cols as $c) {
                    if ($c === 'import_id') { $vals[] = $importId; continue; }
                    if ($c === 'row_hash') { $vals[] = self::rowHash($o); continue; }
                    $v = trim((string)($o[$c] ?? ''));
                    if ($c === 'item_description') $v = mb_substr($v, 0, 500);
                    if ($c === 'status' && $v === '') $v = 'New';
                    if ($c === 'priority' && $v === '') $v = 'Normal';
                    $vals[] = ($v === '' && in_array($c, $nullable, true)) ? null : $v;
                }
            }
            $this->pdo->prepare('INSERT INTO order_snapshots (' . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $placeholder)))->execute($vals);
        }
    }

    private function mergeHistory(int $importId, array $rows): int
    {
        $added = 0;
        $placeholder = '(?,?,?,?,?,?,?,?,?,?)';
        foreach (array_chunk($rows, 300) as $chunk) {
            $vals = [];
            foreach ($chunk as $h) {
                array_push($vals, (string)$h['source'], (int)$h['source_id'], (int)$h['order_id'], mb_substr((string)$h['field_name'], 0, 100),
                    $h['old_value'] === '' ? null : mb_substr((string)$h['old_value'], 0, 255),
                    $h['new_value'] === '' ? null : mb_substr((string)$h['new_value'], 0, 255),
                    mb_substr((string)$h['changed_by'], 0, 150), $this->nullable($h['changed_at'] ?? null), (int)($h['date_only'] ?? 0), $importId);
            }
            $q = $this->pdo->prepare('INSERT IGNORE INTO order_history_log (source, source_id, order_id, field_name, old_value, new_value, changed_by, changed_at, date_only, first_import_id) VALUES ' . implode(',', array_fill(0, count($chunk), $placeholder)));
            $q->execute($vals);
            $added += $q->rowCount();
        }
        return $added;
    }

    private function mergeAssignments(int $importId, array $rows): void
    {
        foreach (array_chunk($rows, 300) as $chunk) {
            $vals = [];
            foreach ($chunk as $a) {
                array_push($vals, (int)$a['source_id'], (int)$a['order_id'], $this->intOrNull($a['from_user_id']), $this->intOrNull($a['to_user_id']),
                    $this->intOrNull($a['by_user_id']), (string)$a['assignment_type'], $this->nullable($a['created_at'] ?? null), $importId);
            }
            $this->pdo->prepare('INSERT IGNORE INTO order_assignments (source_id, order_id, from_user_id, to_user_id, by_user_id, assignment_type, created_at, first_import_id) VALUES ' . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?)')))->execute($vals);
        }
    }

    /** @return array{previous: ?int, counts: array<string,int>} */
    private function classify(int $importId, string $snapshotDate): array
    {
        $q = $this->pdo->prepare("SELECT id FROM imports WHERE source_type IN ('sql','normalized') AND id <> ? AND (snapshot_date < ? OR (snapshot_date = ? AND id < ?)) ORDER BY snapshot_date DESC, id DESC LIMIT 1");
        $q->execute([$importId, $snapshotDate, $snapshotDate, $importId]);
        $prev = $q->fetchColumn();
        $counts = ['new' => 0, 'completed' => 0, 'cancelled' => 0, 'reopened' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0];
        if (!$prev) {
            $counts['baseline'] = (int)$this->pdo->query('SELECT COUNT(*) FROM order_snapshots WHERE import_id = ' . $importId)->fetchColumn();
            return ['previous' => null, 'counts' => $counts];
        }
        $diff = self::diff($this->pdo, (int)$prev, $importId);
        $ev = $this->pdo->prepare('INSERT INTO order_events (import_id, prev_import_id, order_id, event_type, old_status, new_status, changed_fields) VALUES (?,?,?,?,?,?,?)');
        $ch = $this->pdo->prepare('INSERT INTO order_changes (import_id, order_id, field_name, old_value, new_value) VALUES (?,?,?,?,?)');
        foreach ($diff['rows'] as $r) {
            $counts[$r['type']]++;
            if ($r['type'] === 'unchanged') continue;
            $ev->execute([$importId, (int)$prev, $r['order_id'], $r['type'], $r['old_status'], $r['new_status'], mb_substr(implode(',', array_keys($r['changes'])), 0, 500)]);
            foreach ($r['changes'] as $field => [$old, $new]) $ch->execute([$importId, $r['order_id'], $field, mb_substr((string)$old, 0, 500), mb_substr((string)$new, 0, 500)]);
        }
        return ['previous' => (int)$prev, 'counts' => $counts];
    }

    /**
     * Classify every order between snapshot A and snapshot B.
     * @return array{rows: list<array<string,mixed>>, counts: array<string,int>}
     */
    public static function diff(PDO $pdo, int $a, int $b): array
    {
        $load = static function (int $id) use ($pdo): array {
            $q = $pdo->prepare('SELECT * FROM order_snapshots WHERE import_id = ?');
            $q->execute([$id]);
            $out = [];
            foreach ($q as $r) $out[(int)$r['order_id']] = $r;
            return $out;
        };
        $A = $load($a);
        $B = $load($b);
        $rows = [];
        $counts = ['new' => 0, 'completed' => 0, 'cancelled' => 0, 'reopened' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0];
        foreach ($B as $id => $nb) {
            $na = $A[$id] ?? null;
            if (!$na) {
                $rows[] = ['order_id' => $id, 'type' => 'new', 'old_status' => null, 'new_status' => $nb['status'], 'changes' => [], 'order' => $nb];
                $counts['new']++;
                continue;
            }
            $changes = [];
            if ($na['row_hash'] !== $nb['row_hash']) {
                foreach (self::TRACKED as $f) {
                    $o = self::normValue($f, $na[$f]);
                    $n = self::normValue($f, $nb[$f]);
                    if ($o !== $n) $changes[$f] = [$o, $n];
                }
            }
            $wasClosed = in_array($na['status'], self::CLOSED, true);
            $type = 'unchanged';
            if ($na['status'] !== $nb['status'] && $nb['status'] === 'Delivered' && !$wasClosed) $type = 'completed';
            elseif ($na['status'] !== $nb['status'] && $nb['status'] === 'Cancelled' && $na['status'] !== 'Cancelled') $type = 'cancelled';
            elseif ($wasClosed && !in_array($nb['status'], self::CLOSED, true)) $type = 'reopened';
            elseif ($changes) $type = 'updated';
            $counts[$type]++;
            $rows[] = ['order_id' => $id, 'type' => $type, 'old_status' => $na['status'], 'new_status' => $nb['status'], 'changes' => $changes, 'order' => $nb];
        }
        foreach ($A as $id => $na) {
            if (isset($B[$id])) continue;
            $counts['removed']++;
            $rows[] = ['order_id' => $id, 'type' => 'removed', 'old_status' => $na['status'], 'new_status' => null, 'changes' => [], 'order' => $na];
        }
        return ['rows' => $rows, 'counts' => $counts];
    }

    private function nullable(mixed $v): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : $v;
    }

    private function intOrNull(mixed $v): ?int
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : (int)$v;
    }
}

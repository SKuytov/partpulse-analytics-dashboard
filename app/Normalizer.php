<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlDumpReader.php';

/**
 * Converts a raw PartPulse Orders SQL export into the sanitized
 * "partpulse-normalized" package used by the dashboard.
 *
 * Only reporting fields are copied. Password hashes, e-mail addresses, phone
 * numbers, free-text order notes, supplier contacts, sessions, login logs,
 * documents, invoices and every other table are discarded.
 */
final class PartPulseNormalizer
{
    public const FORMAT = 'partpulse-normalized';
    public const VERSION = 1;
    public const TOOL_VERSION = '2.0.0';

    /** Normalized files and their exact column order. */
    public const FILES = [
        'orders.csv' => [
            'order_id', 'submitted_at', 'updated_at', 'status', 'priority', 'building_code', 'cost_center_id',
            'category', 'item_description', 'part_number', 'quantity', 'unit_price', 'total_price',
            'supplier_id', 'supplier_name', 'requester_id', 'requester_name', 'assigned_user_id',
            'date_needed', 'expected_delivery_date', 'delivery_confirmed_at', 'approval_status', 'po_number',
        ],
        'order_history.csv' => [
            'source', 'source_id', 'order_id', 'field_name', 'old_value', 'new_value', 'changed_by', 'changed_at', 'date_only',
        ],
        'assignments.csv' => [
            'source_id', 'order_id', 'from_user_id', 'to_user_id', 'by_user_id', 'assignment_type', 'created_at',
        ],
        'suppliers.csv' => ['supplier_id', 'name', 'country', 'is_eu', 'active'],
        'users.csv' => ['user_id', 'display_name', 'role', 'building_code', 'active'],
        'buildings.csv' => ['building_code', 'name', 'active'],
        'cost_centers.csv' => ['cost_center_id', 'building_code', 'code', 'name', 'active'],
    ];

    /** History fields whose values are free text and therefore redacted. */
    private const REDACT_VALUES = ['supplier_notes', 'alternative_product_description', 'notes', 'cancellation_reason', 'assignment_notes'];

    /** History fields that hold dates; PartPulse sometimes stores JavaScript Date strings. */
    private const DATE_FIELDS = ['expected_delivery_date', 'date_needed'];

    private const SOURCE_TABLES = ['orders', 'order_history', 'orders_audit_log', 'order_assignment_history', 'suppliers', 'users', 'buildings', 'cost_centers'];

    private DateTimeZone $sourceTz;
    private DateTimeZone $targetTz;

    public function __construct(string $sourceTimezone = 'UTC', string $targetTimezone = 'Europe/Sofia')
    {
        $this->sourceTz = new DateTimeZone($sourceTimezone);
        $this->targetTz = new DateTimeZone($targetTimezone);
    }

    /**
     * @return array{manifest: array<string,mixed>, tables: array<string, list<array<string,string>>>}
     */
    public function fromSqlFile(string $path, ?string $snapshotDate = null, ?string $originalName = null): array
    {
        $reader = new SqlDumpReader();
        $raw = array_fill_keys(self::SOURCE_TABLES, []);
        $reader->read($path, self::SOURCE_TABLES, static function (string $table, array $row) use (&$raw): void {
            $raw[$table][] = $row;
        });
        if (!$raw['orders']) throw new RuntimeException('No rows were found in the `orders` table. Export the full PartPulse Orders database with data.');
        foreach (['id', 'status', 'submission_date', 'building'] as $col) {
            if (!array_key_exists($col, $raw['orders'][0])) throw new RuntimeException("The `orders` table is missing the required column `{$col}`.");
        }

        $users = [];
        foreach ($raw['users'] as $u) {
            $users[(string)$u['id']] = [
                'user_id' => (string)$u['id'],
                'display_name' => $this->clean($u['name'] ?? $u['username'] ?? ''),
                'role' => (string)($u['role'] ?? ''),
                'building_code' => (string)($u['building'] ?? ''),
                'active' => (string)($u['active'] ?? '1'),
            ];
        }
        $suppliers = [];
        foreach ($raw['suppliers'] as $s) {
            $suppliers[(string)$s['id']] = [
                'supplier_id' => (string)$s['id'],
                'name' => $this->clean($s['name'] ?? ''),
                'country' => strtoupper(trim((string)($s['country'] ?? ''))),
                'is_eu' => (string)($s['is_eu'] ?? '0'),
                'active' => (string)($s['active'] ?? '1'),
            ];
        }

        $orders = [];
        foreach ($raw['orders'] as $o) {
            if (!empty($o['is_template'])) continue;
            $supplierId = (string)($o['supplier_id'] ?? '');
            $supplierName = $supplierId !== '' && isset($suppliers[$supplierId]) ? $suppliers[$supplierId]['name'] : $this->clean($o['supplier'] ?? '');
            $orders[] = [
                'order_id' => (string)$o['id'],
                'submitted_at' => $this->dt($o['submission_date'] ?? null),
                'updated_at' => $this->dt($o['updated_at'] ?? null),
                'status' => (string)($o['status'] ?? ''),
                'priority' => (string)($o['priority'] ?? 'Normal'),
                'building_code' => (string)($o['building'] ?? ''),
                'cost_center_id' => (string)($o['cost_center_id'] ?? ''),
                'category' => $this->clean($o['category'] ?? ''),
                'item_description' => $this->clip($this->clean($o['item_description'] ?? ''), 500),
                'part_number' => $this->clip($this->clean($o['part_number'] ?? ''), 100),
                'quantity' => (string)($o['quantity'] ?? ''),
                'unit_price' => $this->num($o['unit_price'] ?? null),
                'total_price' => $this->num($o['total_price'] ?? null),
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierName,
                'requester_id' => (string)($o['requester_id'] ?? ''),
                // Current display name from the user directory (matches the PartPulse reports), else the stored name.
                'requester_name' => ($users[(string)($o['requester_id'] ?? '')]['display_name'] ?? '') ?: $this->clean($o['requester_name'] ?? ''),
                'assigned_user_id' => (string)($o['assigned_to_user_id'] ?? ($o['assigned_to'] ?? '')),
                'date_needed' => (string)($o['date_needed'] ?? ''),
                'expected_delivery_date' => (string)($o['expected_delivery_date'] ?? ''),
                'delivery_confirmed_at' => $this->dt($o['delivery_confirmed_at'] ?? null),
                'approval_status' => (string)($o['approval_status'] ?? ''),
                'po_number' => $this->clip($this->clean($o['po_number'] ?? ''), 50),
            ];
        }

        $history = [];
        foreach ($raw['order_history'] as $h) {
            $field = (string)$h['field_name'];
            $redact = in_array($field, self::REDACT_VALUES, true);
            if (in_array($field, self::DATE_FIELDS, true)) {
                $h['old_value'] = $this->dateValue($h['old_value'] ?? null);
                $h['new_value'] = $this->dateValue($h['new_value'] ?? null);
            }
            $history[] = [
                'source' => 'history',
                'source_id' => (string)$h['id'],
                'order_id' => (string)$h['order_id'],
                'field_name' => $field,
                'old_value' => $redact ? ($h['old_value'] === null || $h['old_value'] === '' ? '' : '[text]') : $this->clip($this->clean($h['old_value'] ?? ''), 255),
                'new_value' => $redact ? ($h['new_value'] === null || $h['new_value'] === '' ? '' : '[text]') : $this->clip($this->clean($h['new_value'] ?? ''), 255),
                'changed_by' => $this->clean($h['changed_by'] ?? ''),
                'changed_at' => $this->dt($h['changed_at'] ?? null),
                'date_only' => $this->isMidnight($h['changed_at'] ?? null) ? '1' : '0',
            ];
        }
        foreach ($raw['orders_audit_log'] as $a) {
            $by = (string)($a['changed_by'] ?? '');
            $history[] = [
                'source' => 'audit',
                'source_id' => (string)$a['id'],
                'order_id' => (string)$a['order_id'],
                'field_name' => (string)$a['field_name'],
                'old_value' => $this->clip($this->clean($a['old_value'] ?? ''), 255),
                'new_value' => $this->clip($this->clean($a['new_value'] ?? ''), 255),
                'changed_by' => isset($users[$by]) ? $users[$by]['display_name'] : ($by !== '' ? 'user #' . $by : 'system'),
                'changed_at' => $this->dt($a['changed_at'] ?? null),
                'date_only' => $this->isMidnight($a['changed_at'] ?? null) ? '1' : '0',
            ];
        }

        $assignments = [];
        foreach ($raw['order_assignment_history'] as $a) {
            $assignments[] = [
                'source_id' => (string)$a['id'],
                'order_id' => (string)$a['order_id'],
                'from_user_id' => (string)($a['assigned_from_user_id'] ?? ''),
                'to_user_id' => (string)($a['assigned_to_user_id'] ?? ''),
                'by_user_id' => (string)($a['assigned_by_user_id'] ?? ''),
                'assignment_type' => (string)($a['assignment_type'] ?? ''),
                'created_at' => $this->dt($a['created_at'] ?? null),
            ];
        }

        $buildings = array_map(fn($b) => [
            'building_code' => (string)$b['code'],
            'name' => $this->clean($b['name'] ?? ''),
            'active' => (string)($b['active'] ?? '1'),
        ], $raw['buildings']);
        $costCenters = array_map(fn($c) => [
            'cost_center_id' => (string)$c['id'],
            'building_code' => (string)($c['building_code'] ?? ''),
            'code' => $this->clean($c['code'] ?? ''),
            'name' => $this->clean($c['name'] ?? ''),
            'active' => (string)($c['active'] ?? '1'),
        ], $raw['cost_centers']);

        $generatedAt = $reader->generatedAt() ? $this->dt($reader->generatedAt()) : null;
        if (!$generatedAt) {
            $latest = max(array_map(static fn($o) => max($o['submitted_at'], $o['updated_at']), $orders));
            $generatedAt = $latest ?: date('Y-m-d H:i:s');
        }
        $snapshotDate = $snapshotDate ?: self::dateFromName((string)$originalName) ?: substr($generatedAt, 0, 10);

        $tables = [
            'orders.csv' => $orders,
            'order_history.csv' => $history,
            'assignments.csv' => $assignments,
            'suppliers.csv' => array_values($suppliers),
            'users.csv' => array_values($users),
            'buildings.csv' => $buildings,
            'cost_centers.csv' => $costCenters,
        ];

        return [
            'manifest' => [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'tool_version' => self::TOOL_VERSION,
                'snapshot_date' => $snapshotDate,
                'source_generated_at' => $generatedAt,
                'normalized_at' => (new DateTimeImmutable('now', $this->targetTz))->format('Y-m-d H:i:s'),
                'timezone' => $this->targetTz->getName(),
                'source_timezone' => $this->sourceTz->getName(),
                'source_database' => $reader->databaseName(),
                'source_file' => $originalName ? basename($originalName) : null,
                'source_sha256' => hash_file('sha256', $path),
                'counts' => array_map('count', $tables),
                'removed' => 'password hashes, usernames, e-mails, phones, order notes, supplier contacts, sessions, login logs, documents, invoices',
            ],
            'tables' => $tables,
        ];
    }

    public function writeZip(array $dataset, string $zipPath): void
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('PHP zip extension is missing. Enable extension=zip in php.ini.');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create ' . $zipPath);
        $checksums = [];
        foreach (self::FILES as $file => $columns) {
            $csv = self::toCsv($columns, $dataset['tables'][$file] ?? []);
            $checksums[$file] = hash('sha256', $csv);
            $zip->addFromString($file, $csv);
        }
        $manifest = $dataset['manifest'];
        $manifest['checksums'] = $checksums;
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();
    }

    /** @return array{manifest: array<string,mixed>, tables: array<string, list<array<string,string>>>} */
    public static function readZip(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('PHP zip extension is missing on the server.');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) throw new RuntimeException('The ZIP package could not be opened.');
        try {
            $manifestJson = $zip->getFromName('manifest.json');
            if ($manifestJson === false) throw new RuntimeException('manifest.json is missing. Create the package with the PartPulse Normalizer.');
            $manifest = json_decode($manifestJson, true);
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) throw new RuntimeException('This ZIP is not a PartPulse normalized package.');
            if ((int)($manifest['version'] ?? 0) > self::VERSION) throw new RuntimeException('This package was created by a newer normalizer. Update the dashboard first.');
            $tables = [];
            foreach (self::FILES as $file => $columns) {
                $csv = $zip->getFromName($file);
                if ($csv === false) {
                    if ($file === 'orders.csv') throw new RuntimeException('orders.csv is missing from the package.');
                    $tables[$file] = [];
                    continue;
                }
                if (isset($manifest['checksums'][$file]) && !hash_equals((string)$manifest['checksums'][$file], hash('sha256', $csv))) {
                    throw new RuntimeException("Checksum mismatch for {$file}. The package was modified or damaged.");
                }
                $tables[$file] = self::fromCsv($csv, $columns, $file);
            }
            return ['manifest' => $manifest, 'tables' => $tables];
        } finally {
            $zip->close();
        }
    }

    public static function toCsv(array $columns, array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        fputcsv($h, $columns, ',', '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $c) $line[] = (string)($row[$c] ?? '');
            fputcsv($h, $line, ',', '"', '');
        }
        rewind($h);
        $csv = stream_get_contents($h);
        fclose($h);
        return (string)$csv;
    }

    /** @return list<array<string,string>> */
    public static function fromCsv(string $csv, array $expected, string $file): array
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) $csv = substr($csv, 3);
        $h = fopen('php://temp', 'w+');
        fwrite($h, $csv);
        rewind($h);
        $header = fgetcsv($h, null, ',', '"', '');
        if (!$header) { fclose($h); return []; }
        $header = array_map('trim', $header);
        $missing = array_diff($expected, $header);
        if ($missing) { fclose($h); throw new RuntimeException("{$file} is missing columns: " . implode(', ', $missing)); }
        $rows = [];
        $n = count($header);
        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            if ($r === [null]) continue;
            if (count($r) !== $n) { fclose($h); throw new RuntimeException("{$file} contains a malformed row (" . (count($rows) + 2) . ').'); }
            $rows[] = array_combine($header, $r);
        }
        fclose($h);
        return $rows;
    }

    public static function dateFromName(string $name): ?string
    {
        if (!preg_match('/(20\d{2})[-_.](\d{2})[-_.](\d{2})/', $name, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    private function dt(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) return '';
        try {
            $d = new DateTimeImmutable($value, $this->sourceTz);
            return $d->setTimezone($this->targetTz)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '';
        }
    }

    /** "Fri Oct 16 2026 00:00:00 GMT+0000 (Coordinated Universal Time)" / ISO strings -> Y-m-d. */
    private function dateValue(?string $value): ?string
    {
        $v = trim((string)$value);
        if ($v === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]/', $v, $m) && !str_ends_with($v, 'Z')) return $m[1];
        $clean = preg_replace('/\s*\(.*\)\s*$/', '', $v) ?? $v;
        $ts = strtotime($clean);
        if ($ts === false) return $v;
        // Dates written by a browser at local midnight are shifted into the target zone.
        return (new DateTimeImmutable('@' . $ts))->setTimezone($this->targetTz)->format('Y-m-d');
    }

    private function isMidnight(?string $value): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/', trim((string)$value));
    }

    private function num(?string $value): string
    {
        if ($value === null || trim($value) === '') return '';
        return is_numeric($value) ? (string)round((float)$value, 2) : '';
    }

    private function clean(?string $value): string
    {
        $value = (string)$value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function clip(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/OrderImporter.php';

/**
 * Order-level analytics computed from the latest (or selected) snapshot plus the
 * permanent PartPulse change log. Every period metric is reproducible for any
 * date range because order timelines are rebuilt from timestamped events.
 */
final class PartPulseAnalytics
{
    public const OPEN_FLOW = ['New', 'Pending', 'Quote Requested', 'Quote Received', 'Quote Under Approval', 'Approved', 'Ordered', 'In Transit', 'Partially Delivered', 'On Hold'];
    private const CLOSED = ['Delivered', 'Cancelled'];
    private const DEFAULT_SLA = ['New' => 2, 'Pending' => 3, 'Quote Requested' => 5, 'Quote Received' => 3, 'Quote Under Approval' => 2, 'Approved' => 3, 'Ordered' => 14, 'In Transit' => 7];
    private const FIELD_LABELS = [
        'status' => 'Status', 'expected_delivery_date' => 'Delivery date', 'total_price' => 'Total price', 'unit_price' => 'Unit price',
        'supplier_id' => 'Supplier', 'supplier_notes' => 'Supplier notes', 'alternative_product_name' => 'Alternative product',
        'alternative_product_description' => 'Alternative description', 'quote_ref' => 'Quote', 'cancellation_reason' => 'Cancellation reason',
    ];

    private array $orders = [];
    private array $timeline = [];
    private array $history = [];
    private array $import = [];
    private string $asOf = '';
    private array $suppliers = [];

    public function __construct(private PDO $pdo, private array $config) {}

    public function orderImports(): array
    {
        return $this->pdo->query("SELECT id, DATE_FORMAT(snapshot_date,'%Y-%m-%d') snapshot_date, original_name, source_type, source_generated_at, order_count, history_added, summary_json, created_at FROM imports WHERE source_type IN ('sql','normalized') ORDER BY snapshot_date DESC, id DESC")->fetchAll();
    }

    /** Load universe for the selected import and filters. */
    public function load(?int $importId, array $filters): bool
    {
        $imports = $this->orderImports();
        if (!$imports) return false;
        $selected = $imports[0];
        foreach ($imports as $i) if ($importId && (int)$i['id'] === $importId) $selected = $i;
        $this->import = $selected;
        $this->asOf = $selected['source_generated_at'] ?: ($selected['snapshot_date'] . ' 23:59:59');

        $this->suppliers = $this->pdo->query('SELECT supplier_id, name FROM dim_suppliers')->fetchAll(PDO::FETCH_KEY_PAIR);
        $q = $this->pdo->prepare('SELECT s.*, b.name building_name, cc.code cost_center_code, cc.name cost_center_name, u.display_name assigned_name
            FROM order_snapshots s
            LEFT JOIN dim_buildings b ON b.building_code = s.building_code
            LEFT JOIN dim_cost_centers cc ON cc.cost_center_id = s.cost_center_id
            LEFT JOIN dim_users u ON u.user_id = s.assigned_user_id
            WHERE s.import_id = ?');
        $q->execute([(int)$selected['id']]);
        $this->orders = [];
        foreach ($q as $o) {
            if (!$this->matches($o, $filters)) continue;
            $o['order_id'] = (int)$o['order_id'];
            $o['total_price'] = $o['total_price'] === null ? null : (float)$o['total_price'];
            $o['category'] = $o['category'] !== '' ? $o['category'] : 'Uncategorised';
            $o['supplier_name'] = $o['supplier_name'] !== '' ? $o['supplier_name'] : '—';
            $this->orders[$o['order_id']] = $o;
        }

        $h = $this->pdo->prepare('SELECT id, source, order_id, field_name, old_value, new_value, changed_by, changed_at, date_only FROM order_history_log WHERE changed_at IS NOT NULL AND changed_at <= ? ORDER BY changed_at, id');
        $h->execute([$this->asOf]);
        $aliases = $this->config['actor_aliases'] ?? [];
        $this->history = [];
        foreach ($h as $r) {
            $oid = (int)$r['order_id'];
            if (!isset($this->orders[$oid])) continue;
            $r['order_id'] = $oid;
            $actor = trim((string)$r['changed_by']);
            $r['actor'] = $aliases[$actor] ?? ($actor !== '' ? $actor : 'system');
            $this->history[] = $r;
        }
        $this->buildTimelines();
        return true;
    }

    private function matches(array $o, array $f): bool
    {
        foreach (['building' => 'building_code', 'priority' => 'priority', 'supplier' => 'supplier_name', 'requester' => 'requester_name', 'category' => 'category', 'status' => 'status'] as $k => $col) {
            if (!empty($f[$k])) {
                $val = $col === 'category' && $o[$col] === '' ? 'Uncategorised' : (string)$o[$col];
                if ($col === 'supplier_name' && $val === '') $val = '—';
                if (!in_array($val, (array)$f[$k], true)) return false;
            }
        }
        if (!empty($f['q'])) {
            $needle = mb_strtolower((string)$f['q']);
            $hay = mb_strtolower($o['order_id'] . ' ' . $o['item_description'] . ' ' . $o['part_number'] . ' ' . $o['po_number']);
            if (!str_contains($hay, $needle)) return false;
        }
        return true;
    }

    private function buildTimelines(): void
    {
        $byOrder = [];
        foreach ($this->history as $r) if ($r['field_name'] === 'status') $byOrder[$r['order_id']][] = $r;
        $this->timeline = [];
        foreach ($this->orders as $id => $o) {
            $submitted = $o['submitted_at'] ?: $o['updated_at'] ?: $this->asOf;
            $events = $byOrder[$id] ?? [];
            $intervals = [];
            $openSince = $submitted;
            $lastStatusAt = $submitted;
            $firstOrdered = null;
            $closedAtByStatus = [];
            foreach ($events as $e) {
                $ts = max($e['changed_at'], $submitted);
                $new = (string)$e['new_value'];
                $lastStatusAt = $ts;
                if ($new === 'Ordered' && !$firstOrdered) $firstOrdered = $ts;
                if (in_array($new, self::CLOSED, true)) {
                    $closedAtByStatus[$new] = $ts;
                    if ($openSince !== null) { $intervals[] = [$openSince, $ts]; $openSince = null; }
                } elseif ($openSince === null) {
                    $openSince = $ts;
                }
            }
            $status = $o['status'];
            $closedAt = null;
            if (in_array($status, self::CLOSED, true)) {
                $closedAt = $closedAtByStatus[$status] ?? null;
                if (!$closedAt || ($openSince !== null)) {
                    $fallback = $o['delivery_confirmed_at'] ?: ($o['updated_at'] ?: $submitted);
                    $closedAt = max($fallback, $openSince ?? $submitted);
                    if ($openSince !== null) { $intervals[] = [$openSince, $closedAt]; $openSince = null; }
                    if (!$events) $lastStatusAt = $closedAt;
                }
            } elseif ($openSince === null) {
                $openSince = $lastStatusAt;
            }
            if ($openSince !== null) $intervals[] = [$openSince, null];
            $lead = null;
            if ($status === 'Delivered' && $closedAt) $lead = max(0, (strtotime($closedAt) - strtotime($submitted)) / 86400);
            $this->timeline[$id] = [
                'submitted' => $submitted,
                'closed_at' => $closedAt,
                'intervals' => $intervals,
                'last_status_at' => $lastStatusAt,
                'ordered_at' => $firstOrdered,
                'lead_days' => $lead,
                'events' => $events,
            ];
        }
    }

    /** Full dashboard payload. */
    public function dashboard(string $from, string $to, ?string $cmpFrom, ?string $cmpTo, ?string $actor): array
    {
        $current = $this->periodStats($from, $to, $actor);
        $compare = ($cmpFrom && $cmpTo) ? $this->periodStats($cmpFrom, $cmpTo, $actor, false) : null;
        return [
            'period' => ['from' => $from, 'to' => $to, 'compare_from' => $cmpFrom, 'compare_to' => $cmpTo],
            'as_of' => $this->asOf,
            'import' => $this->publicImport($this->import),
            'universe' => count($this->orders),
            'kpis' => $current['kpis'],
            'compare' => $compare['kpis'] ?? null,
            'series' => $current['series'],
            'compare_series' => $compare['series'] ?? null,
            'status_start' => $this->statusMixAt($this->dayBefore($from) . ' 23:59:59'),
            'status_end' => $this->statusMixAt(min($to . ' 23:59:59', $this->asOf)),
            'transitions' => $current['transitions'],
            'buildings' => $current['buildings'],
            'suppliers' => $current['suppliers'],
            'categories' => $current['categories'],
            'requesters' => $current['requesters'],
            'actors' => $current['actors'],
            'fields' => $current['fields'],
            'heatmap' => $current['heatmap'],
            'lead_histogram' => $current['lead_histogram'],
            'aging' => $this->aging(min($to . ' 23:59:59', $this->asOf)),
            'sla' => $this->sla(),
            'overdue' => $this->overdue(),
            'changes' => $current['changes'],
            'changes_truncated' => $current['changes_truncated'],
        ];
    }

    private function periodStats(string $from, string $to, ?string $actor, bool $detail = true): array
    {
        $start = $from . ' 00:00:00';
        $end = $to . ' 23:59:59';
        $in = static fn(?string $ts): bool => $ts !== null && $ts !== '' && $ts >= $start && $ts <= $end;
        $gran = $this->granularity($from, $to);
        $buckets = $this->buckets($from, $to, $gran);
        $series = [];
        foreach ($buckets as $b) $series[$b] = ['bucket' => $b, 'incoming' => 0, 'completed' => 0, 'cancelled' => 0, 'backlog' => 0, 'activity' => 0, 'spend' => 0.0];

        $incoming = $completed = $cancelled = 0;
        $spend = 0.0;
        $orderedValue = 0.0;
        $orderedCount = 0;
        $leads = [];
        $onTime = $withNeed = 0;
        $byBuilding = $bySupplier = $byCategory = $byRequester = [];
        $completedIds = [];
        foreach ($this->orders as $id => $o) {
            $t = $this->timeline[$id];
            $bKey = $o['building_code'] ?: '—';
            if ($in($t['submitted'])) {
                $incoming++;
                $series[$this->bucketOf($t['submitted'], $gran)]['incoming']++;
                $byBuilding[$bKey]['incoming'] = ($byBuilding[$bKey]['incoming'] ?? 0) + 1;
                $byCategory[$o['category']]['incoming'] = ($byCategory[$o['category']]['incoming'] ?? 0) + 1;
                $byCategory[$o['category']]['value'] = ($byCategory[$o['category']]['value'] ?? 0) + (float)$o['total_price'];
                $rq = $o['requester_name'] ?: '—';
                $byRequester[$rq]['incoming'] = ($byRequester[$rq]['incoming'] ?? 0) + 1;
                if (!in_array($o['status'], self::CLOSED, true)) $byRequester[$rq]['open'] = ($byRequester[$rq]['open'] ?? 0) + 1;
            }
            if ($t['ordered_at'] && $in($t['ordered_at'])) { $orderedCount++; $orderedValue += (float)$o['total_price']; }
            if ($t['closed_at'] && $in($t['closed_at'])) {
                $bk = $this->bucketOf($t['closed_at'], $gran);
                if ($o['status'] === 'Delivered') {
                    $completed++;
                    $completedIds[] = $id;
                    $price = (float)$o['total_price'];
                    $spend += $price;
                    $series[$bk]['completed']++;
                    $series[$bk]['spend'] += $price;
                    if ($t['lead_days'] !== null) $leads[] = $t['lead_days'];
                    if ($o['date_needed']) { $withNeed++; if (substr($t['closed_at'], 0, 10) <= $o['date_needed']) $onTime++; }
                    $byBuilding[$bKey]['completed'] = ($byBuilding[$bKey]['completed'] ?? 0) + 1;
                    $byBuilding[$bKey]['spend'] = ($byBuilding[$bKey]['spend'] ?? 0) + $price;
                    $byBuilding[$bKey]['leads'][] = $t['lead_days'];
                    $s = $o['supplier_name'];
                    $bySupplier[$s]['completed'] = ($bySupplier[$s]['completed'] ?? 0) + 1;
                    $bySupplier[$s]['spend'] = ($bySupplier[$s]['spend'] ?? 0) + $price;
                    $bySupplier[$s]['leads'][] = $t['lead_days'];
                    if ($o['date_needed']) {
                        $bySupplier[$s]['need'] = ($bySupplier[$s]['need'] ?? 0) + 1;
                        if (substr($t['closed_at'], 0, 10) <= $o['date_needed']) $bySupplier[$s]['ontime'] = ($bySupplier[$s]['ontime'] ?? 0) + 1;
                    }
                } else {
                    $cancelled++;
                    $series[$bk]['cancelled']++;
                    $byBuilding[$bKey]['cancelled'] = ($byBuilding[$bKey]['cancelled'] ?? 0) + 1;
                }
            }
            if (!in_array($o['status'], self::CLOSED, true)) $byBuilding[$bKey]['open'] = ($byBuilding[$bKey]['open'] ?? 0) + 1;
        }

        // Backlog at every bucket end via an interval sweep.
        $ends = [];
        foreach ($buckets as $i => $b) $ends[$b] = min(($buckets[$i + 1] ?? null) ? $this->dayBefore($buckets[$i + 1]) . ' 23:59:59' : $end, $end);
        foreach ($ends as $b => $ts) $series[$b]['backlog'] = $this->backlogAt(min($ts, max($this->asOf, $start)));
        $backlogStart = $this->backlogAt($this->dayBefore($from) . ' 23:59:59');
        $backlogEnd = $this->backlogAt(min($end, $this->asOf));

        // Activity log analysis.
        $events = 0; $statusChanges = 0; $touched = []; $actors = []; $fields = []; $transitions = [];
        $heat = array_fill(0, 7, array_fill(0, 24, 0));
        $changes = [];
        $createdLogged = [];
        foreach ($this->history as $r) {
            if (!$in($r['changed_at'])) continue;
            if ($actor && $r['actor'] !== $actor) continue;
            $events++;
            $touched[$r['order_id']] = true;
            $series[$this->bucketOf($r['changed_at'], $gran)]['activity']++;
            $a = $r['actor'];
            $actors[$a]['events'] = ($actors[$a]['events'] ?? 0) + 1;
            $actors[$a]['orders'][$r['order_id']] = true;
            $fields[$r['field_name']] = ($fields[$r['field_name']] ?? 0) + 1;
            $isCreation = $r['field_name'] === 'status' && ($r['old_value'] === null || $r['old_value'] === '');
            if ($isCreation) {
                $actors[$a]['created'] = ($actors[$a]['created'] ?? 0) + 1;
                $createdLogged[$r['order_id']] = true;
            } elseif ($r['field_name'] === 'status') {
                $statusChanges++;
                $actors[$a]['status'] = ($actors[$a]['status'] ?? 0) + 1;
                if ($r['new_value'] === 'Delivered') $actors[$a]['delivered'] = ($actors[$a]['delivered'] ?? 0) + 1;
                if ($r['new_value'] === 'Ordered') $actors[$a]['ordered'] = ($actors[$a]['ordered'] ?? 0) + 1;
                $key = ($r['old_value'] ?: '∅') . ' → ' . ($r['new_value'] ?: '∅');
                $transitions[$key] = ($transitions[$key] ?? 0) + 1;
            } elseif (in_array($r['field_name'], ['unit_price', 'total_price'], true)) {
                $actors[$a]['prices'] = ($actors[$a]['prices'] ?? 0) + 1;
            }
            if (!(int)$r['date_only']) {
                $ts = strtotime($r['changed_at']);
                $heat[(int)date('N', $ts) - 1][(int)date('G', $ts)]++;
            }
            if ($detail) $changes[] = $this->changeRow($r);
        }
        if ($detail && !$actor) {
            foreach ($this->orders as $id => $o) {
                if (!isset($createdLogged[$id]) && $in($this->timeline[$id]['submitted'])) $changes[] = $this->changeRow(['order_id' => $id, 'field_name' => '_created', 'old_value' => null, 'new_value' => $o['status'], 'actor' => $o['requester_name'] ?: '—', 'changed_at' => $this->timeline[$id]['submitted'], 'date_only' => 0]);
            }
        }
        usort($changes, static fn($x, $y) => strcmp($y['at'], $x['at']) ?: $y['order_id'] <=> $x['order_id']);
        $limit = (int)($this->config['change_log_limit'] ?? 3000);
        $truncated = count($changes) > $limit;
        if ($truncated) $changes = array_slice($changes, 0, $limit);

        sort($leads);
        $kpis = [
            'incoming' => $incoming,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'backlog_start' => $backlogStart,
            'backlog_end' => $backlogEnd,
            'net_backlog' => $backlogEnd - $backlogStart,
            'throughput_ratio' => $incoming ? round($completed / $incoming, 3) : null,
            'lead_median' => $this->percentile($leads, 0.5),
            'lead_p90' => $this->percentile($leads, 0.9),
            'lead_avg' => $leads ? round(array_sum($leads) / count($leads), 1) : null,
            'spend' => round($spend, 2),
            'ordered_count' => $orderedCount,
            'ordered_value' => round($orderedValue, 2),
            'on_time_rate' => $withNeed ? round($onTime / $withNeed, 3) : null,
            'on_time_sample' => $withNeed,
            'events' => $events,
            'status_changes' => $statusChanges,
            'orders_touched' => count($touched),
            'days' => (int)round((strtotime($to) - strtotime($from)) / 86400) + 1,
        ];
        if (!$detail) return ['kpis' => $kpis, 'series' => array_values($series)];

        arsort($transitions);
        $tr = [];
        foreach (array_slice($transitions, 0, 16, true) as $k => $v) $tr[] = ['transition' => $k, 'count' => $v];
        $buildingNames = $this->pdo->query('SELECT building_code, name FROM dim_buildings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $bOut = [];
        foreach ($byBuilding as $code => $v) {
            $l = array_values(array_filter($v['leads'] ?? [], static fn($x) => $x !== null)); sort($l);
            $bOut[] = ['code' => $code, 'name' => $buildingNames[$code] ?? $code, 'incoming' => $v['incoming'] ?? 0, 'completed' => $v['completed'] ?? 0, 'cancelled' => $v['cancelled'] ?? 0, 'open' => $v['open'] ?? 0, 'spend' => round($v['spend'] ?? 0, 2), 'lead_median' => $this->percentile($l, 0.5)];
        }
        usort($bOut, static fn($a, $b) => $b['spend'] <=> $a['spend'] ?: $b['incoming'] <=> $a['incoming']);
        $sOut = [];
        foreach ($bySupplier as $name => $v) {
            $l = array_values(array_filter($v['leads'] ?? [], static fn($x) => $x !== null)); sort($l);
            $sOut[] = ['name' => $name, 'completed' => $v['completed'], 'spend' => round($v['spend'], 2), 'lead_median' => $this->percentile($l, 0.5), 'on_time_rate' => !empty($v['need']) ? round(($v['ontime'] ?? 0) / $v['need'], 3) : null];
        }
        usort($sOut, static fn($a, $b) => $b['spend'] <=> $a['spend'] ?: $b['completed'] <=> $a['completed']);
        $cOut = [];
        foreach ($byCategory as $name => $v) $cOut[] = ['name' => $name, 'incoming' => $v['incoming'], 'value' => round($v['value'], 2)];
        usort($cOut, static fn($a, $b) => $b['incoming'] <=> $a['incoming']);
        $rOut = [];
        foreach ($byRequester as $name => $v) $rOut[] = ['name' => $name, 'incoming' => $v['incoming'] ?? 0, 'open' => $v['open'] ?? 0];
        usort($rOut, static fn($a, $b) => $b['incoming'] <=> $a['incoming']);
        $aOut = [];
        foreach ($actors as $name => $v) $aOut[] = ['name' => $name, 'events' => $v['events'], 'created' => $v['created'] ?? 0, 'orders' => count($v['orders']), 'status' => $v['status'] ?? 0, 'ordered' => $v['ordered'] ?? 0, 'delivered' => $v['delivered'] ?? 0, 'prices' => $v['prices'] ?? 0];
        usort($aOut, static fn($a, $b) => $b['events'] <=> $a['events']);
        arsort($fields);
        $fOut = [];
        foreach ($fields as $k => $v) $fOut[] = ['field' => $k, 'label' => self::FIELD_LABELS[$k] ?? ucfirst(str_replace('_', ' ', $k)), 'count' => $v];
        $hist = ['0–3 d' => 0, '4–7 d' => 0, '8–14 d' => 0, '15–30 d' => 0, '31–60 d' => 0, '61–90 d' => 0, '90+ d' => 0];
        foreach ($leads as $d) {
            $k = $d <= 3 ? '0–3 d' : ($d <= 7 ? '4–7 d' : ($d <= 14 ? '8–14 d' : ($d <= 30 ? '15–30 d' : ($d <= 60 ? '31–60 d' : ($d <= 90 ? '61–90 d' : '90+ d')))));
            $hist[$k]++;
        }
        return [
            'kpis' => $kpis,
            'series' => array_values(array_map(static function ($s) { $s['spend'] = round($s['spend'], 2); return $s; }, $series)),
            'transitions' => $tr,
            'buildings' => $bOut,
            'suppliers' => array_slice($sOut, 0, 25),
            'categories' => array_slice($cOut, 0, 15),
            'requesters' => array_slice($rOut, 0, 15),
            'actors' => $aOut,
            'fields' => $fOut,
            'heatmap' => $heat,
            'lead_histogram' => array_map(static fn($k, $v) => ['bucket' => $k, 'count' => $v], array_keys($hist), $hist),
            'changes' => $changes,
            'changes_truncated' => $truncated,
        ];
    }

    private function changeRow(array $r): array
    {
        $o = $this->orders[$r['order_id']];
        $field = $r['field_name'];
        $old = $r['old_value'];
        $new = $r['new_value'];
        if ($field === 'supplier_id') {
            $old = $old !== null && $old !== '' ? ($this->suppliers[$old] ?? "#{$old}") : $old;
            $new = $new !== null && $new !== '' ? ($this->suppliers[$new] ?? "#{$new}") : $new;
        }
        $type = match (true) {
            $field === '_created', $field === 'status' && ($old === null || $old === '') => 'created',
            $field === 'status' && $new === 'Delivered' => 'completed',
            $field === 'status' && $new === 'Cancelled' => 'cancelled',
            $field === 'status' && in_array($old, self::CLOSED, true) => 'reopened',
            $field === 'status' => 'status',
            in_array($field, ['unit_price', 'total_price'], true) => 'price',
            $field === 'expected_delivery_date' => 'eta',
            $field === 'supplier_id' => 'supplier',
            default => 'other',
        };
        return [
            'at' => $r['changed_at'],
            'date_only' => (int)$r['date_only'],
            'order_id' => $r['order_id'],
            'type' => $type,
            'field' => ($field === '_created' || ($field === 'status' && ($old === null || $old === ''))) ? 'Created' : (self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field))),
            'from' => $old,
            'to' => $new,
            'actor' => $r['actor'],
            'item' => mb_substr($o['item_description'], 0, 140),
            'building' => $o['building_code'],
            'priority' => $o['priority'],
            'status' => $o['status'],
        ];
    }

    private function backlogAt(string $ts): int
    {
        $n = 0;
        foreach ($this->timeline as $t) {
            foreach ($t['intervals'] as [$s, $e]) {
                if ($s <= $ts && ($e === null || $e > $ts)) { $n++; break; }
            }
        }
        return $n;
    }

    private function statusAt(int $id, string $ts): ?string
    {
        $t = $this->timeline[$id];
        if ($t['submitted'] > $ts) return null;
        $status = null;
        foreach ($t['events'] as $e) {
            if ($e['changed_at'] <= $ts) $status = $e['new_value'];
            elseif ($status === null) { $status = $e['old_value'] ?: 'New'; break; }
            else break;
        }
        if ($status !== null) return $status;
        $current = $this->orders[$id]['status'];
        if (in_array($current, self::CLOSED, true) && $t['closed_at'] && $t['closed_at'] > $ts) return 'New';
        return $current;
    }

    private function statusMixAt(string $ts): array
    {
        $mix = [];
        foreach ($this->orders as $id => $o) {
            $s = $this->statusAt($id, $ts);
            if ($s !== null) $mix[$s] = ($mix[$s] ?? 0) + 1;
        }
        uksort($mix, fn($a, $b) => $this->rank($a) <=> $this->rank($b));
        return array_map(static fn($k, $v) => ['status' => $k, 'count' => $v], array_keys($mix), $mix);
    }

    private function aging(string $ts): array
    {
        $buckets = ['0–7 d', '8–14 d', '15–30 d', '31–60 d', '61–90 d', '90+ d'];
        $out = [];
        foreach ($this->orders as $id => $o) {
            $s = $this->statusAt($id, $ts);
            if ($s === null || in_array($s, self::CLOSED, true)) continue;
            $age = (strtotime($ts) - strtotime($this->timeline[$id]['submitted'])) / 86400;
            $b = $age <= 7 ? 0 : ($age <= 14 ? 1 : ($age <= 30 ? 2 : ($age <= 60 ? 3 : ($age <= 90 ? 4 : 5))));
            $out[$s] ??= array_fill(0, 6, 0);
            $out[$s][$b]++;
        }
        uksort($out, fn($a, $b) => $this->rank($a) <=> $this->rank($b));
        return ['buckets' => $buckets, 'rows' => array_map(static fn($k, $v) => ['status' => $k, 'counts' => $v], array_keys($out), $out)];
    }

    public function sla(): array
    {
        $thresholds = $this->config['sla_thresholds'] ?? self::DEFAULT_SLA;
        $rows = [];
        $now = strtotime($this->asOf);
        foreach ($this->orders as $id => $o) {
            $limit = $thresholds[$o['status']] ?? null;
            if ($limit === null) continue;
            $days = (int)floor(($now - strtotime($this->timeline[$id]['last_status_at'])) / 86400);
            if ($days <= $limit) continue;
            $rows[] = ['order_id' => $id, 'item' => mb_substr($o['item_description'], 0, 160), 'status' => $o['status'], 'building' => $o['building_code'], 'priority' => $o['priority'], 'days' => $days, 'threshold' => (int)$limit, 'requester' => $o['requester_name'], 'supplier' => $o['supplier_name'], 'value' => $o['total_price']];
        }
        usort($rows, static fn($a, $b) => ($b['priority'] === 'Urgent') <=> ($a['priority'] === 'Urgent') ?: $b['days'] <=> $a['days']);
        return $rows;
    }

    private function overdue(): array
    {
        $today = substr($this->asOf, 0, 10);
        $rows = [];
        foreach ($this->orders as $id => $o) {
            if (in_array($o['status'], self::CLOSED, true) || !$o['expected_delivery_date'] || $o['expected_delivery_date'] >= $today) continue;
            if (!in_array($o['status'], ['Ordered', 'In Transit', 'Partially Delivered', 'Approved'], true)) continue;
            $rows[] = ['order_id' => $id, 'item' => mb_substr($o['item_description'], 0, 160), 'status' => $o['status'], 'supplier' => $o['supplier_name'], 'building' => $o['building_code'], 'priority' => $o['priority'], 'expected' => $o['expected_delivery_date'], 'days_late' => (int)round((strtotime($today) - strtotime($o['expected_delivery_date'])) / 86400), 'value' => $o['total_price']];
        }
        usort($rows, static fn($a, $b) => $b['days_late'] <=> $a['days_late']);
        return $rows;
    }

    /** Filter option lists for the current snapshot (unfiltered). */
    public function options(int $importId): array
    {
        $q = fn(string $sql) => $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        $id = (int)$importId;
        return [
            'buildings' => $this->pdo->query("SELECT s.building_code code, COALESCE(b.name, s.building_code) name, COUNT(*) n FROM order_snapshots s LEFT JOIN dim_buildings b ON b.building_code=s.building_code WHERE s.import_id={$id} GROUP BY s.building_code, b.name ORDER BY n DESC")->fetchAll(),
            'priorities' => $q("SELECT DISTINCT priority FROM order_snapshots WHERE import_id={$id} ORDER BY FIELD(priority,'Urgent','High','Normal','Low')"),
            'statuses' => $q("SELECT DISTINCT status FROM order_snapshots WHERE import_id={$id}"),
            'suppliers' => $q("SELECT COALESCE(NULLIF(supplier_name,''),'—') n FROM order_snapshots WHERE import_id={$id} GROUP BY n ORDER BY COUNT(*) DESC LIMIT 300"),
            'requesters' => $q("SELECT requester_name FROM order_snapshots WHERE import_id={$id} AND requester_name<>'' GROUP BY requester_name ORDER BY COUNT(*) DESC"),
            'categories' => $q("SELECT COALESCE(NULLIF(category,''),'Uncategorised') c FROM order_snapshots WHERE import_id={$id} GROUP BY c ORDER BY COUNT(*) DESC"),
            'actors' => array_values(array_unique(array_map(fn($a) => ($this->config['actor_aliases'] ?? [])[$a] ?? $a, $q('SELECT changed_by FROM order_history_log GROUP BY changed_by ORDER BY COUNT(*) DESC')))),
            'first_order' => $this->pdo->query("SELECT DATE_FORMAT(MIN(submitted_at),'%Y-%m-%d') FROM order_snapshots WHERE import_id={$id}")->fetchColumn(),
        ];
    }

    public function compare(int $a, int $b): array
    {
        $diff = OrderImporter::diff($this->pdo, $a, $b);
        $rows = [];
        foreach ($diff['rows'] as $r) {
            if ($r['type'] === 'unchanged') continue;
            $o = $r['order'];
            $rows[] = ['order_id' => $r['order_id'], 'type' => $r['type'], 'old_status' => $r['old_status'], 'new_status' => $r['new_status'], 'changes' => $r['changes'], 'item' => mb_substr((string)$o['item_description'], 0, 140), 'building' => $o['building_code'], 'priority' => $o['priority'], 'supplier' => $o['supplier_name'], 'value' => $o['total_price'] === null ? null : (float)$o['total_price']];
        }
        $order = ['new' => 0, 'completed' => 1, 'cancelled' => 2, 'reopened' => 3, 'updated' => 4, 'removed' => 5];
        usort($rows, static fn($x, $y) => $order[$x['type']] <=> $order[$y['type']] ?: $y['order_id'] <=> $x['order_id']);
        $info = $this->pdo->prepare("SELECT id, DATE_FORMAT(snapshot_date,'%Y-%m-%d') snapshot_date, source_generated_at, order_count FROM imports WHERE id IN (?, ?)");
        $info->execute([$a, $b]);
        $meta = [];
        foreach ($info as $i) $meta[(int)$i['id']] = $i;
        return ['a' => $meta[$a] ?? null, 'b' => $meta[$b] ?? null, 'counts' => $diff['counts'], 'rows' => $rows];
    }

    public function order(int $id): ?array
    {
        $q = $this->pdo->prepare("SELECT s.*, DATE_FORMAT(i.snapshot_date,'%Y-%m-%d') snapshot_date FROM order_snapshots s JOIN imports i ON i.id=s.import_id WHERE s.order_id=? ORDER BY i.snapshot_date DESC, i.id DESC");
        $q->execute([$id]);
        $snaps = $q->fetchAll();
        if (!$snaps) return null;
        $current = $snaps[0];
        $h = $this->pdo->prepare('SELECT field_name, old_value, new_value, changed_by, changed_at, date_only FROM order_history_log WHERE order_id=? ORDER BY changed_at, id');
        $h->execute([$id]);
        $aliases = $this->config['actor_aliases'] ?? [];
        $history = [];
        foreach ($h as $r) {
            $f = $r['field_name'];
            $old = $r['old_value']; $new = $r['new_value'];
            if ($f === 'supplier_id') { $old = $old ? ($this->suppliers[$old] ?? "#{$old}") : $old; $new = $new ? ($this->suppliers[$new] ?? "#{$new}") : $new; }
            $history[] = ['at' => $r['changed_at'], 'date_only' => (int)$r['date_only'], 'field' => self::FIELD_LABELS[$f] ?? ucfirst(str_replace('_', ' ', $f)), 'raw_field' => $f, 'from' => $old, 'to' => $new, 'actor' => $aliases[$r['changed_by']] ?? $r['changed_by']];
        }
        $keep = ['order_id', 'submitted_at', 'updated_at', 'status', 'priority', 'building_code', 'category', 'item_description', 'part_number', 'quantity', 'unit_price', 'total_price', 'supplier_name', 'requester_name', 'date_needed', 'expected_delivery_date', 'po_number', 'snapshot_date'];
        return [
            'order' => array_intersect_key($current, array_flip($keep)),
            'history' => $history,
            'snapshots' => array_map(static fn($s) => ['date' => $s['snapshot_date'], 'status' => $s['status'], 'total_price' => $s['total_price'], 'expected_delivery_date' => $s['expected_delivery_date']], $snaps),
        ];
    }

    private function publicImport(array $i): array
    {
        return ['id' => (int)$i['id'], 'snapshot_date' => $i['snapshot_date'], 'source_generated_at' => $i['source_generated_at'], 'order_count' => (int)$i['order_count']];
    }

    private function granularity(string $from, string $to): string
    {
        $days = (strtotime($to) - strtotime($from)) / 86400;
        return $days <= 92 ? 'day' : ($days <= 550 ? 'week' : 'month');
    }

    private function bucketOf(string $ts, string $gran): string
    {
        $d = substr($ts, 0, 10);
        return match ($gran) {
            'day' => $d,
            'week' => date('Y-m-d', strtotime($d . ' -' . ((int)date('N', strtotime($d)) - 1) . ' days')),
            default => substr($d, 0, 7) . '-01',
        };
    }

    /** @return list<string> */
    private function buckets(string $from, string $to, string $gran): array
    {
        $out = [];
        $cur = $this->bucketOf($from, $gran);
        $guard = 0;
        while ($cur <= $to && $guard++ < 2000) {
            $out[] = $cur;
            $cur = date('Y-m-d', strtotime($cur . match ($gran) { 'day' => ' +1 day', 'week' => ' +7 days', default => ' +1 month' }));
        }
        return $out;
    }

    private function rank(string $status): int
    {
        $i = array_search($status, array_merge(self::OPEN_FLOW, self::CLOSED), true);
        return $i === false ? 99 : $i;
    }

    private function dayBefore(string $date): string
    {
        return date('Y-m-d', strtotime($date . ' -1 day'));
    }

    private function percentile(array $sorted, float $p): ?float
    {
        $n = count($sorted);
        if (!$n) return null;
        $idx = ($n - 1) * $p;
        $lo = (int)floor($idx);
        $hi = (int)ceil($idx);
        return round($sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($idx - $lo), 1);
    }
}

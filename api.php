<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_public_access(true);
require_once __DIR__ . '/app/Analytics.php';
require_once __DIR__ . '/app/Schema.php';

/**
 * Read-only JSON API for the public dashboard.
 *   ?action=meta                         snapshots + filter options
 *   ?action=dashboard&from=&to=[&cmp_from=&cmp_to=][&building=..]
 *   ?action=compare&a=IMPORT&b=IMPORT    snapshot-to-snapshot diff
 *   ?action=order&id=ORDER               timeline of one order
 */
$date = static function (string $key): ?string {
    $v = (string)($_GET[$key] ?? '');
    if ($v === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) json_out(['error' => "Invalid date: {$key}"], 400);
    return $v;
};
$list = static function (string $key): array {
    $v = $_GET[$key] ?? [];
    $v = is_array($v) ? $v : explode('|', (string)$v);
    return array_values(array_filter(array_map(static fn($x) => mb_substr(trim((string)$x), 0, 200), $v), static fn($x) => $x !== ''));
};

try {
    $pdo = db();
    PartPulseSchema::ensure($pdo);
    $an = new PartPulseAnalytics($pdo, $config);
    $action = (string)($_GET['action'] ?? 'meta');
    $imports = $an->orderImports();
    if (!$imports) {
        $legacy = (int)$pdo->query("SELECT COUNT(*) FROM imports WHERE source_type = 'xlsx'")->fetchColumn();
        json_out(['empty' => true, 'legacy_workbooks' => $legacy]);
    }

    switch ($action) {
        case 'meta':
            $snapshots = array_map(static fn($i) => [
                'id' => (int)$i['id'],
                'snapshot_date' => $i['snapshot_date'],
                'source_generated_at' => $i['source_generated_at'],
                'order_count' => (int)$i['order_count'],
                'summary' => $i['summary_json'] ? json_decode($i['summary_json'], true) : null,
            ], $imports);
            json_out([
                'empty' => false,
                'snapshots' => $snapshots,
                'options' => $an->options((int)$imports[0]['id']),
                'legacy_workbooks' => (int)$pdo->query("SELECT COUNT(*) FROM imports WHERE source_type = 'xlsx'")->fetchColumn(),
                'timezone' => $config['timezone'] ?? 'Europe/Sofia',
            ]);

        case 'dashboard':
            $from = $date('from');
            $to = $date('to');
            if (!$from || !$to) json_out(['error' => 'from and to are required'], 400);
            if ($from > $to) [$from, $to] = [$to, $from];
            $cmpFrom = $date('cmp_from');
            $cmpTo = $date('cmp_to');
            if ($cmpFrom && $cmpTo && $cmpFrom > $cmpTo) [$cmpFrom, $cmpTo] = [$cmpTo, $cmpFrom];
            $filters = [
                'building' => $list('building'), 'priority' => $list('priority'), 'supplier' => $list('supplier'),
                'requester' => $list('requester'), 'category' => $list('category'), 'status' => $list('status'),
                'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
            ];
            $snapshot = filter_input(INPUT_GET, 'snapshot', FILTER_VALIDATE_INT) ?: null;
            $an->load($snapshot, $filters);
            $actor = trim((string)($_GET['actor'] ?? '')) ?: null;
            json_out($an->dashboard($from, $to, $cmpFrom, $cmpTo, $actor));

        case 'compare':
            $a = filter_input(INPUT_GET, 'a', FILTER_VALIDATE_INT);
            $b = filter_input(INPUT_GET, 'b', FILTER_VALIDATE_INT);
            $ids = array_map(static fn($i) => (int)$i['id'], $imports);
            if (!$a || !$b || !in_array($a, $ids, true) || !in_array($b, $ids, true)) json_out(['error' => 'Choose two order snapshots.'], 400);
            $an->load($b, []);
            json_out($an->compare($a, $b));

        case 'order':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            if (!$id) json_out(['error' => 'Invalid order id'], 400);
            $an->load(null, []);
            $order = $an->order($id);
            if (!$order) json_out(['error' => 'Order not found'], 404);
            json_out($order);

        default:
            json_out(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('PartPulse API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['error' => 'Dashboard data is temporarily unavailable.'], 500);
}

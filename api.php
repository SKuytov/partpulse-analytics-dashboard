<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

try {
    $pdo = db();
    $snapshots = $pdo->query('SELECT id, DATE_FORMAT(snapshot_date, "%Y-%m-%d") snapshot_date, original_name, created_at FROM imports ORDER BY snapshot_date DESC, id DESC')->fetchAll();
    if (!$snapshots) json_out(['empty' => true, 'snapshots' => []]);

    $requested = filter_input(INPUT_GET, 'snapshot', FILTER_VALIDATE_INT);
    $ids = array_map('intval', array_column($snapshots, 'id'));
    foreach ($snapshots as &$snapshot) $snapshot['id'] = (int)$snapshot['id'];
    unset($snapshot);
    $importId = $requested && in_array($requested, $ids, true) ? $requested : (int)$snapshots[0]['id'];
    $position = array_search($importId, $ids, true);
    $previousId = ($position !== false && isset($ids[$position + 1])) ? (int)$ids[$position + 1] : null;
    $selected = $snapshots[$position === false ? 0 : $position];

    $fetch = static function (PDO $pdo, string $sql, array $args = []): array {
        $q = $pdo->prepare($sql); $q->execute($args); return $q->fetchAll();
    };
    $statuses = $fetch($pdo, 'SELECT status_name, order_count, percentage FROM status_metrics WHERE import_id = ? ORDER BY order_count DESC', [$importId]);
    $buildings = $fetch($pdo, 'SELECT building_code, building_name, costs_eur, order_count, percentage FROM building_costs WHERE import_id = ? ORDER BY costs_eur DESC', [$importId]);
    $sla = $fetch($pdo, 'SELECT order_no, article, status_name, building_code, priority, days_in_status, threshold_days, requester FROM sla_violations WHERE import_id = ? ORDER BY days_in_status DESC, order_no ASC', [$importId]);

    $totals = [
        'orders' => array_sum(array_map(static fn($r) => (int)$r['order_count'], $statuses)),
        'costs' => array_sum(array_map(static fn($r) => (float)$r['costs_eur'], $buildings)),
        'sla' => count($sla),
        'urgent_sla' => count(array_filter($sla, static fn($r) => strcasecmp($r['priority'], 'Urgent') === 0)),
    ];

    $prev = null;
    if ($previousId) {
        $prevRows = $fetch($pdo, 'SELECT status_name, order_count FROM status_metrics WHERE import_id = ?', [$previousId]);
        $prevBuildings = $fetch($pdo, 'SELECT costs_eur FROM building_costs WHERE import_id = ?', [$previousId]);
        $prevSla = (int)($fetch($pdo, 'SELECT COUNT(*) amount FROM sla_violations WHERE import_id = ?', [$previousId])[0]['amount'] ?? 0);
        $prev = [
            'orders' => array_sum(array_map(static fn($r) => (int)$r['order_count'], $prevRows)),
            'costs' => array_sum(array_map(static fn($r) => (float)$r['costs_eur'], $prevBuildings)),
            'sla' => $prevSla,
        ];
    }

    $trend = $fetch($pdo, 'SELECT i.id, DATE_FORMAT(i.snapshot_date, "%Y-%m-%d") snapshot_date, COALESCE(SUM(sm.order_count),0) orders, (SELECT COUNT(*) FROM sla_violations sv WHERE sv.import_id=i.id) sla, (SELECT COALESCE(SUM(costs_eur),0) FROM building_costs bc WHERE bc.import_id=i.id) costs FROM imports i LEFT JOIN status_metrics sm ON sm.import_id=i.id GROUP BY i.id, i.snapshot_date ORDER BY i.snapshot_date ASC, i.id ASC');
    $requesters = $fetch($pdo, 'SELECT requester, COUNT(*) amount FROM sla_violations WHERE import_id = ? GROUP BY requester ORDER BY amount DESC LIMIT 8', [$importId]);

    json_out([
        'empty' => false,
        'selected' => $selected,
        'snapshots' => $snapshots,
        'totals' => $totals,
        'previous' => $prev,
        'statuses' => $statuses,
        'buildings' => $buildings,
        'sla' => $sla,
        'trend' => $trend,
        'requesters' => $requesters,
    ]);
} catch (Throwable $e) {
    json_out(['error' => 'Dashboard data is temporarily unavailable.'], 500);
}

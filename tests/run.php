<?php
declare(strict_types=1);
/**
 * PartPulse test suite.  php tests/run.php
 *
 * Unit tests (parser + normalizer) always run. Database integration tests run
 * when PP_TEST_DSN is set, e.g.
 *   PP_TEST_DSN="mysql:host=127.0.0.1;dbname=pp_test" PP_TEST_USER=root PP_TEST_PASS= php tests/run.php
 * WARNING: the integration test drops and recreates all PartPulse tables in that database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/app/Normalizer.php';
require_once $root . '/app/OrderImporter.php';
require_once $root . '/app/Analytics.php';
require_once $root . '/app/Schema.php';
date_default_timezone_set('Europe/Sofia');

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}" . ($detail ? " — {$detail}" : '') . "\n"; }
}
$fixture = __DIR__ . '/fixtures/sample_partpulse.sql';

echo "SQL reader\n";
$reader = new SqlDumpReader();
$rows = [];
$reader->read($fixture, ['orders', 'order_history', 'users', 'suppliers', 'user_login_log'], function ($t, $r) use (&$rows) { $rows[$t][] = $r; });
check('reads all orders', count($rows['orders'] ?? []) === 5);
check('semicolon and fake INSERT inside string kept', str_contains($rows['orders'][0]['item_description'], 'INSERT INTO `x` VALUES (1);'));
check('escaped quote and newline decoded', $rows['orders'][1]['item_description'] === "Belt \"XPZ\" 1250\nsecond line");
check('NULL decoded as null', $rows['orders'][2]['unit_price'] === null);
check('escaped single quote', $rows['suppliers'][1]['name'] === "O'Brien \"Tools\"");
check('INSERT without column list uses CREATE TABLE', ($rows['user_login_log'][0]['ip'] ?? '') === '10.0.0.1');
check('generation time parsed', $reader->generatedAt() === '2026-10-16 05:30:00');
check('dump time zone parsed', $reader->dumpTimezone() === '+00:00');
check('column types recorded', $reader->columnType('orders', 'submission_date') === 'timestamp');

echo "Normalizer\n";
$n = new PartPulseNormalizer('UTC', 'Europe/Sofia');
$ds = $n->fromSqlFile($fixture, null, 'partpulse_orders.sql');
$t = $ds['tables'];
$byId = array_column($t['orders.csv'], null, 'order_id');
check('template orders skipped', !isset($byId['104']) && count($byId) === 4);
check('UTC converted to Sofia time', $byId['101']['submitted_at'] === '2026-10-01 09:00:00');
check('late-evening UTC moves to next local day', $byId['103']['submitted_at'] === '2026-10-11 01:30:00');
check('requester name from user directory', $byId['101']['requester_name'] === 'Иван Тестов');
check('supplier name resolved from id', $byId['101']['supplier_name'] === 'Bearing Co; Ltd');
check('multi-line text flattened', $byId['102']['item_description'] === 'Belt "XPZ" 1250 second line');
check('snapshot date from export header', $ds['manifest']['snapshot_date'] === '2026-10-16');
$all = json_encode($ds, JSON_UNESCAPED_UNICODE);
foreach (['$2y$10$', 'admin@example.com', '+359000000', 'private note', 'Secret Contact', 'sales@example.com', 'private phone', '10.0.0.1'] as $secret) {
    check("sensitive value removed: {$secret}", !str_contains($all, $secret));
}
$hist = $t['order_history.csv'];
$eta = array_values(array_filter($hist, fn($h) => $h['field_name'] === 'expected_delivery_date'))[0];
check('JavaScript date string normalized', $eta['new_value'] === '2026-10-06', $eta['new_value']);
check('audit log merged with user name', (bool)array_filter($hist, fn($h) => $h['source'] === 'audit' && $h['changed_by'] === 'Test Admin' && $h['new_value'] === 'Cancelled'));
check('free-text history redacted', (bool)array_filter($hist, fn($h) => $h['field_name'] === 'supplier_notes' && $h['new_value'] === '[text]'));
$tmpZip = sys_get_temp_dir() . '/pp-test-' . bin2hex(random_bytes(3)) . '.zip';
$n->writeZip($ds, $tmpZip);
$back = PartPulseNormalizer::readZip($tmpZip);
check('ZIP round-trip preserves rows', count($back['tables']['orders.csv']) === 4 && $back['tables']['orders.csv'][1]['item_description'] === $t['orders.csv'][1]['item_description']);
$z = new ZipArchive(); $z->open($tmpZip); $z->addFromString('orders.csv', "order_id\n1\n"); $z->close();
try { PartPulseNormalizer::readZip($tmpZip); check('tampered package rejected', false); } catch (RuntimeException $e) { check('tampered package rejected', str_contains($e->getMessage(), 'Checksum')); }
@unlink($tmpZip);

$dsn = getenv('PP_TEST_DSN');
if ($dsn) {
    echo "Database integration\n";
    $pdo = new PDO($dsn, getenv('PP_TEST_USER') ?: 'root', getenv('PP_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['order_changes', 'order_events', 'order_snapshots', 'order_history_log', 'order_assignments', 'dim_suppliers', 'dim_users', 'dim_buildings', 'dim_cost_centers', 'sla_violations', 'status_metrics', 'building_costs', 'imports'] as $tb) $pdo->exec("DROP TABLE IF EXISTS {$tb}");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    PartPulseSchema::ensure($pdo);
    $config = ['upload_dir' => sys_get_temp_dir(), 'timezone' => 'Europe/Sofia', 'actor_aliases' => ['admin' => 'Test Admin']];
    $imp = new OrderImporter($pdo, $config);
    $r1 = $imp->import($ds, ['original_name' => 'a.sql', 'stored_name' => 'a.zip', 'file_hash' => str_repeat('1', 64), 'file_size' => 1, 'source_type' => 'sql']);
    check('baseline import', $r1['orders'] === 4 && $r1['previous_import_id'] === null);
    try { $imp->import($ds, ['original_name' => 'a.sql', 'stored_name' => 'a.zip', 'file_hash' => str_repeat('1', 64), 'file_size' => 1, 'source_type' => 'sql']); check('duplicate file rejected', false); }
    catch (RuntimeException $e) { check('duplicate file rejected', str_contains($e->getMessage(), 'already imported')); }

    $ds2 = $ds;
    $ds2['manifest']['snapshot_date'] = '2026-10-17';
    $ds2['manifest']['source_generated_at'] = '2026-10-17 08:00:00';
    foreach ($ds2['tables']['orders.csv'] as $i => &$o) {
        if ($o['order_id'] === '102') { $o['status'] = 'Delivered'; $o['updated_at'] = '2026-10-16 10:00:00'; }
        if ($o['order_id'] === '103') { $o['total_price'] = '15.00'; }
        if ($o['order_id'] === '101') { $o['status'] = 'Ordered'; }
    }
    unset($o);
    $ds2['tables']['orders.csv'] = array_values(array_filter($ds2['tables']['orders.csv'], fn($o) => $o['order_id'] !== '105'));
    $ds2['tables']['orders.csv'][] = array_merge($ds['tables']['orders.csv'][0], ['order_id' => '106', 'status' => 'New', 'submitted_at' => '2026-10-16 09:30:00']);
    $ds2['tables']['order_history.csv'][] = ['source' => 'history', 'source_id' => '50', 'order_id' => '102', 'field_name' => 'status', 'old_value' => 'Ordered', 'new_value' => 'Delivered', 'changed_by' => 'admin', 'changed_at' => '2026-10-16 10:00:00', 'date_only' => '0'];
    $r2 = $imp->import($ds2, ['original_name' => 'b.sql', 'stored_name' => 'b.zip', 'file_hash' => str_repeat('2', 64), 'file_size' => 1, 'source_type' => 'sql']);
    $e = $r2['events'];
    check('history merged without duplicates', $r2['history_added'] === 1, (string)$r2['history_added']);
    check('diff: new', $e['new'] === 1);
    check('diff: completed', $e['completed'] === 1);
    check('diff: reopened', $e['reopened'] === 1);
    check('diff: updated', $e['updated'] === 1);
    check('diff: removed (never counted as completed)', $e['removed'] === 1);
    check('events persisted', (int)$pdo->query('SELECT COUNT(*) FROM order_events WHERE import_id=' . $r2['import_id'])->fetchColumn() === 5);

    $an = new PartPulseAnalytics($pdo, $config);
    $an->load(null, []);
    $d = $an->dashboard('2026-10-16', '2026-10-16', '2026-10-15', '2026-10-15', null);
    check('period: completed on 16 Oct', $d['kpis']['completed'] === 1, json_encode($d['kpis']));
    check('period: new on 16 Oct', $d['kpis']['incoming'] === 1);
    check('actor alias applied', ($d['actors'][0]['name'] ?? '') === 'Test Admin');
    $d1 = (new PartPulseAnalytics($pdo, $config));
    $d1->load($r1['import_id'], []);
    $w = $d1->dashboard('2026-10-01', '2026-10-15', null, null, null);
    check('history: delivered on 6 Oct counted', $w['kpis']['completed'] === 1);
    check('history: audit-log cancellation counted', $w['kpis']['cancelled'] === 1);
    check('lead time from change log (5 days)', abs(($w['kpis']['lead_median'] ?? 0) - 5.08) < 0.1, (string)$w['kpis']['lead_median']);
    check('backlog at end', $w['kpis']['backlog_end'] === 2);
    $f = new PartPulseAnalytics($pdo, $config); $f->load($r1['import_id'], ['building' => ['WW']]);
    check('building filter', $f->dashboard('2026-09-01', '2026-10-15', null, null, null)['kpis']['incoming'] === 2);
    $cmp = $an->compare($r1['import_id'], $r2['import_id']);
    check('compare endpoint', $cmp['counts']['new'] === 1 && count($cmp['rows']) === 5);
    $ord = $an->order(101);
    check('order timeline', $ord && count($ord['history']) === 4 && count($ord['snapshots']) === 2);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

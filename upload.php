<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
@set_time_limit(300);
@ini_set('memory_limit', '512M');
try {
    check_csrf();
    $file = $_FILES['workbook'] ?? [];
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    require_once __DIR__ . '/app/Schema.php';
    PartPulseSchema::ensure(db());

    if ($ext === 'xlsx') {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!is_file($autoload)) throw new RuntimeException('Dependencies are missing. Run Composer locally and upload the vendor directory.');
        require $autoload;
        require __DIR__ . '/app/importer.php';
        $id = (new PartPulseImporter(db(), $config))->import($file);
        flash('success', "Workbook imported as summary snapshot #{$id}. It is visible under Workbook snapshots.");
    } elseif (in_array($ext, ['sql', 'zip'], true)) {
        require_once __DIR__ . '/app/OrderImporter.php';
        $override = trim((string)($_POST['snapshot_date'] ?? ''));
        $r = (new OrderImporter(db(), $config))->importUpload($file, $override !== '' ? $override : null);
        $e = $r['events'];
        if ($r['previous_import_id']) {
            $msg = sprintf('Snapshot %s imported: %d orders, %d new history entries. Since the previous snapshot: %d new, %d completed, %d cancelled, %d reopened, %d updated, %d removed.',
                $r['snapshot_date'], $r['orders'], $r['history_added'], $e['new'], $e['completed'], $e['cancelled'], $e['reopened'], $e['updated'], $e['removed']);
        } else {
            $msg = sprintf('Baseline snapshot %s imported: %d orders and %d history entries. The complete history since the first order is now available on the dashboard.', $r['snapshot_date'], $r['orders'], $r['history_added']);
        }
        flash('success', $msg);
    } else {
        throw new RuntimeException('Upload a PartPulse .sql export, a normalized .zip package, or a daily .xlsx workbook.');
    }
} catch (Throwable $e) {
    error_log('PartPulse import failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $safe = ($e instanceof RuntimeException && !$e instanceof PDOException) ? $e->getMessage() : 'Import failed. Verify the file format and server configuration.';
    flash('error', $safe);
}
header('Location: admin.php');

<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
try {
    check_csrf();
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Dependencies are missing. Run Composer locally and upload the vendor directory.');
    require $autoload;
    require __DIR__ . '/app/importer.php';
    $id = (new PartPulseImporter(db(), $config))->import($_FILES['workbook'] ?? []);
    flash('success', "Workbook imported successfully as snapshot #{$id}. The public dashboard is live.");
} catch (Throwable $e) {
    error_log('PartPulse import failed: ' . $e->getMessage());
    $safe = $e instanceof RuntimeException ? $e->getMessage() : 'Import failed. Verify the workbook format and server configuration.';
    flash('error', $safe);
}
header('Location: admin.php');

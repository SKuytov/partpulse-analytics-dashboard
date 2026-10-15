<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
try {
    check_csrf();
    $id = filter_input(INPUT_POST, 'import_id', FILTER_VALIDATE_INT);
    if (!$id) throw new RuntimeException('Invalid snapshot.');
    $pdo = db();
    $q = $pdo->prepare('SELECT id, snapshot_date, stored_name FROM imports WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('Snapshot not found.');
    $pdo->beginTransaction();
    // Snapshot rows, events and changes are removed by ON DELETE CASCADE.
    // The permanent history log keeps entries; only their first-seen reference is cleared.
    $pdo->prepare('UPDATE order_history_log SET first_import_id = NULL WHERE first_import_id = ?')->execute([$id]);
    $pdo->prepare('UPDATE order_assignments SET first_import_id = NULL WHERE first_import_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM imports WHERE id = ?')->execute([$id]);
    $pdo->commit();
    $path = rtrim((string)$config['upload_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename((string)$row['stored_name']);
    if (is_file($path)) @unlink($path);
    flash('success', "Snapshot {$row['snapshot_date']} (#{$id}) was deleted.");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('PartPulse delete failed: ' . $e->getMessage());
    flash('error', ($e instanceof RuntimeException && !$e instanceof PDOException) ? $e->getMessage() : 'The snapshot could not be deleted.');
}
header('Location: admin.php');

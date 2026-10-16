<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Schema.php';
require_admin();
$flash = take_flash();
$imports = [];
$upgraded = false;
$limits = [
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'zip' => class_exists(ZipArchive::class),
];
try {
    $upgraded = PartPulseSchema::ensure(db());
    $imports = db()->query("SELECT id, DATE_FORMAT(snapshot_date,'%Y-%m-%d') snapshot_date, original_name, source_type, source_generated_at, order_count, history_added, summary_json, file_size, created_at FROM imports ORDER BY snapshot_date DESC, id DESC LIMIT 60")->fetchAll();
} catch (Throwable $e) {
    error_log('PartPulse admin: ' . $e->getMessage());
    $flash = ['type' => 'error', 'message' => 'Database unavailable. Check config.php and import sql/schema.sql in phpMyAdmin.'];
}
$typeLabel = ['sql' => 'SQL export', 'normalized' => 'Normalized ZIP', 'xlsx' => 'Workbook'];
$publicUrl = 'index.php' . (($config['public_access_key'] ?? '') !== '' ? '?key=' . rawurlencode((string)$config['public_access_key']) : '');
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Upload center · PartPulse</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Roboto+Mono:wght@500;600&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/app.css"></head><body><a class="skip-link" href="#main">Skip to content</a>
<header class="topbar"><a class="brand brand-dark" href="index.php"><svg viewBox="0 0 36 36" aria-hidden="true"><path d="M4 20h6l3-8 5 15 4-11 3 4h7"/><circle cx="29" cy="20" r="3"/></svg><span>PartPulse</span></a><nav class="top-actions" aria-label="Admin navigation"><a class="button ghost" href="<?= e($publicUrl) ?>">COO dashboard</a><a class="button ghost" href="workbook.php">Workbooks</a><a class="button ghost" href="logout.php">Sign out</a></nav></header>
<main id="main" class="admin-shell">
  <section class="admin-intro"><p class="eyebrow">Administrator</p><h1>Upload center</h1><p>Each morning: export the PartPulse Orders database from phpMyAdmin (or run the PartPulse Normalizer) and upload the file here. Every upload becomes a dated snapshot and the dashboard compares it automatically.</p></section>
  <?php if ($upgraded): ?><p class="alert success" role="status">Database upgraded to the order-level history schema.</p><?php endif; ?>
  <?php if ($flash): ?><p class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <section class="upload-panel">
    <form action="upload.php" method="post" enctype="multipart/form-data" class="upload-form" id="uploadForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="dropzone" for="workbook"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5h14v-5"/></svg><strong>Choose today’s file</strong><span id="fileName">.sql export · normalized .zip · .xlsx workbook — max <?= (int)($config['max_upload_bytes'] / 1024 / 1024) ?> MB</span><input id="workbook" name="workbook" type="file" accept=".sql,.zip,.xlsx" required></label>
      <div class="upload-side">
        <label class="snapshot-label">Snapshot date <span class="muted">(optional)</span><input type="date" name="snapshot_date" id="snapshotDate" max="<?= e(date('Y-m-d')) ?>"></label>
        <button class="button primary" id="uploadButton" type="submit">Import and publish</button>
      </div>
    </form>
    <ul class="upload-notes">
      <li><strong>.sql</strong> — full PartPulse Orders export. Parsed without executing anything; only reporting fields are kept, a sanitized ZIP is archived and the raw dump is discarded.<?= ($config['allow_sql_upload'] ?? true) ? '' : ' <em>(disabled in config.php)</em>' ?></li>
      <li><strong>.zip</strong> — package produced by <code>tools/PartPulse-Normalizer.bat</code> on your PC. Recommended when you prefer that the raw dump never leaves your computer.</li>
      <li><strong>.xlsx</strong> — the previous daily analysis workbook (summary view only).</li>
      <li>Leave the date empty to use the date from the export header or filename. Server limits: upload <?= e((string)$limits['upload_max_filesize']) ?>, post <?= e((string)$limits['post_max_size']) ?>, ZIP support <?= $limits['zip'] ? 'available' : '<strong>missing</strong>' ?>.</li>
    </ul>
  </section>
  <section class="history">
    <div class="section-head"><div><p class="eyebrow">Archive</p><h2>Snapshots</h2></div><span class="muted"><?= count($imports) ?> shown</span></div>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th>Orders</th><th>Change vs previous</th><th>Source file</th><th>Uploaded</th><th></th></tr></thead><tbody>
    <?php foreach ($imports as $row): $s = $row['summary_json'] ? json_decode($row['summary_json'], true) : null; $ev = $s['events'] ?? null; ?>
      <tr>
        <td><?= e($row['snapshot_date']) ?></td>
        <td><span class="type-chip type-<?= e($row['source_type']) ?>"><?= e($typeLabel[$row['source_type']] ?? $row['source_type']) ?></span></td>
        <td><?= $row['order_count'] !== null ? number_format((int)$row['order_count']) : '—' ?></td>
        <td class="change-cell"><?php if ($ev && !empty($s['previous_import_id'])): ?><span class="ev ev-new">+<?= (int)$ev['new'] ?> new</span> <span class="ev ev-completed"><?= (int)$ev['completed'] ?> completed</span> <span class="ev ev-cancelled"><?= (int)$ev['cancelled'] ?> cancelled</span> <span class="ev ev-updated"><?= (int)$ev['updated'] ?> updated</span><?php if ((int)$ev['reopened']): ?> <span class="ev ev-reopened"><?= (int)$ev['reopened'] ?> reopened</span><?php endif; ?><?php if ((int)$ev['removed']): ?> <span class="ev ev-removed"><?= (int)$ev['removed'] ?> removed</span><?php endif; ?><?php elseif ($ev): ?><span class="muted">Baseline · <?= number_format((int)($s['history_added'] ?? 0)) ?> history entries</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td class="file-cell" title="<?= e($row['original_name']) ?>"><?= e($row['original_name']) ?></td>
        <td><?= e($row['created_at']) ?></td>
        <td><form method="post" action="delete.php" onsubmit="return confirm('Delete snapshot <?= e($row['snapshot_date']) ?>? This cannot be undone.')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="import_id" value="<?= (int)$row['id'] ?>"><button class="link-button" type="submit">Delete</button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$imports): ?><tr><td colspan="7">No uploads yet. Start with today’s PartPulse SQL export — it contains the complete history since the first order.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</main>
<script>
const f=document.querySelector('#workbook'),n=document.querySelector('#fileName'),b=document.querySelector('#uploadButton'),d=document.querySelector('#snapshotDate');
f.addEventListener('change',()=>{const file=f.files[0];n.textContent=file?`${file.name} · ${(file.size/1048576).toFixed(1)} MB`:'.sql · .zip · .xlsx';const m=file&&file.name.match(/(20\d{2})[-_.](\d{2})[-_.](\d{2})/);if(m&&!d.value)d.placeholder=`${m[1]}-${m[2]}-${m[3]}`;});
document.querySelector('#uploadForm').addEventListener('submit',()=>{b.disabled=true;b.textContent='Importing — this can take a minute…'});
</script></body></html>

<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
if (is_admin()) { header('Location: admin.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        $user = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        if (hash_equals((string)$config['admin']['username'], $user) && password_verify($pass, (string)$config['admin']['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['partpulse_admin'] = true;
            $_SESSION['admin_user'] = $user;
            header('Location: admin.php'); exit;
        }
        usleep(500000);
        $error = 'Invalid username or password.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin sign in · PartPulse</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Roboto+Mono:wght@500;600&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/style.css"></head><body class="auth-body"><a class="skip-link" href="#main">Skip to content</a><main id="main" class="auth-shell"><section class="auth-card"><a class="brand brand-dark" href="index.php" aria-label="PartPulse public dashboard"><svg viewBox="0 0 36 36" role="img" aria-label="PartPulse logo"><path d="M4 20h6l3-8 5 15 4-11 3 4h7"/><circle cx="29" cy="20" r="3"/></svg><span>PartPulse</span></a><div><p class="eyebrow">Secure workspace</p><h1>Morning upload</h1><p class="muted">Sign in, upload the latest workbook, and the public dashboard updates immediately.</p></div><?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif; ?><form method="post" class="form-stack"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Username<input name="username" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="button primary" type="submit">Sign in</button></form><a class="text-link" href="index.php">Open public dashboard →</a></section></main></body></html>

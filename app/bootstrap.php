<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('PartPulse is not configured. Copy config.example.php to config.php and enter the database/admin settings.');
}
$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Europe/Sofia');
ini_set('display_errors', '0');
error_reporting(E_ALL);
header_remove('X-Powered-By');
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}

if (PHP_SAPI !== 'cli') {
    session_name($config['session_name'] ?? 'PARTPULSE_ADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $d = $config['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], $d['port'] ?? 3306, $d['name'], $d['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if (!$token || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        throw new RuntimeException('Invalid security token. Refresh the page and try again.');
    }
}

function is_admin(): bool
{
    return !empty($_SESSION['partpulse_admin']);
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: login.php');
        exit;
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $value;
}

/**
 * Optional protection for the "public" COO link. When `public_access_key` is
 * empty (default) the dashboard is open, exactly as in version 1. When it is
 * set, the first visit must use index.php?key=THE_KEY; a long-lived cookie is
 * then stored so the COO bookmark keeps working without a password.
 */
function require_public_access(bool $json = false): void
{
    global $config;
    $key = (string)($config['public_access_key'] ?? '');
    if ($key === '' || is_admin()) return;
    $cookie = 'PP_VIEW';
    $expected = hash_hmac('sha256', 'partpulse-view', $key);
    $given = (string)($_GET['key'] ?? '');
    if ($given !== '' && hash_equals($key, $given)) {
        setcookie($cookie, $expected, [
            'expires' => time() + 86400 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        return;
    }
    if (hash_equals($expected, (string)($_COOKIE[$cookie] ?? ''))) return;
    if ($json) json_out(['error' => 'Access key required.'], 403);
    http_response_code(403);
    exit('<!DOCTYPE html><meta charset="utf-8"><title>PartPulse</title><body style="font-family:sans-serif;padding:3rem">This dashboard requires the private viewing link.</body>');
}

function app_config(): array
{
    global $config;
    return $config;
}

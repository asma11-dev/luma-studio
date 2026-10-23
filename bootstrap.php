<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
$config = is_file($configFile) ? require $configFile : require dirname(__DIR__) . '/config.example.php';
date_default_timezone_set($config['timezone'] ?? 'UTC');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $c = $config['db'];
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['name'], $c['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(int $cents): string { return '$' . number_format($cents / 100, 0); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'; }
function check_csrf(): void
{
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(419); exit('This page expired. Go back and try again.');
    }
}
function redirect(string $page, array $params = []): never
{
    header('Location: /?' . http_build_query(['page' => $page] + $params)); exit;
}
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = [$message, $type]; }
function take_flash(): ?array { $v = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $v; }
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $q = db()->prepare('SELECT id,name,email,role FROM users WHERE id=? AND is_active=1');
    $q->execute([$_SESSION['user_id']]);
    $user = $q->fetch();
    if (!$user) unset($_SESSION['user_id']);
    return $user ?: null;
}
function require_login(): array { $u = current_user(); if (!$u) redirect('login'); return $u; }
function require_staff(bool $ownerOnly = false): array
{
    $u = require_login();
    if (($ownerOnly && $u['role'] !== 'owner') || (!$ownerOnly && !in_array($u['role'], ['owner','stylist'], true))) {
        http_response_code(403); exit('You do not have access to this page.');
    }
    return $u;
}
function throttle(string $action, int $limit = 8, int $window = 900): bool
{
    $now = time();
    $key = hash('sha256', $action . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
    $record = $_SESSION['throttle'][$key] ?? ['since' => $now, 'count' => 0];
    if ($now - $record['since'] > $window) $record = ['since' => $now, 'count' => 0];
    $record['count']++;
    $_SESSION['throttle'][$key] = $record;
    return $record['count'] <= $limit;
}
function send_email(string $to, string $subject, string $body): bool
{
    global $config;
    $fromName = $config['mail_name'] ?? 'Luma Studio';
    $from = $config['mail_from'] ?? 'appointments@example.com';
    $headers = "From: " . mb_encode_mimeheader($fromName, 'UTF-8') . " <{$from}>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    return mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers);
}
function app_url(string $query): string
{
    global $config;
    return rtrim($config['app_url'] ?? '', '/') . '/?' . $query;
}
function date_label(string $datetime): string { return date('D, M j · g:i a', strtotime($datetime)); }

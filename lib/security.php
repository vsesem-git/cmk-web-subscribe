<?php
/**
 * HTTP and application security: headers, sessions, CSRF, access checks and rate limits.
 */
declare(strict_types=1);

/** Harden HTML/JSON responses. */
function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header("Content-Security-Policy: default-src 'self'; "
        . "base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; "
        . "img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; "
        . "connect-src 'self'; frame-src 'self' blob:");
    if (function_exists('request_is_https') && request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** Start a hardened session and rotate stale, idle, or user-agent-mismatched IDs. */
function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $now = time();
    $ua = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $started = !empty($_SESSION['__started']);
    $uaMismatch = $started && !hash_equals((string)($_SESSION['__ua'] ?? ''), $ua);
    $idleExpired = $started && isset($_SESSION['__last_activity'])
        && ($now - (int)$_SESSION['__last_activity']) > 8 * 60 * 60;

    if (!$started || $uaMismatch || $idleExpired) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['__started'] = true;
        $_SESSION['__ua'] = $ua;
        $_SESSION['__csrf_created'] = $now;
    }
    $_SESSION['__last_activity'] = $now;
}

/** Return the session CSRF token, rotating it after one day. */
function csrf_token(): string
{
    $created = (int)($_SESSION['__csrf_created'] ?? 0);
    if (empty($_SESSION['csrf']) || $created === 0 || (time() - $created) > 86400) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['__csrf_created'] = time();
    }
    return (string)$_SESSION['csrf'];
}

/** Check a CSRF token using constant-time comparison. */
function csrf_check($token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && is_string($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

/** Require the route to use one of its explicitly allowed HTTP methods. */
function require_method(string $actual, array $allowed): void
{
    if (!in_array(strtoupper($actual), $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_response(['ok' => false, 'error' => 'Метод запроса не поддерживается.'], 405);
    }
}

/** Escape a value for an HTML text/attribute context. */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Fetch the latest account record so disabling/deleting/changing a password revokes sessions. */
function current_user(): ?array
{
    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser)) return null;

    $user = $sessionUser;
    if (function_exists('users_find')) {
        $login = (string)($sessionUser['login'] ?? '');
        $fresh = $login !== '' ? users_find($login) : null;
        if (!$fresh || (($fresh['active'] ?? true) === false)) {
            unset($_SESSION['user'], $_SESSION['__auth_hash']);
            return null;
        }
        $sessionHash = (string)($_SESSION['__auth_hash'] ?? ($sessionUser['password_hash'] ?? ''));
        $freshHash = (string)($fresh['password_hash'] ?? '');
        if ($sessionHash === '' || $freshHash === '' || !hash_equals($sessionHash, $freshHash)) {
            unset($_SESSION['user'], $_SESSION['__auth_hash']);
            return null;
        }
        $_SESSION['user'] = $fresh;
        $user = $fresh;
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && ($user['role'] ?? '') === 'admin';
}

/** Require login; otherwise return a JSON 401 response. */
function require_login(): void
{
    if (!is_logged_in()) {
        json_response(['ok' => false, 'error' => 'Требуется вход'], 401);
    }
}

/** Require administrator role; otherwise return a JSON 403 response. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        json_response(['ok' => false, 'error' => 'Недостаточно прав'], 403);
    }
}

/** Return a JSON response and terminate the request. */
function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json === false ? '{"ok":false,"error":"Ошибка формирования ответа."}' : $json;
    exit;
}

/** Read a bounded JSON request body. */
function read_json_body(): array
{
    $maxBytes = 8 * 1024 * 1024;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
        json_response(['ok' => false, 'error' => 'Тело запроса слишком большое.'], 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        json_response(['ok' => false, 'error' => 'Тело запроса слишком большое.'], 413);
    }
    if (trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        json_response(['ok' => false, 'error' => 'Некорректный JSON.'], 400);
    }
    return $data;
}

/** Lowercase UTF-8 text, including Cyrillic, even without mbstring. */
function mb_lower(string $s): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ';
    $lower = 'abcdefghijklmnopqrstuvwxyzабвгдеёжзийклмнопрстуфхцчшщъыьэюя';
    $uu = preg_split('//u', $upper, -1, PREG_SPLIT_NO_EMPTY);
    $ll = preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY);
    return str_replace($uu, $ll, $s);
}

/** Validate an email address. */
function is_email(string $email): bool
{
    return strlen($email) <= 254 && (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Cross-request rate limit shared by all sessions from one IP.
 * Identifiers are HMACed (the file never contains clear-text IPs or user names).
 * Storage errors fail closed instead of silently disabling the protection.
 */
function rate_limit(string $key, int $maxAttempts, int $windowSec): bool
{
    if ($maxAttempts < 1 || $windowSec < 1 || !defined('DATA_DIR') || !defined('APP_SECRET')) return false;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $id = hash_hmac('sha256', $key . "\0" . $ip, APP_SECRET);
    $path = DATA_DIR . '/.rate_limits.json';
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0700, true) && !is_dir(DATA_DIR)) return false;

    $fp = @fopen($path, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if (is_resource($fp)) fclose($fp);
        return false;
    }
    @chmod($path, 0600);
    rewind($fp);
    $raw = stream_get_contents($fp);
    $state = json_decode($raw ?: '{}', true);
    if (!is_array($state)) $state = [];

    $now = time();
    foreach ($state as $bucketId => $bucket) {
        if (!is_array($bucket) || (int)($bucket['reset'] ?? 0) <= $now) {
            unset($state[$bucketId]);
        }
    }
    if (count($state) > 10000) {
        uasort($state, function ($a, $b) {
            return (int)($a['reset'] ?? 0) <=> (int)($b['reset'] ?? 0);
        });
        $state = array_slice($state, -5000, null, true);
    }

    $bucket = $state[$id] ?? ['count' => 0, 'reset' => $now + $windowSec];
    if ((int)($bucket['reset'] ?? 0) <= $now) {
        $bucket = ['count' => 0, 'reset' => $now + $windowSec];
    }
    $bucket['count'] = (int)($bucket['count'] ?? 0) + 1;
    $state[$id] = $bucket;

    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    $written = $json !== false;
    if ($written) {
        rewind($fp);
        $written = ftruncate($fp, 0) && fwrite($fp, $json) === strlen($json) && fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);

    $allowed = $written && $bucket['count'] <= $maxAttempts;
    if (!$allowed && $written && $bucket['count'] > $maxAttempts) {
        header('Retry-After: ' . max(1, (int)$bucket['reset'] - $now));
    }
    return $allowed;
}

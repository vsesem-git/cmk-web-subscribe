<?php
/**
 * Функции безопасности: заголовки, CSRF, сессия, доступ, санитайзинг.
 */
declare(strict_types=1);

/** Безопасные HTTP-заголовки (защита от XSS, clickjacking, MIME-sniffing и т.п.). */
function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // CSP: разрешаем только собственные ресурсы + inline-стили (используются в шаблоне).
    header("Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'; "
        . "script-src 'self'; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    // HSTS имеет смысл только под HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** Старт сессии + защита от фиксации. */
function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['__started'])) {
        session_regenerate_id(true);
        $_SESSION['__started'] = true;
        $_SESSION['__ua'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }
    // Привязка к User-Agent — простейшая защита от угона сессии
    if (($_SESSION['__ua'] ?? '') !== hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
        session_unset();
        session_destroy();
        session_start();
    }
}

/** Токен CSRF. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Проверка CSRF-токена (строгое сравнение). */
function csrf_check(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

/** Экранирование для вывода в HTML. */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Текущий пользователь из сессии (или null). */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool { return current_user() !== null; }
function is_admin(): bool { $u = current_user(); return $u && ($u['role'] ?? '') === 'admin'; }

/** Требовать вход; иначе 401 JSON. */
function require_login(): void
{
    if (!is_logged_in()) {
        json_response(['ok' => false, 'error' => 'Требуется вход'], 401);
    }
}

/** Требовать роль администратора; иначе 403 JSON. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        json_response(['ok' => false, 'error' => 'Недостаточно прав'], 403);
    }
}

/** Универсальный JSON-ответ и выход. */
function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Прочитать JSON тела запроса. */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

/** Нижний регистр с поддержкой кириллицы, работает и без расширения mbstring. */
function mb_lower(string $s): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    // Fallback: латиница + русская кириллица
    $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ';
    $lower = 'abcdefghijklmnopqrstuvwxyzабвгдеёжзийклмнопрстуфхцчшщъыьэюя';
    // работаем по многобайтовым символам
    $uu = preg_split('//u', $upper, -1, PREG_SPLIT_NO_EMPTY);
    $ll = preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY);
    return str_replace($uu, $ll, $s);
}

/** Валидация email. */
function is_email(string $e): bool
{
    return (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}

/** Простейший rate-limit по ключу (защита от перебора). Возвращает true, если разрешено. */
function rate_limit(string $key, int $maxAttempts, int $windowSec): bool
{
    $k = 'rl_' . md5($key);
    $now = time();
    $bucket = $_SESSION[$k] ?? ['count' => 0, 'reset' => $now + $windowSec];
    if ($now > $bucket['reset']) {
        $bucket = ['count' => 0, 'reset' => $now + $windowSec];
    }
    $bucket['count']++;
    $_SESSION[$k] = $bucket;
    return $bucket['count'] <= $maxAttempts;
}

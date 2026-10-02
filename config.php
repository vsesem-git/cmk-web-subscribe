<?php
/**
 * Центр межрегиональных коммуникаций (ЦМК) — Вебинары для подписчиков
 * Основная конфигурация.
 *
 * ВАЖНО: этот файл содержит секреты. Он лежит вне web-доступа по возможности,
 * а .htaccess дополнительно запрещает прямой доступ к *.php в /lib и /data.
 */

declare(strict_types=1);

// --- Пути ---
define('BASE_DIR', __DIR__);
define('DATA_DIR', BASE_DIR . '/data');
define('LOG_DIR', DATA_DIR . '/logs');
define('USERS_FILE', DATA_DIR . '/users.json');
define('WEBINARS_FILE', DATA_DIR . '/webinars.json');
define('SETTINGS_FILE', DATA_DIR . '/settings.json');

// --- Файлы логов (JSON Lines) ---
define('CMK_LOG_LOGIN',  LOG_DIR . '/login.jsonl');   // входы пользователей
define('CMK_LOG_VIEWS',  LOG_DIR . '/views.jsonl');   // просмотры вебинаров
define('CMK_LOG_MAIL',   LOG_DIR . '/mail.jsonl');    // отправленные письма

/**
 * Базовый URL приложения — вычисляется автоматически, чтобы проект
 * работал на любом домене, поддомене и в любой подпапке БЕЗ правок кода.
 * Пример: https://site.ru/webinars/  ->  base_path = "/webinars/"
 */
function app_base_path(): string
{
    // директория скрипта относительно корня сайта
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    // если запрос пришёл в /api/index.php — поднимаемся на уровень выше
    $dir = str_replace('\\', '/', dirname($script));
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }
    $dir = rtrim($dir, '/') . '/';
    return $dir === '//' ? '/' : $dir;
}
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // Proxy headers are trusted only when explicitly enabled in the server environment.
    if (getenv('CMK_TRUST_PROXY') === '1') {
        $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $forwarded === 'https';
    }
    return false;
}

function app_base_url(): string
{
    $scheme = request_is_https() ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    // Never reflect an arbitrary Host header into an absolute URL.
    if (!preg_match('/\\A(?:\\[[0-9a-f:.]+\\]|[a-z0-9.-]+)(?::[0-9]{1,5})?\\z/i', $host)) {
        $host = 'localhost';
    }
    return $scheme . '://' . $host . app_base_path();
}

/**
 * Use a deployment-provided secret when available, otherwise create a private,
 * persistent secret file on first start. This keeps secrets out of source control.
 */
function app_secret(): string
{
    $configured = getenv('CMK_APP_SECRET');
    if ($configured !== false && $configured !== '') {
        if (strlen($configured) < 64) {
            throw new RuntimeException('CMK_APP_SECRET must contain at least 64 characters.');
        }
        return $configured;
    }

    $path = DATA_DIR . '/.app_secret';
    if (is_file($path)) {
        $secret = trim((string)@file_get_contents($path));
        if (strlen($secret) >= 64) return $secret;
        throw new RuntimeException('The application secret file is invalid.');
    }
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0700, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('The data directory is not writable; configure CMK_APP_SECRET.');
    }

    $secret = bin2hex(random_bytes(32));
    $handle = @fopen($path, 'x');
    if ($handle !== false) {
        @chmod($path, 0600);
        $written = fwrite($handle, $secret);
        fclose($handle);
        if ($written === strlen($secret)) return $secret;
        @unlink($path);
        throw new RuntimeException('Could not persist the application secret.');
    }

    // Another worker may have created the file at the same time.
    if (is_file($path)) {
        $secret = trim((string)@file_get_contents($path));
        if (strlen($secret) >= 64) return $secret;
    }
    throw new RuntimeException('Could not initialize the application secret.');
}

/**
 * Ссылка на статический ресурс с автоверсией по времени изменения файла.
 * ?v=<mtime> меняется ТОЛЬКО когда файл реально обновлён — браузер держит
 * файл в кэше, пока версия не изменилась (никакой лишней нагрузки на сервер).
 * filemtime кэшируется в пределах запроса (static), поэтому вызывается один раз на файл.
 */
function asset(string $relPath): string
{
    static $cache = [];
    $rel = ltrim($relPath, '/');
    if (!isset($cache[$rel])) {
        $file = BASE_DIR . '/' . $rel;
        $v = @filemtime($file);            // 0/ false, если файла нет
        $cache[$rel] = $rel . ($v ? '?v=' . $v : '');
    }
    return $cache[$rel];
}


// Секрет создаётся автоматически в закрытом файле data/.app_secret либо задаётся через окружение.
define('APP_SECRET', app_secret());

// --- Параметры кабинета вебинара (для ссылок в письмах) ---
$cabinetBase = getenv('CMK_CABINET_BASE');
$cabinetBase = ($cabinetBase !== false && $cabinetBase !== '') ? trim($cabinetBase) : 'https://edu.vsesem.ru/webinar/';
$cabinetParts = @parse_url($cabinetBase);
if (!is_array($cabinetParts) || strtolower((string)($cabinetParts['scheme'] ?? '')) !== 'https'
    || empty($cabinetParts['host']) || isset($cabinetParts['user']) || isset($cabinetParts['pass'])
    || preg_match('/[\\r\\n\\x00]/', $cabinetBase)) {
    throw new RuntimeException('CMK_CABINET_BASE must be a valid HTTPS URL.');
}
define('CABINET_BASE', rtrim($cabinetBase, '/') . '/');

// --- Кэш внешнего источника вебинаров (сек). 0 = без кэша (каждый раз свежее). ---
define('WEBINARS_CACHE_TTL', 30);

// --- Бренд ---
define('BRAND_SHORT', 'ЦМК');
define('BRAND_FULL',  'Центр межрегиональных коммуникаций');
define('MAIL_FROM_NAME', 'ЦМК-Подписка');

// --- Часовой пояс ---
date_default_timezone_set('Europe/Moscow');

// --- Флаги безопасности cookie сессии ---
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => app_base_path(),
    'httponly' => true,
    'secure'   => request_is_https(),
    'samesite' => 'Strict',
]);

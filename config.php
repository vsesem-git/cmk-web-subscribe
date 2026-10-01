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
function app_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . app_base_path();
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


// --- Секрет для подписи сессий/CSRF. ОБЯЗАТЕЛЬНО замените на свой! ---
define('APP_SECRET', 'ЗАМЕНИТЕ_ЭТУ_СТРОКУ_НА_СЛУЧАЙНУЮ_64_СИМВОЛА_ABCdef1234567890');

// --- Параметры кабинета вебинара (для ссылок в письмах) ---
define('CABINET_BASE', 'https://edu.vsesem.ru/webinar/');

// --- Кэш внешнего источника вебинаров (сек). 0 = без кэша (каждый раз свежее). ---
define('WEBINARS_CACHE_TTL', 30);

// --- Бренд ---
define('BRAND_SHORT', 'ЦМК');
define('BRAND_FULL',  'Центр межрегиональных коммуникаций');
define('MAIL_FROM_NAME', 'ЦМК-Подписка');

// --- Часовой пояс ---
date_default_timezone_set('Europe/Moscow');

// --- Флаги безопасности cookie сессии ---
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => $secureCookie,
    'samesite' => 'Strict',
]);

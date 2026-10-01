<?php
/**
 * Хранилище на JSON-файлах с блокировками.
 * Пароли пользователей хранятся в виде хешей (password_hash).
 */
declare(strict_types=1);

/** Атомарное чтение JSON. */
function store_read(string $file, array $default = []): array
{
    if (!is_file($file)) return $default;
    $fp = fopen($file, 'rb');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($raw ?: 'null', true);
    return is_array($data) ? $data : $default;
}

/** Атомарная запись JSON (через временный файл + rename). */
function store_write(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0640);
    return rename($tmp, $file);
}

/* ---------- Пользователи ---------- */
function users_all(): array
{
    $d = store_read(USERS_FILE, ['users' => []]);
    return $d['users'] ?? [];
}
function users_save(array $users): bool
{
    return store_write(USERS_FILE, ['users' => array_values($users)]);
}
function users_find(string $login): ?array
{
    foreach (users_all() as $u) {
        if (($u['login'] ?? '') === $login) return $u;
    }
    return null;
}

/** Убрать из массива пользователя приватные поля перед отдачей клиенту. */
function user_public(array $u): array
{
    unset($u['password_hash']);
    return $u;
}

/* ---------- Настройки (SMTP и пр.) ---------- */
function settings_defaults(): array
{
    return [
        'smtp' => [
            'host' => '', 'port' => 465, 'secure' => 'ssl',
            'user' => '', 'pass' => '', 'from_email' => '', 'from_name' => MAIL_FROM_NAME,
        ],
        'source_url' => '',
        'show_past'  => true,
        // Тумблеры функций (админ включает/выключает)
        'features' => [
            'log_login'   => true,   // логировать входы
            'log_views'   => true,   // логировать просмотры вебинаров
            'log_mail'    => true,   // логировать отправку писем
            'btn_access'  => true,   // кнопка «Отправить доступ на email»
            'btn_invite'  => true,   // кнопка «Отправить приглашение»
            'my_views'    => true,   // раздел «Мои просмотры» для пользователей
            'timer'       => true,   // таймер обратного отсчёта
            'viewed_badge'=> true,   // значок «просмотрен»
            'expiry_warn' => true,   // предупреждение о скором окончании подписки
        ],
        // Авто-очистка логов
        'log_retention' => [
            'enabled'   => true,
            'days'      => 180,      // хранить N дней
            'max_lines' => 20000,    // и не более N строк на файл
        ],
        // Размер шрифта по столбцам (px) — задаёт админ, применяется для всех
        'col_fonts' => [
            'date'    => 15,
            'speaker' => 15,
            'timer'   => 14,
            'title'   => 16,
            'price'   => 15,
        ],
        // Ширина столбцов (px), 0 = авто
        'col_widths' => [
            'date'    => 118,
            'speaker' => 140,
            'timer'   => 110,
            'title'   => 0,     // 0 = растягивать
            'price'   => 130,
            'action'  => 240,
        ],
        // Вид таблицы
        'view' => [
            'density'    => 'normal',   // compact | normal | comfortable
            'theme'      => 'light',    // light | dark
            'start_hour' => 10,         // час начала вебинаров по умолчанию (для таймера)
            'mode'       => 'table',    // table | cards — режим отображения
            'page_size'  => 0,          // 0 = без пагинации; иначе N на страницу («показать ещё»)
            'date_format'=> 'D MMMM YYYY', // шаблон даты (D, DD, M, MM, MMMM, YYYY, YY)
        ],
        // Конфиг колонок как данные: порядок + видимость + подпись.
        // key — служебный ключ (совпадает с ключами col_fonts/col_widths и рендерерами).
        'columns' => [
            ['key' => 'date',    'label' => 'Дата',              'visible' => true],
            ['key' => 'speaker', 'label' => 'Лектор',            'visible' => true],
            ['key' => 'timer',   'label' => 'До начала',         'visible' => true],
            ['key' => 'title',   'label' => 'Тема',              'visible' => true],
            ['key' => 'price',   'label' => 'Цена без подписки', 'visible' => true],
            ['key' => 'action',  'label' => 'Действия',          'visible' => true],
        ],
        // Сопоставление полей JSON -> логические поля (гибкий формат внешних данных)
        'field_map' => [
            'date'             => 'date',
            'speaker'          => 'speaker',
            'title'            => 'title',
            'price'            => 'price',
            'direction'        => 'direction',
            'link_participant' => 'link_participant',
            'time'             => 'time',
            'id'               => 'id',
        ],
        // Переопределение цветов/иконок категорий (пусто = брать из кода)
        // формат: { "zhkh": {"color":"#1E6FA8","label":"ЖКХ"} , ... }
        'cat_overrides' => new stdClass(),
    ];
}
function settings_get(): array
{
    $s = store_read(SETTINGS_FILE, []);
    // мягко сливаем с дефолтами (чтобы новые ключи появлялись у старых конфигов)
    return array_replace_recursive(settings_defaults(), is_array($s) ? $s : []);
}
function settings_save(array $s): bool
{
    return store_write(SETTINGS_FILE, $s);
}

/** Быстрая проверка, включена ли функция. */
function feature_on(string $key): bool
{
    $s = settings_get();
    return !empty($s['features'][$key]);
}

/* ---------- Вебинары ---------- */
/**
 * Список вебинаров. Если в настройках задан source_url — берём оттуда
 * (с коротким серверным кэшем), иначе — из локального data/webinars.json.
 */
function webinars_all(): array
{
    $s = settings_get();
    $url = trim((string)($s['source_url'] ?? ''));
    if ($url !== '') {
        $data = webinars_from_source($url);
        if ($data !== null) return $data;
        // при недоступности источника — падаем на локальный файл
    }
    $d = store_read(WEBINARS_FILE, []);
    if (isset($d['webinars'])) return $d['webinars'];
    return $d;
}

/**
 * Загрузка вебинаров из внешнего URL с кэшем.
 * Кэш живёт WEBINARS_CACHE_TTL секунд (по умолчанию 60) — чтобы обновление
 * подтягивалось при перезагрузке страницы, но не било по источнику на каждый запрос.
 * Вернёт массив или null (если не удалось).
 */
function webinars_from_source(string $url): ?array
{
    $cacheFile = DATA_DIR . '/.webinars_cache.json';
    $ttl = defined('WEBINARS_CACHE_TTL') ? (int)WEBINARS_CACHE_TTL : 60;
    if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    $raw = fetch_remote($url);
    if ($raw === null) {
        // источник недоступен — используем устаревший кэш, если есть
        if (is_file($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }
        return null;
    }
    $d = json_decode($raw, true);
    if (!is_array($d)) return null;
    $list = isset($d['webinars']) ? $d['webinars'] : $d;
    if (!is_array($list)) return null;
    @file_put_contents($cacheFile, json_encode($list, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $list;
}

/** Скачать содержимое URL (cURL или file_get_contents). null при ошибке. */
function fetch_remote(string $url): ?string
{
    if (!preg_match('~^https?://~i', $url)) return null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => BRAND_SHORT . '-webinars/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 400) return (string)$body;
        return null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : (string)$body;
}

/** Принудительно сбросить кэш внешнего источника. */
function webinars_cache_clear(): void
{
    $cacheFile = DATA_DIR . '/.webinars_cache.json';
    if (is_file($cacheFile)) @unlink($cacheFile);
}
function webinar_find($id): ?array
{
    foreach (webinars_all() as $w) {
        if ((string)($w['id'] ?? '') === (string)$id) return $w;
    }
    return null;
}

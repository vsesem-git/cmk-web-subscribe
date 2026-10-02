<?php
/**
 * JSON-file storage, runtime settings, webinar normalization and access policy.
 */
declare(strict_types=1);

/** Read a JSON document under a shared lock. */
function store_read(string $file, array $default = []): array
{
    if (!is_file($file)) return $default;
    $fp = @fopen($file, 'rb');
    if (!$fp) return $default;
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return $default;
    }
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($raw ?: 'null', true);
    return is_array($data) ? $data : $default;
}

/** Write JSON atomically with private permissions. */
function store_write(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
    $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    @chmod($file, 0600);
    return true;
}

/* ---------- Users ---------- */
function users_all(): array
{
    $data = store_read(USERS_FILE, ['users' => []]);
    return isset($data['users']) && is_array($data['users']) ? $data['users'] : [];
}

function users_save(array $users): bool
{
    return store_write(USERS_FILE, ['users' => array_values($users)]);
}

function users_find(string $login): ?array
{
    $needle = function_exists('mb_lower') ? mb_lower(trim($login)) : strtolower(trim($login));
    foreach (users_all() as $user) {
        if (!is_array($user)) continue;
        $candidate = (string)($user['login'] ?? '');
        $candidate = function_exists('mb_lower') ? mb_lower($candidate) : strtolower($candidate);
        if ($candidate === $needle) return $user;
    }
    return null;
}

/** Return only the account fields required by the UI; never leak hashes or imported extras. */
function user_public(array $user): array
{
    $allowed = array_flip(['login', 'role', 'org', 'email', 'categories', 'expires', 'active', 'last_login', 'last_ip', 'login_count']);
    return array_intersect_key($user, $allowed);
}

/* ---------- Settings ---------- */
function settings_defaults(): array
{
    return [
        'smtp' => [
            'host' => '', 'port' => 465, 'secure' => 'ssl',
            'user' => '', 'pass' => '', 'from_email' => '', 'from_name' => MAIL_FROM_NAME,
        ],
        'source_url' => '',
        'show_past' => true,
        'features' => [
            'log_login' => true,
            'log_views' => true,
            'log_mail' => true,
            'btn_access' => true,
            'btn_invite' => true,
            'my_views' => true,
            'timer' => true,
            'viewed_badge' => true,
            'expiry_warn' => true,
        ],
        'log_retention' => ['enabled' => true, 'days' => 180, 'max_lines' => 20000],
        'col_fonts' => ['date' => 15, 'speaker' => 15, 'timer' => 14, 'title' => 16, 'price' => 15],
        'col_widths' => ['date' => 118, 'speaker' => 140, 'timer' => 110, 'title' => 0, 'price' => 130, 'action' => 240],
        'view' => [
            'density' => 'normal', 'theme' => 'light', 'start_hour' => 10,
            'mode' => 'table', 'page_size' => 0, 'date_format' => 'D MMMM YYYY',
        ],
        'columns' => [
            ['key' => 'date', 'label' => 'Дата', 'visible' => true],
            ['key' => 'speaker', 'label' => 'Лектор', 'visible' => true],
            ['key' => 'timer', 'label' => 'До начала', 'visible' => true],
            ['key' => 'title', 'label' => 'Тема', 'visible' => true],
            ['key' => 'price', 'label' => 'Цена без подписки', 'visible' => true],
            ['key' => 'action', 'label' => 'Действия', 'visible' => true],
        ],
        'field_map' => [
            'date' => 'date', 'speaker' => 'speaker', 'title' => 'title', 'price' => 'price',
            'direction' => 'direction', 'link_participant' => 'link_participant',
            'time' => 'time', 'id' => 'id',
        ],
        'cat_overrides' => new stdClass(),
        'presets' => [],
    ];
}

function settings_get(): array
{
    $settings = store_read(SETTINGS_FILE, []);
    return array_replace_recursive(settings_defaults(), $settings);
}

function settings_save(array $settings): bool
{
    return store_write(SETTINGS_FILE, $settings);
}

function feature_on(string $key): bool
{
    $settings = settings_get();
    return !empty($settings['features'][$key]);
}

/* ---------- Remote source validation / fetching ---------- */
function is_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/** Reject browser/URL-parser legacy IPv4 spellings such as 127.1 or 0x7f000001. */
function host_has_numeric_final_label(string $host): bool
{
    $host = trim($host, '[]');
    return preg_match('/(?:^|\\.)(?:0x[0-9a-f]*|[0-9]+)$/i', $host) === 1;
}

/** Reject private/local hosts commonly used for SSRF and unsafe protocols. */
function remote_source_url_valid(string $url): bool
{
    if (strlen($url) > 2048 || preg_match('/[\\x00-\\x20\\\\]/', $url)) return false;
    $parts = @parse_url(trim($url));
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') return false;
    if (empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
    if (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535)) return false;
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === '' || $host === 'localhost' || preg_match('/(?:\.localhost|\.local|\.internal|\.test)$/i', $host)) return false;
    $ipHost = trim($host, '[]');
    if (filter_var($ipHost, FILTER_VALIDATE_IP)) return is_public_ip($ipHost);
    if (host_has_numeric_final_label($ipHost) || strpos($ipHost, '.') === false
        || filter_var($ipHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) return false;

    $ips = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) $ips[] = $record['ip'];
                if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
            }
        }
    }
    if (!$ips && function_exists('gethostbynamel')) {
        $resolved = @gethostbynamel($host);
        if (is_array($resolved)) $ips = $resolved;
    }
    if (!$ips) {
        $resolved = @gethostbyname($host);
        if ($resolved && $resolved !== $host) $ips[] = $resolved;
    }
    if (!$ips) return false;
    foreach (array_unique($ips) as $ip) {
        if (!is_public_ip((string)$ip)) return false;
    }
    return true;
}

/** Allow only real HTTPS links with no credentials or local-network target. */
function safe_web_url($value): string
{
    if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\\]/', $value)) return '';
    $url = trim($value);
    if (!filter_var($url, FILTER_VALIDATE_URL)) return '';
    $parts = @parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return '';
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === 'localhost' || preg_match('/(?:\.localhost|\.local|\.internal|\.test|\.lan)$/i', $host)) return '';
    $ipHost = trim($host, '[]');
    if (filter_var($ipHost, FILTER_VALIDATE_IP)) {
        if (!is_public_ip($ipHost)) return '';
    } elseif (host_has_numeric_final_label($ipHost) || strpos($ipHost, '.') === false
        || filter_var($ipHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        return '';
    }
    return $url;
}

function webinars_all(): array
{
    $settings = settings_get();
    $url = trim((string)($settings['source_url'] ?? ''));
    if ($url !== '') {
        $data = webinars_from_source($url);
        if ($data !== null) return $data;
    }
    $local = store_read(WEBINARS_FILE, []);
    $list = isset($local['webinars']) ? $local['webinars'] : $local;
    return is_array($list) ? $list : [];
}

function webinars_from_source(string $url): ?array
{
    if (!remote_source_url_valid($url)) return null;
    $cacheFile = DATA_DIR . '/.webinars_cache_' . hash('sha256', $url) . '.json';
    $ttl = defined('WEBINARS_CACHE_TTL') ? max(0, (int)WEBINARS_CACHE_TTL) : 30;
    if ($ttl > 0 && is_file($cacheFile) && (time() - (int)@filemtime($cacheFile) < $ttl)) {
        $cached = store_read($cacheFile, []);
        if (is_array($cached)) return $cached;
    }

    $raw = fetch_remote($url);
    if ($raw === null) {
        if (is_file($cacheFile)) return store_read($cacheFile, []);
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    $list = isset($data['webinars']) ? $data['webinars'] : $data;
    if (!is_array($list) || count($list) > 1000) return null;
    if (!store_write($cacheFile, array_values($list))) return $list;
    return array_values($list);
}

function fetch_remote(string $url): ?string
{
    if (!remote_source_url_valid($url)) return null;
    $maxBytes = 4 * 1024 * 1024;

    if (function_exists('curl_init')) {
        $body = '';
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => BRAND_SHORT . '-webinars/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $ok !== false && $code >= 200 && $code < 300 ? $body : null;
    }

    $context = stream_context_create([
        'http' => ['timeout' => 8, 'follow_location' => 0, 'max_redirects' => 0, 'header' => "Accept: application/json\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
    ]);
    $handle = @fopen($url, 'rb', false, $context);
    if (!$handle) return null;
    $body = stream_get_contents($handle, $maxBytes + 1);
    fclose($handle);
    if (!is_string($body) || strlen($body) > $maxBytes) return null;
    return $body;
}

function webinars_cache_clear(): void
{
    foreach (glob(DATA_DIR . '/.webinars_cache*.json') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
}

/* ---------- Webinar normalization and authorization ---------- */
function webinar_text($value, int $maxChars): string
{
    if (!is_scalar($value)) return '';
    $text = (string)$value;
    $text = preg_replace('/[\\x00-\\x1F\\x7F]/u', ' ', $text);
    if (function_exists('mb_substr')) return mb_substr($text, 0, $maxChars, 'UTF-8');
    if (preg_match('/^.{0,' . $maxChars . '}/us', $text, $match)) return $match[0];
    return substr($text, 0, $maxChars);
}

function webinar_field(array $source, string $canonical, array $fieldMap)
{
    $mapped = $fieldMap[$canonical] ?? $canonical;
    if (is_string($mapped) && $mapped !== '' && array_key_exists($mapped, $source)) return $source[$mapped];
    return $source[$canonical] ?? null;
}

function webinar_normalize(array $source, ?array $fieldMap = null): ?array
{
    if ($fieldMap === null) $fieldMap = settings_get()['field_map'] ?? [];
    $id = webinar_field($source, 'id', $fieldMap);
    if (!is_scalar($id)) return null;
    $id = webinar_text($id, 120);
    if ($id === '') return null;

    $date = webinar_text(webinar_field($source, 'date', $fieldMap), 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    $parsedDate = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) return null;

    $priceValue = webinar_field($source, 'price', $fieldMap);
    $price = is_numeric($priceValue) ? (float)$priceValue : 0;
    if (!is_finite($price) || $price < 0) $price = 0;
    $time = webinar_text(webinar_field($source, 'time', $fieldMap), 5);
    if ($time !== '' && !preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $time)) $time = '';

    return [
        'id' => $id,
        'date' => $date,
        'time' => $time,
        'speaker' => webinar_text(webinar_field($source, 'speaker', $fieldMap), 240),
        'title' => webinar_text(webinar_field($source, 'title', $fieldMap), 1200),
        'price' => min($price, 100000000),
        'direction' => webinar_text(webinar_field($source, 'direction', $fieldMap), 80),
        'link_participant' => safe_web_url(webinar_field($source, 'link_participant', $fieldMap)),
        'link_recording' => safe_web_url($source['link_recording'] ?? ''),
    ];
}

function webinar_category_key(array $webinar): string
{
    $direction = mb_lower(trim((string)($webinar['direction'] ?? '')));
    $keys = ['zhkh', 'zdrav', 'electro', 'eco', 'build', 'land', 'goz', 'gas'];
    if (in_array($direction, $keys, true)) return $direction;
    $aliases = [
        'жкх' => 'zhkh',
        'здрав' => 'zdrav', 'здравоохранение' => 'zdrav', 'медицина' => 'zdrav',
        'электро' => 'electro', 'электроэнергетика' => 'electro', 'энергетика' => 'electro', 'энерго' => 'electro', 'energy' => 'electro',
        'экология' => 'eco', 'эко' => 'eco',
        'строительство' => 'build', 'строй' => 'build',
        'земля' => 'land',
        'гоз' => 'goz', 'госрегулирование' => 'goz', 'госзакупки' => 'goz', 'гособоронзаказ' => 'goz',
        'газ' => 'gas',
    ];
    return $aliases[$direction] ?? 'other';
}

function user_subscription_valid(array $user): bool
{
    if (($user['role'] ?? '') === 'admin') return true;
    $expires = trim((string)($user['expires'] ?? ''));
    if ($expires === '') return true;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) return false;
    $date = DateTime::createFromFormat('!Y-m-d', $expires);
    if (!$date || $date->format('Y-m-d') !== $expires) return false;
    return $expires >= date('Y-m-d');
}

function user_can_access_webinar(array $user, array $webinar): bool
{
    if (($user['active'] ?? true) === false || !user_subscription_valid($user)) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    $categories = isset($user['categories']) && is_array($user['categories']) ? $user['categories'] : [];
    if (in_array('all', $categories, true)) return true;
    return in_array(webinar_category_key($webinar), $categories, true);
}

function webinars_visible_to_user(?array $user): array
{
    if (!$user || (($user['active'] ?? true) === false) || !user_subscription_valid($user)) return [];
    $visible = [];
    $seen = [];
    $fieldMap = settings_get()['field_map'] ?? [];
    foreach (webinars_all() as $source) {
        if (!is_array($source)) continue;
        $webinar = webinar_normalize($source, $fieldMap);
        if (!$webinar || isset($seen[$webinar['id']]) || !user_can_access_webinar($user, $webinar)) continue;
        $seen[$webinar['id']] = true;
        $visible[] = $webinar;
        if (count($visible) >= 1000) break;
    }
    return $visible;
}

function webinar_find_for_user($id, ?array $user): ?array
{
    if (!is_scalar($id) || !$user) return null;
    $needle = (string)$id;
    if ($needle === '' || strlen($needle) > 120) return null;
    $fieldMap = settings_get()['field_map'] ?? [];
    foreach (webinars_all() as $source) {
        if (!is_array($source)) continue;
        $webinar = webinar_normalize($source, $fieldMap);
        if ($webinar && $webinar['id'] === $needle && user_can_access_webinar($user, $webinar)) return $webinar;
    }
    return null;
}

/** Backwards-compatible safe lookup; callers must still require a session. */
function webinar_find($id): ?array
{
    if (!function_exists('current_user')) return null;
    return webinar_find_for_user($id, current_user());
}

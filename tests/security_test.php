<?php
/** Lightweight PHP regression tests; run with: php tests/security_test.php */
declare(strict_types=1);

$testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cmk-security-tests-' . bin2hex(random_bytes(6));
if (!mkdir($testDir, 0700, true)) throw new RuntimeException('Could not create isolated test data directory.');

define('BASE_DIR', dirname(__DIR__));
define('DATA_DIR', $testDir);
define('LOG_DIR', DATA_DIR . '/logs');
define('USERS_FILE', DATA_DIR . '/users.json');
define('WEBINARS_FILE', DATA_DIR . '/webinars.json');
define('SETTINGS_FILE', DATA_DIR . '/settings.json');
define('CMK_LOG_LOGIN', LOG_DIR . '/login.jsonl');
define('CMK_LOG_VIEWS', LOG_DIR . '/views.jsonl');
define('CMK_LOG_MAIL', LOG_DIR . '/mail.jsonl');
define('BRAND_SHORT', 'ЦМК');
define('MAIL_FROM_NAME', 'ЦМК-Подписка');
define('CABINET_BASE', 'https://edu.example.test/webinar/');
define('WEBINARS_CACHE_TTL', 30);

date_default_timezone_set('Europe/Moscow');
require_once BASE_DIR . '/lib/security.php';
require_once BASE_DIR . '/lib/storage.php';
require_once BASE_DIR . '/api/users.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
};
$cleanup = static function (string $dir) use (&$cleanup): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && !is_link($path)) $cleanup($path);
        else @unlink($path);
    }
    @rmdir($dir);
};

try {
    $_SESSION = ['csrf' => 'test-csrf-token'];
    $assert(csrf_check('test-csrf-token'), 'valid CSRF token is accepted');
    $assert(!csrf_check('wrong-token'), 'invalid CSRF token is rejected');
    $assert(!csrf_check(['test-csrf-token']), 'non-string CSRF token is rejected');

    $assert(safe_web_url('https://example.com/webinar/42') !== '', 'public HTTPS link is accepted');
    $assert(safe_web_url('http://example.com/webinar/42') === '', 'plain HTTP link is rejected');
    $assert(safe_web_url('javascript:alert(1)') === '', 'script URL is rejected');
    $assert(safe_web_url('https://user:pass@example.com/') === '', 'URL credentials are rejected');
    $assert(safe_web_url('https://127.0.0.1/') === '', 'IPv4 loopback URL is rejected');
    $assert(safe_web_url('https://[::1]/') === '', 'IPv6 loopback URL is rejected');
    $assert(safe_web_url('https://2130706433/') === '', 'integer-form loopback URL is rejected');
    $assert(safe_web_url('https://0x7f000001/') === '', 'hex-form loopback URL is rejected');
    $assert(safe_web_url('https://intranet/') === '', 'single-label local hostname is rejected');

    $assert(!remote_source_url_valid('http://8.8.8.8/feed.json'), 'remote source requires HTTPS');
    $assert(!remote_source_url_valid('https://intranet/feed.json'), 'single-label source hostname is rejected');
    $assert(!remote_source_url_valid('https://127.0.0.1/feed.json'), 'private source IP is rejected');
    $assert(!remote_source_url_valid('https://[::1]/feed.json'), 'IPv6 loopback source is rejected');
    $assert(!remote_source_url_valid('https://2130706433/feed.json'), 'non-canonical numeric source host is rejected');
    $assert(!remote_source_url_valid('https://user:pass@8.8.8.8/feed.json'), 'source credentials are rejected');
    $assert(remote_source_url_valid('https://8.8.8.8/feed.json'), 'public IPv4 HTTPS source is accepted');

    $publicUser = user_public(['login' => 'alice', 'password_hash' => 'secret-hash', 'api_token' => 'private', 'role' => 'user']);
    $assert(isset($publicUser['login']) && !isset($publicUser['password_hash']) && !isset($publicUser['api_token']), 'public user data uses a strict allow-list');
    $assert(norm_cats(['invalid']) === [], 'unknown categories do not grant access');
    $assert(norm_cats(['all', 'zhkh']) === ['all'], 'all-category grant is canonicalized');
    $assert(valid_new_password('Abcd1234!xyz'), '12-character password is accepted');
    $assert(!valid_new_password('short'), 'short password is rejected');
    $assert(valid_new_password(str_repeat('я', 12)), 'Unicode password length is counted in characters');
    $assert(!valid_new_password(str_repeat('я', 37)), 'password over 72 bytes is rejected');

    $tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    $validSource = [
        'id' => 'w-1', 'date' => $tomorrow, 'time' => '10:30', 'speaker' => 'Лектор',
        'title' => 'Проверка', 'price' => '1250.50', 'direction' => 'ЖКХ',
        'link_participant' => 'https://example.com/w-1',
    ];
    $normalized = webinar_normalize($validSource, settings_defaults()['field_map']);
    $assert(is_array($normalized) && $normalized['price'] === 1250.5, 'webinar fields are normalized');
    $assert($normalized['link_participant'] === 'https://example.com/w-1', 'safe webinar URL is preserved');
    $invalidDate = $validSource;
    $invalidDate['date'] = '2026-02-30';
    $assert(webinar_normalize($invalidDate, settings_defaults()['field_map']) === null, 'impossible calendar date is rejected');
    $assert(webinar_category_key(['direction' => 'ЖКХ']) === 'zhkh', 'Russian category aliases normalize');
    $assert(webinar_category_key(['direction' => 'unknown']) === 'other', 'unknown category maps to other');

    $subscriber = [
        'login' => 'zhkh-user', 'role' => 'user', 'active' => true,
        'expires' => (new DateTimeImmutable('+7 days'))->format('Y-m-d'), 'categories' => ['zhkh'],
    ];
    $assert(user_can_access_webinar($subscriber, ['direction' => 'ЖКХ']), 'allowed category is accessible');
    $assert(!user_can_access_webinar($subscriber, ['direction' => 'Газ']), 'other category is denied');
    $expired = $subscriber;
    $expired['expires'] = (new DateTimeImmutable('-2 days'))->format('Y-m-d');
    $assert(!user_can_access_webinar($expired, ['direction' => 'ЖКХ']), 'expired subscription is denied');
    $disabled = $subscriber;
    $disabled['active'] = false;
    $assert(!user_can_access_webinar($disabled, ['direction' => 'ЖКХ']), 'disabled account is denied');

    $assert(settings_save(settings_defaults()), 'test settings can be written');
    $assert((fileperms(SETTINGS_FILE) & 0777) === 0600, 'stored settings have owner-only permissions');
    $webinars = [
        $validSource,
        [
            'id' => 'w-2', 'date' => $tomorrow, 'speaker' => 'Другой лектор', 'title' => 'Закрытое направление',
            'price' => 0, 'direction' => 'Газ', 'link_participant' => 'https://example.com/w-2',
        ],
    ];
    $assert(store_write(WEBINARS_FILE, $webinars), 'isolated webinar fixture is written');
    $visible = webinars_visible_to_user($subscriber);
    $assert(count($visible) === 1 && $visible[0]['id'] === 'w-1', 'API webinar list is filtered by server-side category ACL');
    $assert(webinar_find_for_user('w-2', $subscriber) === null, 'direct lookup cannot bypass category ACL');
    $assert(webinar_find_for_user('w-1', $subscriber) !== null, 'direct lookup returns an authorized webinar');

    echo "OK — {$checks} PHP security regression checks passed.\n";
} finally {
    $cleanup($testDir);
}

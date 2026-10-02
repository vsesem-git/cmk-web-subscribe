<?php
/** Single JSON API entry point. Every route has an explicit HTTP method and authorization policy. */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once BASE_DIR . '/lib/security.php';
require_once BASE_DIR . '/lib/storage.php';
require_once BASE_DIR . '/lib/logs.php';

send_security_headers();
secure_session_start();

$action = $_GET['action'] ?? '';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!is_string($action) || $action === '') {
    json_response(['ok' => false, 'error' => 'Неизвестное действие.'], 404);
}

$actionMethods = [
    'me' => ['GET'],
    'login' => ['POST'],
    'logout' => ['POST'],
    'webinars' => ['GET'],
    'webinars_refresh' => ['POST'],
    'log_view' => ['POST'],
    'my_views' => ['GET'],
    'users_list' => ['GET'],
    'user_save' => ['POST'],
    'user_delete' => ['POST'],
    'users_bulk_preview' => ['POST'],
    'users_bulk_create' => ['POST'],
    'settings_get' => ['GET'],
    'settings_save' => ['POST'],
    'features_save' => ['POST'],
    'colfonts_save' => ['POST'],
    'colwidths_save' => ['POST'],
    'view_save' => ['POST'],
    'columns_save' => ['POST'],
    'fieldmap_save' => ['POST'],
    'catoverrides_save' => ['POST'],
    'presets_save' => ['POST'],
    'retention_save' => ['POST'],
    'logs_prune_now' => ['POST'],
    'logs_clear' => ['POST'],
    'config_export' => ['GET'],
    'config_import' => ['POST'],
    'logs_summary' => ['GET'],
    'logs_list' => ['GET'],
    'logs_export' => ['GET'],
    'user_views' => ['GET'],
    'smtp_test' => ['POST'],
    'mail_preview' => ['POST'],
    'mail_send' => ['POST'],
];
if (!isset($actionMethods[$action])) {
    json_response(['ok' => false, 'error' => 'Неизвестное действие.'], 404);
}
require_method($method, $actionMethods[$action]);

// All POST routes, including login, require the token tied to the current session.
if ($method !== 'GET') {
    $body = read_json_body();
    $token = $body['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_check($token)) {
        json_response(['ok' => false, 'error' => 'Неверный CSRF-токен. Обновите страницу.'], 419);
    }
    $GLOBALS['__body'] = $body;
}
if (is_logged_in()) log_maybe_autoprune();

function respond_with_webinars(): void
{
    require_login();
    $user = current_user();
    $login = (string)($user['login'] ?? '');
    $seen = feature_on('log_views') ? user_view_summary($login) : [];
    json_response([
        'ok' => true,
        'webinars' => webinars_visible_to_user($user),
        'cabinet' => CABINET_BASE,
        'my_views' => array_map(function ($view) {
            return (string)($view['webinar_id'] ?? '');
        }, $seen),
    ]);
}

switch ($action) {
    case 'me':
        $user = current_user();
        $settings = settings_get();
        json_response([
            'ok' => true,
            'authenticated' => $user !== null,
            'user' => $user !== null ? user_public($user) : null,
            'setup_required' => count(users_all()) === 0,
            'csrf' => csrf_token(),
            'brand' => ['short' => BRAND_SHORT, 'full' => BRAND_FULL],
            'features' => $settings['features'] ?? [],
            'show_past' => $settings['show_past'] ?? true,
            'col_fonts' => $settings['col_fonts'] ?? [],
            'col_widths' => $settings['col_widths'] ?? [],
            'view' => $settings['view'] ?? [],
            'columns' => $settings['columns'] ?? [],
            'field_map' => $settings['field_map'] ?? [],
            'cat_overrides' => $settings['cat_overrides'] ?? [],
            'presets' => $settings['presets'] ?? [],
        ]);
        break;

    case 'login':
        $body = $GLOBALS['__body'] ?? [];
        $login = trim((string)($body['login'] ?? ''));
        if (strlen($login) > 80) $login = '';
        $password = (string)($body['password'] ?? '');
        if (strlen($password) > 1024) $password = 'invalid-password-too-long';
        if (!rate_limit('login', 8, 300)) {
            json_response(['ok' => false, 'error' => 'Слишком много попыток. Повторите через несколько минут.'], 429);
        }
        $user = users_find($login);
        static $dummyHash = null;
        if ($dummyHash === null) $dummyHash = password_hash('not-a-real-password', PASSWORD_DEFAULT);
        $hash = is_array($user) ? (string)($user['password_hash'] ?? '') : '';
        $valid = password_verify($password, $hash !== '' ? $hash : $dummyHash);
        if (!$user || !$valid) {
            log_login($login, false, 'bad_credentials');
            json_response(['ok' => false, 'error' => 'Неверный логин или пароль.'], 401);
        }
        if (($user['active'] ?? true) === false) {
            log_login($login, false, 'disabled');
            json_response(['ok' => false, 'error' => 'Аккаунт отключён. Обратитесь к администратору.'], 403);
        }
        session_regenerate_id(true);
        // Rotate the pre-authentication CSRF token together with the session ID.
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['__csrf_created'] = time();
        user_touch_login((string)$user['login']);
        $user = users_find((string)$user['login']) ?? $user;
        $_SESSION['user'] = $user;
        $_SESSION['__auth_hash'] = (string)($user['password_hash'] ?? '');
        log_login((string)$user['login'], true);
        json_response(['ok' => true, 'user' => user_public($user), 'csrf' => csrf_token()]);
        break;

    case 'logout':
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['__started'] = true;
        $_SESSION['__ua'] = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $_SESSION['__last_activity'] = time();
        $_SESSION['__csrf_created'] = time();
        json_response(['ok' => true, 'csrf' => csrf_token()]);
        break;

    case 'webinars':
        respond_with_webinars();
        break;

    case 'webinars_refresh':
        require_login();
        webinars_cache_clear();
        respond_with_webinars();
        break;

    case 'log_view':
        require_login();
        $body = $GLOBALS['__body'] ?? [];
        $user = current_user();
        $webinar = webinar_find_for_user($body['webinar_id'] ?? null, $user);
        if (!$webinar) json_response(['ok' => false, 'error' => 'Вебинар не найден.'], 404);
        if (!feature_on('log_views')) json_response(['ok' => true, 'logged' => false]);
        log_view((string)($user['login'] ?? ''), $webinar['id'], $webinar['title'], 'watch');
        json_response(['ok' => true, 'logged' => true]);
        break;

    case 'my_views':
        require_login();
        $user = current_user();
        if (!feature_on('my_views') && !is_admin()) {
            json_response(['ok' => false, 'error' => 'Раздел отключён администратором.'], 403);
        }
        $login = (string)($user['login'] ?? '');
        json_response(['ok' => true, 'views' => user_view_summary($login), 'user' => user_public($user)]);
        break;

    default:
        require_once BASE_DIR . '/api/users.php';
        require_once BASE_DIR . '/api/mail.php';
        require_once BASE_DIR . '/api/settings.php';
        require_once BASE_DIR . '/api/logs.php';
        handle_users_action($action, $method);
        handle_settings_action($action, $method);
        handle_mail_action($action, $method);
        handle_logs_action($action, $method);
        json_response(['ok' => false, 'error' => 'Неизвестное действие.'], 404);
}

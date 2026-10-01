<?php
/**
 * Единая точка входа API. Все действия — через ?action=...
 * Отвечает только JSON. Все изменения защищены CSRF-токеном и проверкой ролей.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once BASE_DIR . '/lib/security.php';
require_once BASE_DIR . '/lib/storage.php';
require_once BASE_DIR . '/lib/logs.php';

send_security_headers();
secure_session_start();
log_maybe_autoprune();   // ленивая авто-очистка логов (не чаще раза в сутки)

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Для всех изменяющих запросов — обязательная проверка CSRF
$mutating = in_array($method, ['POST', 'PUT', 'DELETE'], true);
if ($mutating && $action !== 'login') {
    $body = read_json_body();
    $token = $body['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_check($token)) {
        json_response(['ok' => false, 'error' => 'Неверный CSRF-токен. Обновите страницу.'], 419);
    }
    $GLOBALS['__body'] = $body;
}

switch ($action) {

    /* ---------- Текущий статус сессии + CSRF ---------- */
    case 'me':
        $s = settings_get();
        json_response([
            'ok' => true,
            'authenticated' => is_logged_in(),
            'user' => is_logged_in() ? user_public(current_user()) : null,
            'csrf' => csrf_token(),
            'brand' => ['short' => BRAND_SHORT, 'full' => BRAND_FULL],
            'features' => $s['features'] ?? [],
            'show_past' => $s['show_past'] ?? true,
            'col_fonts' => $s['col_fonts'] ?? [],
            'col_widths' => $s['col_widths'] ?? [],
            'view' => $s['view'] ?? [],
            'columns' => $s['columns'] ?? [],
            'field_map' => $s['field_map'] ?? [],
            'cat_overrides' => $s['cat_overrides'] ?? [],
            'presets' => $s['presets'] ?? [],
        ]);
        break;

    /* ---------- Вход ---------- */
    case 'login':
        if ($method !== 'POST') json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
        // Защита от перебора: не более 5 попыток за 5 минут на сессию
        if (!rate_limit('login', 5, 300)) {
            json_response(['ok' => false, 'error' => 'Слишком много попыток. Повторите через несколько минут.'], 429);
        }
        $body = read_json_body();
        $login = trim((string)($body['login'] ?? ''));
        $pass  = (string)($body['password'] ?? '');
        $u = users_find($login);
        // Постоянное время: всегда выполняем verify, даже если юзера нет
        $hash = $u['password_hash'] ?? password_hash('dummy', PASSWORD_DEFAULT);
        $valid = password_verify($pass, $hash);
        if (!$u || !$valid) {
            log_login($login, false, 'bad_credentials');
            json_response(['ok' => false, 'error' => 'Неверный логин или пароль.'], 401);
        }
        if (($u['active'] ?? true) === false) {
            log_login($login, false, 'disabled');
            json_response(['ok' => false, 'error' => 'Аккаунт отключён. Обратитесь к администратору.'], 403);
        }
        session_regenerate_id(true);
        user_touch_login($login);
        // подтягиваем свежие поля (last_login и т.п.)
        $u = users_find($login) ?? $u;
        $_SESSION['user'] = $u;
        log_login($login, true);
        json_response(['ok' => true, 'user' => user_public($u), 'csrf' => csrf_token()]);
        break;

    /* ---------- Выход ---------- */
    case 'logout':
        $_SESSION = [];
        session_destroy();
        json_response(['ok' => true]);
        break;

    /* ---------- Список вебинаров (для вошедших) ---------- */
    case 'webinars':
        require_login();
        // ?fresh=1 — принудительно сбросить кэш внешнего источника (кнопка «Обновить»)
        if (($_GET['fresh'] ?? '') === '1') webinars_cache_clear();
        // Применяем сопоставление полей: приводим внешний JSON к каноническим ключам
        $fmap = settings_get()['field_map'] ?? [];
        $list = array_map(function ($w) use ($fmap) {
            if (!is_array($w)) return $w;
            $out = $w;   // сохраняем исходные поля тоже
            foreach ($fmap as $canon => $srcKey) {
                if ($srcKey && $srcKey !== $canon && array_key_exists($srcKey, $w)) {
                    $out[$canon] = $w[$srcKey];
                }
            }
            return $out;
        }, webinars_all());
        json_response([
            'ok' => true,
            'webinars' => $list,
            'cabinet' => CABINET_BASE,
            'my_views' => array_map(fn($v) => (string)$v['webinar_id'], user_view_summary((current_user()['login'] ?? ''))),
        ]);
        break;

    /* ---------- Зафиксировать просмотр вебинара (клик «Смотреть») ---------- */
    case 'log_view':
        require_login();
        $b = $GLOBALS['__body'] ?? [];
        $w = webinar_find($b['webinar_id'] ?? null);
        if (!$w) json_response(['ok' => false, 'error' => 'Вебинар не найден.'], 404);
        log_view((string)(current_user()['login'] ?? ''), $w['id'] ?? '', (string)($w['title'] ?? ''), 'watch');
        json_response(['ok' => true]);
        break;

    /* ---------- Мои просмотры (пользователь видит свою историю) ---------- */
    case 'my_views':
        require_login();
        if (!feature_on('my_views') && !is_admin()) {
            json_response(['ok' => false, 'error' => 'Раздел отключён администратором.'], 403);
        }
        $login = (string)(current_user()['login'] ?? '');
        json_response(['ok' => true, 'views' => user_view_summary($login), 'user' => user_public(current_user())]);
        break;

    default:
        // Делегируем в модули (каждый обрабатывает свои действия и вызывает json_response при совпадении)
        require_once BASE_DIR . '/api/users.php';
        require_once BASE_DIR . '/api/mail.php';
        require_once BASE_DIR . '/api/settings.php';
        require_once BASE_DIR . '/api/logs.php';
        handle_users_action($action, $method);
        handle_settings_action($action, $method);
        handle_mail_action($action, $method);
        handle_logs_action($action, $method);
        json_response(['ok' => false, 'error' => 'Неизвестное действие: ' . h($action)], 404);
}

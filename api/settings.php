<?php
/**
 * Настройки приложения: SMTP, источник данных, отображение прошедших.
 * Чтение (без пароля SMTP) и запись настроек доступны только администратору.
 */
declare(strict_types=1);

require_once BASE_DIR . '/lib/logs.php';

/** Validate and reduce an uploaded settings bundle to the supported schema. */
function sanitize_imported_settings(array $incoming, array $current): ?array
{
    $defaults = settings_defaults();
    $smtpIn = is_array($incoming['smtp'] ?? null) ? $incoming['smtp'] : [];
    $smtpCurrent = is_array($current['smtp'] ?? null) ? $current['smtp'] : [];
    $sourceUrl = trim((string)($incoming['source_url'] ?? $defaults['source_url']));
    if ($sourceUrl !== '' && !remote_source_url_valid($sourceUrl)) return null;

    $secure = strtolower((string)($smtpIn['secure'] ?? 'ssl'));
    $host = trim((string)($smtpIn['host'] ?? ''));
    $port = (int)($smtpIn['port'] ?? 465);
    $user = trim((string)($smtpIn['user'] ?? ''));
    $fromEmail = trim((string)($smtpIn['from_email'] ?? ''));
    $fromName = preg_replace('/[\\r\\n\\x00-\\x1F\\x7F]/', ' ', (string)($smtpIn['from_name'] ?? MAIL_FROM_NAME));
    $pass = $smtpIn['pass'] ?? '';
    if (!is_string($pass)) return null;
    if ($pass === '') $pass = (string)($smtpCurrent['pass'] ?? '');
    if (!in_array($secure, ['ssl', 'tls'], true) || $port < 1 || $port > 65535
        || strlen($host) > 253 || preg_match('/[\\s\\r\\n\\x00]/', $host)
        || strlen($user) > 254 || strlen($pass) > 2048 || strlen((string)$fromName) > 120
        || ($fromEmail !== '' && !is_email($fromEmail))) return null;
    if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP)
        && !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $host)) return null;

    $out = $defaults;
    $out['smtp'] = [
        'host' => $host, 'port' => $port, 'secure' => $secure, 'user' => $user,
        'pass' => $pass, 'from_email' => $fromEmail,
        'from_name' => trim((string)$fromName) ?: MAIL_FROM_NAME,
    ];
    $out['source_url'] = $sourceUrl;
    $out['show_past'] = !empty($incoming['show_past']);

    $featureInput = is_array($incoming['features'] ?? null) ? $incoming['features'] : [];
    foreach ($defaults['features'] as $key => $_) $out['features'][$key] = !empty($featureInput[$key]);

    $retention = is_array($incoming['log_retention'] ?? null) ? $incoming['log_retention'] : [];
    $out['log_retention'] = [
        'enabled' => !empty($retention['enabled']),
        'days' => max(0, min(3650, (int)($retention['days'] ?? $defaults['log_retention']['days']))),
        'max_lines' => max(0, min(1000000, (int)($retention['max_lines'] ?? $defaults['log_retention']['max_lines']))),
    ];

    $fontInput = is_array($incoming['col_fonts'] ?? null) ? $incoming['col_fonts'] : [];
    $widthInput = is_array($incoming['col_widths'] ?? null) ? $incoming['col_widths'] : [];
    foreach ($defaults['col_fonts'] as $key => $value) {
        $out['col_fonts'][$key] = max(10, min(28, (int)($fontInput[$key] ?? $value)));
    }
    foreach ($defaults['col_widths'] as $key => $value) {
        $width = (int)($widthInput[$key] ?? $value);
        $out['col_widths'][$key] = $width <= 0 ? 0 : max(60, min(600, $width));
    }

    $viewInput = is_array($incoming['view'] ?? null) ? $incoming['view'] : [];
    $out['view']['density'] = in_array(($viewInput['density'] ?? ''), ['compact', 'normal', 'comfortable'], true) ? $viewInput['density'] : 'normal';
    $out['view']['theme'] = in_array(($viewInput['theme'] ?? ''), ['light', 'dark'], true) ? $viewInput['theme'] : 'light';
    $out['view']['start_hour'] = max(0, min(23, (int)($viewInput['start_hour'] ?? 10)));
    $out['view']['mode'] = in_array(($viewInput['mode'] ?? ''), ['table', 'cards'], true) ? $viewInput['mode'] : 'table';
    $out['view']['page_size'] = max(0, min(500, (int)($viewInput['page_size'] ?? 0)));
    $format = (string)($viewInput['date_format'] ?? 'D MMMM YYYY');
    $out['view']['date_format'] = preg_match('/^[DMyY .,:\\/-]{1,40}$/', $format) ? $format : 'D MMMM YYYY';

    $allowedColumns = ['date', 'speaker', 'timer', 'title', 'price', 'action'];
    $columnInput = is_array($incoming['columns'] ?? null) ? $incoming['columns'] : [];
    $columns = [];
    $seenColumns = [];
    foreach ($columnInput as $column) {
        if (!is_array($column) || !in_array(($column['key'] ?? ''), $allowedColumns, true) || isset($seenColumns[$column['key']])) continue;
        $key = $column['key'];
        $seenColumns[$key] = true;
        $columns[] = ['key' => $key, 'label' => substr(trim((string)($column['label'] ?? $key)), 0, 60), 'visible' => !empty($column['visible'])];
    }
    foreach ($allowedColumns as $key) {
        if (!isset($seenColumns[$key])) $columns[] = ['key' => $key, 'label' => $key, 'visible' => false];
    }
    $out['columns'] = $columns;

    $fieldInput = is_array($incoming['field_map'] ?? null) ? $incoming['field_map'] : [];
    foreach (array_keys($defaults['field_map']) as $key) {
        $field = trim((string)($fieldInput[$key] ?? $key));
        $out['field_map'][$key] = preg_match('/^[\\p{L}\\p{N}_. -]{1,60}$/u', $field) ? $field : $key;
    }

    $colorKeys = ['zhkh', 'zdrav', 'electro', 'eco', 'build', 'land', 'goz', 'gas', 'other'];
    $overrideInput = is_array($incoming['cat_overrides'] ?? null) ? $incoming['cat_overrides'] : [];
    $overrides = [];
    foreach ($colorKeys as $key) {
        if (!isset($overrideInput[$key]) || !is_array($overrideInput[$key])) continue;
        $entry = [];
        if (isset($overrideInput[$key]['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$overrideInput[$key]['color'])) $entry['color'] = (string)$overrideInput[$key]['color'];
        $label = trim((string)($overrideInput[$key]['label'] ?? ''));
        if ($label !== '') $entry['label'] = substr($label, 0, 40);
        if ($entry) $overrides[$key] = $entry;
    }
    $out['cat_overrides'] = $overrides;

    $presetInput = is_array($incoming['presets'] ?? null) ? array_slice($incoming['presets'], 0, 30) : [];
    $presets = [];
    foreach ($presetInput as $preset) {
        if (!is_array($preset)) continue;
        $name = trim((string)($preset['name'] ?? ''));
        $period = (string)($preset['period'] ?? 'all');
        $category = (string)($preset['cat'] ?? 'all');
        if ($name === '' || !in_array($category, array_merge(['all', 'other'], valid_cat_keys()), true)) continue;
        if (!in_array($period, ['all', 'upcoming', 'past'], true) && !preg_match('/^year:\\d{4}$/', $period)) continue;
        $presets[] = ['name' => substr($name, 0, 40), 'period' => $period, 'cat' => $category, 'search' => substr((string)($preset['search'] ?? ''), 0, 80)];
    }
    $out['presets'] = $presets;
    return $out;
}

function handle_settings_action(string $action, string $method): void
{
    switch ($action) {

        case 'settings_get':
            require_admin();
            $s = settings_get();
            $smtp = $s['smtp'] ?? [];
            // Return an explicit allow-list; the SMTP password and unknown imported keys never leave the server.
            $safe = [
                'smtp' => [
                    'host' => $smtp['host'] ?? '',
                    'port' => $smtp['port'] ?? 465,
                    'secure' => $smtp['secure'] ?? 'ssl',
                    'user' => $smtp['user'] ?? '',
                    'from_email' => $smtp['from_email'] ?? '',
                    'from_name' => $smtp['from_name'] ?? MAIL_FROM_NAME,
                    'pass_set' => !empty($smtp['pass']),
                ],
                'source_url' => $s['source_url'] ?? '',
                'show_past' => $s['show_past'] ?? true,
                'log_retention' => $s['log_retention'] ?? settings_defaults()['log_retention'],
            ];
            json_response(['ok' => true, 'settings' => $safe]);
            break;

        case 'settings_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $cur = settings_get();
            $inSmtp = is_array($b['smtp'] ?? null) ? $b['smtp'] : [];
            $secure = strtolower(trim((string)($inSmtp['secure'] ?? 'ssl')));
            $host = trim((string)($inSmtp['host'] ?? ''));
            $port = (int)($inSmtp['port'] ?? 465);
            $smtpUser = trim((string)($inSmtp['user'] ?? ''));
            $fromEmail = trim((string)($inSmtp['from_email'] ?? ''));
            $fromName = preg_replace('/[\\r\\n\\x00-\\x1F\\x7F]/', ' ', (string)($inSmtp['from_name'] ?? ''));
            $newPass = (string)($inSmtp['pass'] ?? '');
            if (!in_array($secure, ['ssl', 'tls'], true)) {
                json_response(['ok' => false, 'error' => 'Выберите SSL или STARTTLS. Открытое SMTP запрещено.'], 422);
            }
            if ($port < 1 || $port > 65535 || strlen($host) > 253 || preg_match('/[\\s\\r\\n\\x00]/', $host)
                || strlen($smtpUser) > 254 || strlen($newPass) > 2048 || strlen((string)$fromName) > 120) {
                json_response(['ok' => false, 'error' => 'Проверьте параметры SMTP.'], 422);
            }
            if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP)
                && !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $host)) {
                json_response(['ok' => false, 'error' => 'Некорректное имя SMTP-сервера.'], 422);
            }
            if ($fromEmail !== '' && !is_email($fromEmail)) {
                json_response(['ok' => false, 'error' => 'Некорректный email отправителя.'], 422);
            }
            $newSmtp = [
                'host' => $host,
                'port' => $port,
                'secure' => $secure,
                'user' => $smtpUser,
                'from_email' => $fromEmail,
                'from_name' => trim((string)$fromName) ?: MAIL_FROM_NAME,
                'pass' => $newPass !== '' ? $newPass : (string)($cur['smtp']['pass'] ?? ''),
            ];
            $newSourceUrl = trim((string)($b['source_url'] ?? ($cur['source_url'] ?? '')));
            if ($newSourceUrl !== '' && !remote_source_url_valid($newSourceUrl)) {
                json_response(['ok' => false, 'error' => 'Источник должен быть публичным HTTPS-адресом без перенаправления на локальную сеть.'], 422);
            }
            $cur['smtp'] = $newSmtp;
            $cur['source_url'] = $newSourceUrl;
            $cur['show_past'] = (bool)($b['show_past'] ?? ($cur['show_past'] ?? true));
            if (!settings_save($cur)) json_response(['ok' => false, 'error' => 'Не удалось сохранить настройки.'], 500);
            webinars_cache_clear();
            json_response(['ok' => true]);
            break;

        /* Сохранить тумблеры функций */
        case 'features_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = is_array($b['features'] ?? null) ? $b['features'] : [];
            $cur = settings_get();
            $defaults = settings_defaults()['features'];
            $features = [];
            foreach ($defaults as $k => $_) {
                $features[$k] = !empty($in[$k]);
            }
            $cur['features'] = $features;
            if (isset($b['show_past'])) $cur['show_past'] = (bool)$b['show_past'];
            if (!settings_save($cur)) json_response(['ok' => false, 'error' => 'Не удалось сохранить настройки.'], 500);
            json_response(['ok' => true, 'features' => $features]);
            break;

        /* Сохранить размеры шрифта по столбцам */
        case 'colfonts_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = is_array($b['col_fonts'] ?? null) ? $b['col_fonts'] : [];
            $cur = settings_get();
            $defaults = settings_defaults()['col_fonts'];
            $cf = [];
            foreach ($defaults as $k => $def) {
                $v = (int)($in[$k] ?? $def);
                $cf[$k] = max(10, min(28, $v));   // ограничим 10–28px
            }
            $cur['col_fonts'] = $cf;
            if (!settings_save($cur)) json_response(['ok' => false, 'error' => 'Не удалось сохранить настройки.'], 500);
            json_response(['ok' => true, 'col_fonts' => $cf]);
            break;

        /* Сохранить ширины столбцов */
        case 'colwidths_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = is_array($b['col_widths'] ?? null) ? $b['col_widths'] : [];
            $cur = settings_get();
            $defaults = settings_defaults()['col_widths'];
            $cw = [];
            foreach ($defaults as $k => $def) {
                $v = (int)($in[$k] ?? $def);
                // 0 = авто; иначе ограничим 60–600
                $cw[$k] = $v <= 0 ? 0 : max(60, min(600, $v));
            }
            $cur['col_widths'] = $cw;
            if (!settings_save($cur)) json_response(['ok' => false, 'error' => 'Не удалось сохранить настройки.'], 500);
            json_response(['ok' => true, 'col_widths' => $cw]);
            break;

        /* Сохранить вид (плотность, тема, час старта) */
        case 'view_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = is_array($b['view'] ?? null) ? $b['view'] : [];
            $cur = settings_get();
            $v = $cur['view'] ?? [];
            $dCur = $v['density'] ?? 'normal';
            $dIn = $in['density'] ?? $dCur;
            $v['density'] = in_array($dIn, ['compact','normal','comfortable'], true) ? $dIn : $dCur;
            $tCur = $v['theme'] ?? 'light';
            $tIn = $in['theme'] ?? $tCur;
            $v['theme'] = in_array($tIn, ['light','dark'], true) ? $tIn : $tCur;
            $v['start_hour'] = max(0, min(23, (int)($in['start_hour'] ?? ($v['start_hour'] ?? 10))));
            $v['mode'] = in_array(($in['mode'] ?? ($v['mode'] ?? 'table')), ['table','cards'], true) ? $in['mode'] : ($v['mode'] ?? 'table');
            $v['page_size'] = max(0, min(500, (int)($in['page_size'] ?? ($v['page_size'] ?? 0))));
            $df = trim((string)($in['date_format'] ?? ($v['date_format'] ?? 'D MMMM YYYY')));
            $v['date_format'] = preg_match('/^[DMyY .,:\\/-]{1,40}$/', $df) ? $df : 'D MMMM YYYY';
            $cur['view'] = $v;
            if (!settings_save($cur)) json_response(['ok' => false, 'error' => 'Не удалось сохранить настройки.'], 500);
            json_response(['ok' => true, 'view' => $v]);
            break;

        /* Сохранить конфиг колонок (порядок + видимость + подписи) */
        case 'columns_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = $b['columns'] ?? [];
            $allowed = ['date','speaker','timer','title','price','action'];
            $out = []; $seen = [];
            if (is_array($in)) {
                foreach ($in as $c) {
                    if (!is_array($c)) continue;
                    $k = $c['key'] ?? '';
                    if (!in_array($k, $allowed, true) || isset($seen[$k])) continue;
                    $seen[$k] = true;
                    $out[] = [
                        'key' => $k,
                        'label' => substr(trim((string)($c['label'] ?? $k)), 0, 60),
                        'visible' => !empty($c['visible']),
                    ];
                }
            }
            // добьём отсутствующие колонки (скрытыми), чтобы конфиг был полным
            foreach ($allowed as $k) {
                if (!isset($seen[$k])) $out[] = ['key' => $k, 'label' => $k, 'visible' => false];
            }
            $cur = settings_get();
            $cur['columns'] = $out;
            if (!settings_save($cur)) json_response(['ok' => false, 'error' => 'Не удалось сохранить настройки.'], 500);
            json_response(['ok' => true, 'columns' => $out]);
            break;

        /* Сохранить сопоставление полей JSON */
        case 'fieldmap_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = is_array($b['field_map'] ?? null) ? $b['field_map'] : [];
            $canon = ['date','speaker','title','price','direction','link_participant','time','id'];
            $out = [];
            foreach ($canon as $k) {
                $candidate = trim((string)($in[$k] ?? $k));
                $out[$k] = preg_match('/^[\\p{L}\\p{N}_. -]{1,60}$/u', $candidate) ? $candidate : $k;
            }
            $cur = settings_get();
            $cur['field_map'] = $out;
            if (!settings_save($cur)) json_response(['ok' => false, 'error' => 'Не удалось сохранить настройки.'], 500);
            webinars_cache_clear();   // формат мог измениться — обновить кэш
            json_response(['ok' => true, 'field_map' => $out]);
            break;

        /* Сохранить переопределения категорий (цвет/подпись) */
        case 'catoverrides_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = $b['cat_overrides'] ?? [];
            $keys = ['zhkh','zdrav','electro','eco','build','land','goz','gas','other'];
            $out = [];
            if (is_array($in)) {
                foreach ($keys as $k) {
                    if (!isset($in[$k]) || !is_array($in[$k])) continue;
                    $o = [];
                    if (!empty($in[$k]['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $in[$k]['color'])) $o['color'] = $in[$k]['color'];
                    if (isset($in[$k]['label'])) { $lbl = substr(trim((string)$in[$k]['label']), 0, 40); if ($lbl !== '') $o['label'] = $lbl; }
                    if ($o) $out[$k] = $o;
                }
            }
            $cur = settings_get();
            $cur['cat_overrides'] = $out ?: new stdClass();
            if (!settings_save($cur)) json_response(['ok' => false, 'error' => 'Не удалось сохранить настройки.'], 500);
            json_response(['ok' => true, 'cat_overrides' => $out]);
            break;

        /* Сохранить пользовательские пресеты фильтров */
        case 'presets_save':
            require_login();   // пресеты может сохранять и обычный пользователь (для себя нет БД -> общие, только админ)
            if (!is_admin()) json_response(['ok' => false, 'error' => 'Только администратор может менять общие пресеты.'], 403);
            $b = $GLOBALS['__body'] ?? [];
            $in = is_array($b['presets'] ?? null) ? $b['presets'] : [];
            $out = [];
            if (is_array($in)) {
                foreach (array_slice($in, 0, 30) as $p) {
                    if (!is_array($p) || empty($p['name'])) continue;
                    $name = trim((string)$p['name']);
                    $period = (string)($p['period'] ?? 'all');
                    $category = (string)($p['cat'] ?? 'all');
                    if ($name === '' || !in_array($category, array_merge(['all', 'other'], valid_cat_keys()), true)) continue;
                    if (!in_array($period, ['all', 'upcoming', 'past'], true) && !preg_match('/^year:\\d{4}$/', $period)) continue;
                    $out[] = [
                        'name' => substr($name, 0, 40),
                        'period' => $period,
                        'cat' => $category,
                        'search' => substr((string)($p['search'] ?? ''), 0, 80),
                    ];
                }
            }
            $cur = settings_get();
            $cur['presets'] = $out;
            if (!settings_save($cur)) json_response(['ok' => false, 'error' => 'Не удалось сохранить настройки.'], 500);
            json_response(['ok' => true, 'presets' => $out]);
            break;

        /* Сохранить настройки авто-очистки логов */
        case 'retention_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = is_array($b['log_retention'] ?? null) ? $b['log_retention'] : [];
            $cur = settings_get();
            $cur['log_retention'] = [
                'enabled' => !empty($in['enabled']),
                'days' => max(0, min(3650, (int)($in['days'] ?? 0))),
                'max_lines' => max(0, min(1000000, (int)($in['max_lines'] ?? 0))),
            ];
            if (!settings_save($cur)) json_response(['ok' => false, 'error' => 'Не удалось сохранить настройки.'], 500);
            json_response(['ok' => true]);
            break;

        /* Очистить логи прямо сейчас (по текущим правилам) */
        case 'logs_prune_now':
            require_admin();
            $s = settings_get();
            $r = $s['log_retention'] ?? [];
            $removed = log_prune_all((int)($r['days'] ?? 0), (int)($r['max_lines'] ?? 0));
            json_response(['ok' => true, 'removed' => $removed]);
            break;

        /* Полностью очистить конкретный лог */
        case 'logs_clear':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $type = (string)($b['type'] ?? '');
            $file = ['login' => CMK_LOG_LOGIN, 'views' => CMK_LOG_VIEWS, 'mail' => CMK_LOG_MAIL][$type] ?? null;
            if (!$file) json_response(['ok' => false, 'error' => 'Неизвестный тип лога.'], 422);
            @file_put_contents($file, '', LOCK_EX);
            json_response(['ok' => true]);
            break;

        /* Экспорт всей конфигурации одним файлом */
        case 'config_export':
            require_admin();
            $withSmtpPass = (($_GET['smtp_pass'] ?? '0') === '1');
            $settings = settings_get();
            if (!$withSmtpPass) {
                // не выгружаем пароль SMTP в открытом виде
                $settings['smtp']['pass'] = '';
            }
            $bundle = [
                'app'      => BRAND_SHORT,
                'kind'     => 'cmk-config-bundle',
                'version'  => 1,
                'exported' => date('c'),
                'includes_smtp_password' => $withSmtpPass,
                'settings' => $settings,
                'users'    => users_all(), // пароли — только password_hash()-хеши
                'webinars' => webinars_all(),
            ];
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="cmk-config-' . date('Y-m-d') . '.json"');
            echo json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;

        /* Импорт конфигурации из файла */
        case 'config_import':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $bundle = $b['bundle'] ?? null;
            $parts = $b['parts'] ?? ['settings' => true, 'users' => true, 'webinars' => false];
            if (!is_array($bundle) || ($bundle['kind'] ?? '') !== 'cmk-config-bundle') {
                json_response(['ok' => false, 'error' => 'Файл не является конфигурацией ' . BRAND_SHORT . '.'], 422);
            }
            $applied = [];

            // Настройки: only known keys are accepted, and security-sensitive fields are revalidated.
            if (!empty($parts['settings']) && isset($bundle['settings']) && is_array($bundle['settings'])) {
                $merged = sanitize_imported_settings($bundle['settings'], settings_get());
                if ($merged === null) {
                    json_response(['ok' => false, 'error' => 'В импортируемых настройках есть недопустимый адрес источника или параметры SMTP.'], 422);
                }
                if (!settings_save($merged)) json_response(['ok' => false, 'error' => 'Не удалось импортировать настройки.'], 500);
                webinars_cache_clear();
                $applied[] = 'настройки';
            }

            // Accounts must have unique logins, valid password hashes, and an active administrator.
            if (!empty($parts['users']) && isset($bundle['users']) && is_array($bundle['users'])) {
                $users = $bundle['users'];
                if (count($users) > 5000) json_response(['ok' => false, 'error' => 'В файле слишком много пользователей.'], 422);
                $seenLogins = [];
                foreach ($users as &$user) {
                    if (!is_array($user)) json_response(['ok' => false, 'error' => 'Некорректная запись пользователя в файле.'], 422);
                    $login = trim((string)($user['login'] ?? ''));
                    $passwordHash = (string)($user['password_hash'] ?? '');
                    $key = mb_lower($login);
                    $hashInfo = password_get_info($passwordHash);
                    if (!preg_match('/^[A-Za-zА-Яа-яЁё0-9_]{3,20}$/u', $login) || isset($seenLogins[$key])
                        || empty($hashInfo['algo']) || !in_array(($user['role'] ?? ''), ['admin', 'user'], true)
                        || !is_array($user['categories'] ?? null) || !norm_cats($user['categories'])
                        || (($user['email'] ?? '') !== '' && !is_email((string)$user['email']))
                        || strlen((string)($user['org'] ?? '')) > 160) {
                        json_response(['ok' => false, 'error' => 'Некорректные или повторяющиеся учётные данные в файле.'], 422);
                    }
                    $expires = trim((string)($user['expires'] ?? ''));
                    if ($expires !== '' && norm_date($expires) === null) {
                        json_response(['ok' => false, 'error' => 'Некорректная дата подписки в файле пользователей.'], 422);
                    }
                    $user['login'] = $login;
                    $user['categories'] = norm_cats($user['categories']);
                    $user['expires'] = $expires;
                    $user['active'] = ($user['active'] ?? true) !== false;
                    $seenLogins[$key] = true;
                }
                unset($user);
                if (!users_have_active_admin($users)) {
                    json_response(['ok' => false, 'error' => 'В импортируемых пользователях нет активного администратора — импорт отменён.'], 422);
                }
                if (!users_save($users)) json_response(['ok' => false, 'error' => 'Не удалось импортировать пользователей.'], 500);
                $applied[] = 'пользователи (' . count($users) . ')';
            }

            // Webinars are normalized and bounded when served; never import an unbounded/non-object list.
            if (!empty($parts['webinars']) && isset($bundle['webinars']) && is_array($bundle['webinars'])) {
                $webinars = $bundle['webinars'];
                if (count($webinars) > 1000) json_response(['ok' => false, 'error' => 'В файле слишком много вебинаров.'], 422);
                foreach ($webinars as $webinar) {
                    if (!is_array($webinar)) json_response(['ok' => false, 'error' => 'Некорректная запись вебинара в файле.'], 422);
                }
                if (!store_write(WEBINARS_FILE, array_values($webinars))) json_response(['ok' => false, 'error' => 'Не удалось импортировать вебинары.'], 500);
                $applied[] = 'вебинары (' . count($webinars) . ')';
            }

            if (!$applied) json_response(['ok' => false, 'error' => 'Нечего импортировать — выберите разделы.'], 422);
            json_response(['ok' => true, 'applied' => $applied]);
            break;
    }
}

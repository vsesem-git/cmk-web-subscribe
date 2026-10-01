<?php
/**
 * Настройки приложения: SMTP, источник данных, отображение прошедших.
 * Чтение доступно вошедшим (без пароля SMTP), запись — только администратору.
 */
declare(strict_types=1);

require_once BASE_DIR . '/lib/logs.php';

function handle_settings_action(string $action, string $method): void
{
    switch ($action) {

        case 'settings_get':
            require_login();
            $s = settings_get();
            // Пароль SMTP наружу не отдаём — только признак, что он задан
            $smtp = $s['smtp'] ?? [];
            $safe = $s;
            $safe['smtp'] = [
                'host' => $smtp['host'] ?? '',
                'port' => $smtp['port'] ?? 465,
                'secure' => $smtp['secure'] ?? 'ssl',
                'user' => $smtp['user'] ?? '',
                'from_email' => $smtp['from_email'] ?? '',
                'from_name' => $smtp['from_name'] ?? MAIL_FROM_NAME,
                'pass_set' => !empty($smtp['pass']),
            ];
            json_response(['ok' => true, 'settings' => $safe]);
            break;

        case 'settings_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $cur = settings_get();
            $inSmtp = $b['smtp'] ?? [];

            $secure = strtolower((string)($inSmtp['secure'] ?? 'ssl'));
            if (!in_array($secure, ['ssl', 'tls', 'none'], true)) $secure = 'ssl';

            $newSmtp = [
                'host' => trim((string)($inSmtp['host'] ?? '')),
                'port' => (int)($inSmtp['port'] ?? 465),
                'secure' => $secure,
                'user' => trim((string)($inSmtp['user'] ?? '')),
                'from_email' => trim((string)($inSmtp['from_email'] ?? '')),
                'from_name' => trim((string)($inSmtp['from_name'] ?? '')) ?: MAIL_FROM_NAME,
                // Пароль: если пришёл непустой — обновляем, иначе сохраняем прежний
                'pass' => (isset($inSmtp['pass']) && $inSmtp['pass'] !== '')
                    ? (string)$inSmtp['pass']
                    : (string)($cur['smtp']['pass'] ?? ''),
            ];
            if ($newSmtp['from_email'] !== '' && !is_email($newSmtp['from_email'])) {
                json_response(['ok' => false, 'error' => 'Некорректный email отправителя.'], 422);
            }
            $cur['smtp'] = $newSmtp;
            $cur['source_url'] = trim((string)($b['source_url'] ?? ($cur['source_url'] ?? '')));
            $cur['show_past'] = (bool)($b['show_past'] ?? ($cur['show_past'] ?? true));
            settings_save($cur);
            webinars_cache_clear();   // источник мог смениться — сбросить кэш
            json_response(['ok' => true]);
            break;

        /* Сохранить тумблеры функций */
        case 'features_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = $b['features'] ?? [];
            $cur = settings_get();
            $defaults = settings_defaults()['features'];
            $features = [];
            foreach ($defaults as $k => $_) {
                $features[$k] = !empty($in[$k]);
            }
            $cur['features'] = $features;
            if (isset($b['show_past'])) $cur['show_past'] = (bool)$b['show_past'];
            settings_save($cur);
            json_response(['ok' => true, 'features' => $features]);
            break;

        /* Сохранить размеры шрифта по столбцам */
        case 'colfonts_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = $b['col_fonts'] ?? [];
            $cur = settings_get();
            $defaults = settings_defaults()['col_fonts'];
            $cf = [];
            foreach ($defaults as $k => $def) {
                $v = (int)($in[$k] ?? $def);
                $cf[$k] = max(10, min(28, $v));   // ограничим 10–28px
            }
            $cur['col_fonts'] = $cf;
            settings_save($cur);
            json_response(['ok' => true, 'col_fonts' => $cf]);
            break;

        /* Сохранить ширины столбцов */
        case 'colwidths_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = $b['col_widths'] ?? [];
            $cur = settings_get();
            $defaults = settings_defaults()['col_widths'];
            $cw = [];
            foreach ($defaults as $k => $def) {
                $v = (int)($in[$k] ?? $def);
                // 0 = авто; иначе ограничим 60–600
                $cw[$k] = $v <= 0 ? 0 : max(60, min(600, $v));
            }
            $cur['col_widths'] = $cw;
            settings_save($cur);
            json_response(['ok' => true, 'col_widths' => $cw]);
            break;

        /* Сохранить вид (плотность, тема, час старта) */
        case 'view_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = $b['view'] ?? [];
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
            $v['date_format'] = $df !== '' ? substr($df, 0, 40) : 'D MMMM YYYY';
            $cur['view'] = $v;
            settings_save($cur);
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
            settings_save($cur);
            json_response(['ok' => true, 'columns' => $out]);
            break;

        /* Сохранить сопоставление полей JSON */
        case 'fieldmap_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = $b['field_map'] ?? [];
            $canon = ['date','speaker','title','price','direction','link_participant','time','id'];
            $out = [];
            foreach ($canon as $k) {
                $out[$k] = substr(trim((string)($in[$k] ?? $k)), 0, 60) ?: $k;
            }
            $cur = settings_get();
            $cur['field_map'] = $out;
            settings_save($cur);
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
            settings_save($cur);
            json_response(['ok' => true, 'cat_overrides' => $out]);
            break;

        /* Сохранить пользовательские пресеты фильтров */
        case 'presets_save':
            require_login();   // пресеты может сохранять и обычный пользователь (для себя нет БД -> общие, только админ)
            if (!is_admin()) json_response(['ok' => false, 'error' => 'Только администратор может менять общие пресеты.'], 403);
            $b = $GLOBALS['__body'] ?? [];
            $in = $b['presets'] ?? [];
            $out = [];
            if (is_array($in)) {
                foreach (array_slice($in, 0, 30) as $p) {
                    if (!is_array($p) || empty($p['name'])) continue;
                    $out[] = [
                        'name'   => substr(trim((string)$p['name']), 0, 40),
                        'period' => (string)($p['period'] ?? 'all'),
                        'cat'    => (string)($p['cat'] ?? 'all'),
                        'search' => substr((string)($p['search'] ?? ''), 0, 80),
                    ];
                }
            }
            $cur = settings_get();
            $cur['presets'] = $out;
            settings_save($cur);
            json_response(['ok' => true, 'presets' => $out]);
            break;

        /* Сохранить настройки авто-очистки логов */
        case 'retention_save':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $in = $b['log_retention'] ?? [];
            $cur = settings_get();
            $cur['log_retention'] = [
                'enabled'   => !empty($in['enabled']),
                'days'      => max(0, (int)($in['days'] ?? 0)),
                'max_lines' => max(0, (int)($in['max_lines'] ?? 0)),
            ];
            settings_save($cur);
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
                'users'    => users_all(), // пароли — только bcrypt-хеши
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

            // Настройки
            if (!empty($parts['settings']) && isset($bundle['settings']) && is_array($bundle['settings'])) {
                $incoming = $bundle['settings'];
                // если в файле пустой пароль SMTP — сохраняем текущий (не затираем)
                $cur = settings_get();
                if (empty($incoming['smtp']['pass'])) {
                    $incoming['smtp']['pass'] = $cur['smtp']['pass'] ?? '';
                }
                // мягкое слияние с дефолтами, чтобы не потерять ключи
                $merged = array_replace_recursive(settings_defaults(), $incoming);
                settings_save($merged);
                $applied[] = 'настройки';
            }

            // Пользователи
            if (!empty($parts['users']) && isset($bundle['users']) && is_array($bundle['users'])) {
                $users = $bundle['users'];
                // валидируем минимально: должен быть хотя бы один админ
                $hasAdmin = false;
                foreach ($users as $u) {
                    if (($u['role'] ?? '') === 'admin') { $hasAdmin = true; break; }
                }
                if (!$hasAdmin) {
                    json_response(['ok' => false, 'error' => 'В импортируемых пользователях нет ни одного администратора — импорт отменён.'], 422);
                }
                users_save($users);
                $applied[] = 'пользователи (' . count($users) . ')';
            }

            // Вебинары (необязательно)
            if (!empty($parts['webinars']) && isset($bundle['webinars']) && is_array($bundle['webinars'])) {
                store_write(WEBINARS_FILE, $bundle['webinars']);
                $applied[] = 'вебинары (' . count($bundle['webinars']) . ')';
            }

            if (!$applied) json_response(['ok' => false, 'error' => 'Нечего импортировать — выберите разделы.'], 422);
            json_response(['ok' => true, 'applied' => $applied]);
            break;
    }
}

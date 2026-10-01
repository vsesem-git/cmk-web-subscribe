<?php
/**
 * Управление пользователями (только администратор).
 */
declare(strict_types=1);

/** Ключи направлений. */
function valid_cat_keys(): array
{
    return ['all', 'zhkh', 'zdrav', 'electro', 'eco', 'build', 'land', 'goz', 'gas'];
}

/** Нормализация категорий из запроса. */
function norm_cats($cats): array
{
    if (!is_array($cats)) $cats = [];
    $valid = valid_cat_keys();
    $out = array_values(array_intersect($valid, $cats));
    if (in_array('all', $out, true)) return ['all'];
    return $out ?: ['all'];
}

/** Дата ДД.ММ.ГГГГ или ГГГГ-ММ-ДД -> ГГГГ-ММ-ДД или null. */
function norm_date(string $s): ?string
{
    $s = trim($s);
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
        [$_, $d, $mo, $y] = $m;
    } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        [$_, $y, $mo, $d] = $m;
    } else {
        return null;
    }
    if (!checkdate((int)$mo, (int)$d, (int)$y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** Генерация логина (4 латинских буквы), уникального. */
function gen_login(array $existing): string
{
    $lat = 'abcdefghijklmnopqrstuvwxyz';
    do {
        $l = '';
        for ($i = 0; $i < 4; $i++) $l .= $lat[random_int(0, 25)];
    } while (in_array($l, $existing, true));
    return $l;
}

/** Генерация пароля: 6 симв., минимум 1 цифра + буквы + 1 спецсимвол (безопасный набор). */
function gen_password(): string
{
    $digits = '23456789';
    $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower  = 'abcdefghijkmnpqrstuvwxyz';
    $sym    = '!#$%*+-?';
    $all    = $digits . $upper . $lower;
    $chars = [
        $digits[random_int(0, strlen($digits) - 1)],
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $sym[random_int(0, strlen($sym) - 1)],
    ];
    while (count($chars) < 6) $chars[] = $all[random_int(0, strlen($all) - 1)];
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/** Человекочитаемое направление -> ключ. */
function dir_alias_to_key(string $s): ?string
{
    $map = [
        'жкх' => 'zhkh',
        'здрав' => 'zdrav', 'здравоохранение' => 'zdrav', 'медицина' => 'zdrav',
        'электро' => 'electro', 'электроэнергетика' => 'electro', 'энергетика' => 'electro', 'энерго' => 'electro',
        'экология' => 'eco', 'эко' => 'eco',
        'строительство' => 'build', 'строй' => 'build',
        'земля' => 'land',
        'гоз' => 'goz', 'госрегулирование' => 'goz', 'госзакупки' => 'goz', 'гособоронзаказ' => 'goz',
        'газ' => 'gas',
        'все' => 'all', 'всё' => 'all', 'all' => 'all',
    ];
    $k = mb_lower(trim($s));
    if (in_array($k, valid_cat_keys(), true)) return $k;
    return $map[$k] ?? null;
}

function handle_users_action(string $action, string $method): void
{
    switch ($action) {

        case 'users_list':
            require_admin();
            $list = array_map('user_public', users_all());
            json_response(['ok' => true, 'users' => $list]);
            break;

        case 'user_save': // создание или обновление
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $editing = trim((string)($b['editing'] ?? ''));
            $login   = trim((string)($b['login'] ?? ''));
            $pass    = (string)($b['password'] ?? '');
            $org     = trim((string)($b['org'] ?? ''));
            $email   = trim((string)($b['email'] ?? ''));
            $role    = ($b['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $cats    = norm_cats($b['categories'] ?? []);
            $exp     = norm_date((string)($b['expires'] ?? ''));

            if (!preg_match('/^[A-Za-zА-Яа-я0-9_]{3,20}$/u', $login)) {
                json_response(['ok' => false, 'error' => 'Логин: 3–20 символов (буквы/цифры).'], 422);
            }
            if ($email !== '' && !is_email($email)) {
                json_response(['ok' => false, 'error' => 'Некорректный email.'], 422);
            }
            $users = users_all();
            foreach ($users as $u) {
                if (($u['login'] ?? '') === $login && $login !== $editing) {
                    json_response(['ok' => false, 'error' => 'Такой логин уже существует.'], 422);
                }
            }
            if ($editing) {
                $found = false;
                foreach ($users as &$u) {
                    if (($u['login'] ?? '') === $editing) {
                        $u['login'] = $login;
                        $u['org'] = $org; $u['email'] = $email;
                        $u['role'] = $role; $u['categories'] = $cats;
                        $u['expires'] = $exp ?? '';
                        if ($pass !== '') $u['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                        $found = true;
                        break;
                    }
                }
                unset($u);
                if (!$found) json_response(['ok' => false, 'error' => 'Пользователь не найден.'], 404);
            } else {
                if (strlen($pass) < 4) json_response(['ok' => false, 'error' => 'Пароль слишком короткий.'], 422);
                $users[] = [
                    'login' => $login,
                    'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                    'role' => $role, 'org' => $org, 'email' => $email,
                    'categories' => $cats, 'expires' => $exp ?? '', 'active' => true,
                ];
            }
            users_save($users);
            json_response(['ok' => true]);
            break;

        case 'user_delete':
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $login = trim((string)($b['login'] ?? ''));
            $cur = current_user();
            if ($cur && ($cur['login'] ?? '') === $login) {
                json_response(['ok' => false, 'error' => 'Нельзя удалить текущего пользователя.'], 422);
            }
            $users = array_values(array_filter(users_all(), fn($u) => ($u['login'] ?? '') !== $login));
            users_save($users);
            json_response(['ok' => true]);
            break;

        case 'users_bulk_preview': // разбор + генерация логинов/паролей (без сохранения)
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $text = (string)($b['text'] ?? '');
            $existing = array_map(fn($u) => $u['login'] ?? '', users_all());
            $items = []; $errors = [];
            $lines = preg_split('/\r?\n/', $text);
            $idx = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $idx++;
                $parts = array_map('trim', preg_split('/[;\t]/', $line));
                if (count($parts) < 3) { $errors[] = "Строка $idx: нужно 3 поля через «;»."; continue; }
                [$org, $dirRaw, $dateRaw] = [$parts[0], $parts[1], $parts[2]];
                $email = $parts[3] ?? '';
                $cat = dir_alias_to_key($dirRaw);
                $iso = norm_date($dateRaw);
                $rowErr = [];
                if ($org === '') $rowErr[] = 'пустая организация';
                if (!$cat) $rowErr[] = "неизвестное направление «" . $dirRaw . "»";
                if (!$iso) $rowErr[] = "неверная дата «" . $dateRaw . "»";
                if ($email !== '' && !is_email($email)) $rowErr[] = 'некорректный email';
                if ($rowErr) { $errors[] = "Строка $idx: " . implode(', ', $rowErr); continue; }
                $login = gen_login($existing);
                $existing[] = $login;
                $items[] = [
                    'org' => $org, 'email' => $email, 'categories' => [$cat],
                    'expires' => $iso, 'login' => $login, 'password' => gen_password(),
                    'role' => 'user',
                ];
            }
            json_response(['ok' => true, 'items' => $items, 'errors' => $errors]);
            break;

        case 'users_bulk_create': // сохранить подтверждённые записи
            require_admin();
            $b = $GLOBALS['__body'] ?? [];
            $items = $b['items'] ?? [];
            if (!is_array($items) || !$items) json_response(['ok' => false, 'error' => 'Нет данных для создания.'], 422);
            $users = users_all();
            $existing = array_map(fn($u) => $u['login'] ?? '', $users);
            $created = 0;
            foreach ($items as $it) {
                $login = trim((string)($it['login'] ?? ''));
                if ($login === '' || in_array($login, $existing, true)) $login = gen_login($existing);
                $existing[] = $login;
                $users[] = [
                    'login' => $login,
                    'password_hash' => password_hash((string)($it['password'] ?? gen_password()), PASSWORD_DEFAULT),
                    'role' => 'user',
                    'org' => (string)($it['org'] ?? ''),
                    'email' => (string)($it['email'] ?? ''),
                    'categories' => norm_cats($it['categories'] ?? []),
                    'expires' => norm_date((string)($it['expires'] ?? '')) ?? '',
                    'active' => true,
                ];
                $created++;
            }
            users_save($users);
            json_response(['ok' => true, 'created' => $created]);
            break;
    }
}

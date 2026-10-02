<?php
/** User administration (administrator-only). */
declare(strict_types=1);

function valid_cat_keys(): array
{
    return ['all', 'zhkh', 'zdrav', 'electro', 'eco', 'build', 'land', 'goz', 'gas'];
}

/** Normalize category input without silently granting access to every category. */
function norm_cats($cats): array
{
    if (!is_array($cats)) return [];
    $valid = valid_cat_keys();
    $out = array_values(array_unique(array_intersect($valid, $cats)));
    if (in_array('all', $out, true)) return ['all'];
    return $out;
}

function norm_date(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
        [$_, $day, $month, $year] = $m;
    } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        [$_, $year, $month, $day] = $m;
    } else {
        return null;
    }
    if (!checkdate((int)$month, (int)$day, (int)$year)) return null;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function login_key(string $login): string
{
    return mb_lower(trim($login));
}

function gen_login(array $existing): string
{
    $taken = array_map('login_key', $existing);
    $letters = 'abcdefghijklmnopqrstuvwxyz';
    do {
        $login = '';
        for ($i = 0; $i < 6; $i++) $login .= $letters[random_int(0, 25)];
    } while (in_array($login, $taken, true));
    return $login;
}

/** Generate a 16-character password with upper/lowercase, a digit and a symbol. */
function gen_password(): string
{
    $digits = '23456789';
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $symbols = '!#$%*+-?';
    $all = $digits . $upper . $lower . $symbols;
    $chars = [
        $digits[random_int(0, strlen($digits) - 1)],
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];
    while (count($chars) < 16) $chars[] = $all[random_int(0, strlen($all) - 1)];
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

function dir_alias_to_key(string $value): ?string
{
    $aliases = [
        'жкх' => 'zhkh',
        'здрав' => 'zdrav', 'здравоохранение' => 'zdrav', 'медицина' => 'zdrav',
        'электро' => 'electro', 'электроэнергетика' => 'electro', 'энергетика' => 'electro', 'энерго' => 'electro', 'energy' => 'electro',
        'экология' => 'eco', 'эко' => 'eco',
        'строительство' => 'build', 'строй' => 'build',
        'земля' => 'land',
        'гоз' => 'goz', 'госрегулирование' => 'goz', 'госзакупки' => 'goz', 'гособоронзаказ' => 'goz',
        'газ' => 'gas',
        'все' => 'all', 'всё' => 'all', 'all' => 'all',
    ];
    $key = mb_lower(trim($value));
    if (in_array($key, valid_cat_keys(), true)) return $key;
    return $aliases[$key] ?? null;
}

function users_have_active_admin(array $users): bool
{
    foreach ($users as $user) {
        if (is_array($user) && ($user['role'] ?? '') === 'admin' && ($user['active'] ?? true) !== false) return true;
    }
    return false;
}

function valid_new_password(string $password): bool
{
    if (strlen($password) > 72) return false;
    if (function_exists('mb_strlen')) return mb_strlen($password, 'UTF-8') >= 12;
    return preg_match_all('/./us', $password) >= 12;
}

function handle_users_action(string $action, string $method): void
{
    switch ($action) {
        case 'users_list':
            require_admin();
            json_response(['ok' => true, 'users' => array_map('user_public', users_all())]);
            break;

        case 'user_save':
            require_admin();
            $body = $GLOBALS['__body'] ?? [];
            $editing = trim((string)($body['editing'] ?? ''));
            $login = trim((string)($body['login'] ?? ''));
            $password = (string)($body['password'] ?? '');
            $org = trim((string)($body['org'] ?? ''));
            $email = trim((string)($body['email'] ?? ''));
            $role = ($body['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $categories = norm_cats($body['categories'] ?? []);
            $expiresRaw = trim((string)($body['expires'] ?? ''));
            $expires = $expiresRaw === '' ? '' : norm_date($expiresRaw);

            if (!preg_match('/^[A-Za-zА-Яа-яЁё0-9_]{3,20}$/u', $login)) {
                json_response(['ok' => false, 'error' => 'Логин: 3–20 символов (буквы/цифры).'], 422);
            }
            if (strlen($org) > 160 || ($email !== '' && !is_email($email))) {
                json_response(['ok' => false, 'error' => 'Проверьте организацию и email (не более 254 символов).'], 422);
            }
            if ($expiresRaw !== '' && $expires === null) {
                json_response(['ok' => false, 'error' => 'Некорректная дата окончания подписки.'], 422);
            }
            if (!$categories) {
                json_response(['ok' => false, 'error' => 'Выберите хотя бы одно направление.'], 422);
            }
            if ($password !== '' && !valid_new_password($password)) {
                json_response(['ok' => false, 'error' => 'Новый пароль должен содержать не менее 12 символов (максимум 72 байта).'], 422);
            }

            $users = users_all();
            $editingKey = $editing !== '' ? login_key($editing) : '';
            $loginKey = login_key($login);
            foreach ($users as $user) {
                $existingLogin = (string)($user['login'] ?? '');
                if (login_key($existingLogin) === $loginKey && login_key($existingLogin) !== $editingKey) {
                    json_response(['ok' => false, 'error' => 'Такой логин уже существует.'], 422);
                }
            }

            if ($editing !== '') {
                $found = false;
                foreach ($users as &$user) {
                    if (login_key((string)($user['login'] ?? '')) !== $editingKey) continue;
                    $user['login'] = $login;
                    $user['org'] = $org;
                    $user['email'] = $email;
                    $user['role'] = $role;
                    $user['categories'] = $categories;
                    $user['expires'] = $expires ?? '';
                    if ($password !== '') $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    $found = true;
                    break;
                }
                unset($user);
                if (!$found) json_response(['ok' => false, 'error' => 'Пользователь не найден.'], 404);
            } else {
                if (!valid_new_password($password)) {
                    json_response(['ok' => false, 'error' => 'Пароль должен содержать не менее 12 символов (максимум 72 байта).'], 422);
                }
                $users[] = [
                    'login' => $login,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role, 'org' => $org, 'email' => $email,
                    'categories' => $categories, 'expires' => $expires ?? '', 'active' => true,
                ];
            }
            if (!users_have_active_admin($users)) {
                json_response(['ok' => false, 'error' => 'Нельзя удалить или понизить последнего активного администратора.'], 422);
            }
            if (!users_save($users)) json_response(['ok' => false, 'error' => 'Не удалось сохранить пользователя.'], 500);
            json_response(['ok' => true]);
            break;

        case 'user_delete':
            require_admin();
            $body = $GLOBALS['__body'] ?? [];
            $login = trim((string)($body['login'] ?? ''));
            $current = current_user();
            if ($current && login_key((string)($current['login'] ?? '')) === login_key($login)) {
                json_response(['ok' => false, 'error' => 'Нельзя удалить текущего пользователя.'], 422);
            }
            $users = users_all();
            $remaining = array_values(array_filter($users, function ($user) use ($login) {
                return login_key((string)($user['login'] ?? '')) !== login_key($login);
            }));
            if (count($remaining) === count($users)) json_response(['ok' => false, 'error' => 'Пользователь не найден.'], 404);
            if (!users_have_active_admin($remaining)) {
                json_response(['ok' => false, 'error' => 'Нельзя удалить последнего активного администратора.'], 422);
            }
            if (!users_save($remaining)) json_response(['ok' => false, 'error' => 'Не удалось удалить пользователя.'], 500);
            json_response(['ok' => true]);
            break;

        case 'users_bulk_preview':
            require_admin();
            $body = $GLOBALS['__body'] ?? [];
            $text = (string)($body['text'] ?? '');
            if (strlen($text) > 50000) json_response(['ok' => false, 'error' => 'Файл слишком большой (максимум 50 КБ).'], 413);
            $existing = array_map(function ($user) { return (string)($user['login'] ?? ''); }, users_all());
            $items = [];
            $errors = [];
            $lines = preg_split('/\r?\n/', $text);
            $idx = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $idx++;
                if ($idx > 200) {
                    $errors[] = 'За один раз можно добавить не более 200 пользователей.';
                    break;
                }
                $parts = array_map('trim', preg_split('/[;\t]/', $line));
                if (count($parts) < 3) { $errors[] = "Строка $idx: нужно 3 поля через «;»."; continue; }
                [$org, $direction, $date] = [$parts[0], $parts[1], $parts[2]];
                $email = $parts[3] ?? '';
                $category = dir_alias_to_key($direction);
                $isoDate = norm_date($date);
                $rowErrors = [];
                if ($org === '' || strlen($org) > 160) $rowErrors[] = 'пустая или слишком длинная организация';
                if (!$category) $rowErrors[] = 'неизвестное направление';
                if (!$isoDate) $rowErrors[] = "неверная дата «" . $date . "»";
                if ($email !== '' && !is_email($email)) $rowErrors[] = 'некорректный email';
                if ($rowErrors) { $errors[] = "Строка $idx: " . implode(', ', $rowErrors); continue; }

                $login = gen_login($existing);
                $existing[] = $login;
                $items[] = [
                    'org' => $org, 'email' => $email, 'categories' => [$category],
                    'expires' => $isoDate, 'login' => $login, 'password' => gen_password(), 'role' => 'user',
                ];
            }
            json_response(['ok' => true, 'items' => $items, 'errors' => $errors]);
            break;

        case 'users_bulk_create':
            require_admin();
            $body = $GLOBALS['__body'] ?? [];
            $items = $body['items'] ?? [];
            if (!is_array($items) || !$items || count($items) > 200) {
                json_response(['ok' => false, 'error' => 'Укажите от 1 до 200 пользователей.'], 422);
            }
            $users = users_all();
            $existing = array_map(function ($user) { return login_key((string)($user['login'] ?? '')); }, $users);
            $created = [];
            foreach ($items as $index => $item) {
                if (!is_array($item)) json_response(['ok' => false, 'error' => 'Некорректная запись пользователя.'], 422);
                $login = trim((string)($item['login'] ?? ''));
                $password = (string)($item['password'] ?? '');
                $org = trim((string)($item['org'] ?? ''));
                $email = trim((string)($item['email'] ?? ''));
                $categories = norm_cats($item['categories'] ?? []);
                $expiresRaw = trim((string)($item['expires'] ?? ''));
                $expires = $expiresRaw === '' ? '' : norm_date($expiresRaw);
                $key = login_key($login);
                if (!preg_match('/^[A-Za-zА-Яа-яЁё0-9_]{3,20}$/u', $login) || in_array($key, $existing, true)) {
                    json_response(['ok' => false, 'error' => 'Некорректный или повторяющийся логин в строке ' . ($index + 1) . '.'], 422);
                }
                if (!valid_new_password($password) || $org === '' || strlen($org) > 160
                    || ($email !== '' && !is_email($email)) || !$categories
                    || ($expiresRaw !== '' && $expires === null)) {
                    json_response(['ok' => false, 'error' => 'Некорректные данные пользователя в строке ' . ($index + 1) . '.'], 422);
                }
                $existing[] = $key;
                $created[] = [
                    'login' => $login, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'user', 'org' => $org, 'email' => $email,
                    'categories' => $categories, 'expires' => $expires ?? '', 'active' => true,
                ];
            }
            if (!users_save(array_merge($users, $created))) {
                json_response(['ok' => false, 'error' => 'Не удалось сохранить пользователей.'], 500);
            }
            json_response(['ok' => true, 'created' => count($created)]);
            break;
    }
}

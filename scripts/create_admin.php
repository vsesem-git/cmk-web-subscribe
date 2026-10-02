<?php
/** One-time, CLI-only administrator bootstrap. Run: php scripts/create_admin.php */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require_once __DIR__ . '/../config.php';
require_once BASE_DIR . '/lib/security.php';
require_once BASE_DIR . '/lib/storage.php';

function prompt_secret(string $label): string
{
    fwrite(STDOUT, $label);
    $canHide = DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec');
    if ($canHide) @shell_exec('stty -echo');
    try {
        $value = fgets(STDIN);
    } finally {
        if ($canHide) {
            @shell_exec('stty echo');
            fwrite(STDOUT, "\n");
        }
    }
    return trim((string)$value);
}

if (is_file(USERS_FILE)) {
    $rawUsers = @file_get_contents(USERS_FILE);
    $storedUsers = $rawUsers === false ? null : json_decode($rawUsers, true);
    if (!is_array($storedUsers) || json_last_error() !== JSON_ERROR_NONE
        || !isset($storedUsers['users']) || !is_array($storedUsers['users'])) {
        fwrite(STDERR, "Файл пользователей отсутствует или повреждён/недоступен. Скрипт не будет его перезаписывать.\n");
        exit(1);
    }
}
if (users_all()) {
    fwrite(STDERR, "Пользователи уже настроены. Скрипт ничего не изменил.\n");
    exit(1);
}

fwrite(STDOUT, 'Логин администратора (3–20 символов): ');
$loginInput = function_exists('readline') ? readline() : fgets(STDIN);
$login = trim((string)$loginInput);
if (!preg_match('/^[A-Za-zА-Яа-яЁё0-9_]{3,20}$/u', $login)) {
    fwrite(STDERR, "Недопустимый логин.\n");
    exit(1);
}
$password = prompt_secret('Новый пароль (не менее 12 символов): ');
$confirm = prompt_secret('Повторите пароль: ');
if (preg_match_all('/./us', $password) < 12 || strlen($password) > 72 || $password !== $confirm) {
    fwrite(STDERR, "Пароли не совпадают либо длина пароля должна быть 12+ символов и не превышать 72 байта.\n");
    exit(1);
}

$admin = [
    'login' => $login,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'role' => 'admin',
    'org' => 'Администратор системы',
    'email' => '',
    'categories' => ['all'],
    'expires' => '',
    'active' => true,
];
if (!users_save([$admin])) {
    fwrite(STDERR, "Не удалось сохранить администратора. Проверьте права на каталог data/.\n");
    exit(1);
}

fwrite(STDOUT, "Администратор создан. Войдите в веб-интерфейс и создайте пользователей.\n");

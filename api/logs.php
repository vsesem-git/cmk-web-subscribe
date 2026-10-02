<?php
/**
 * Журналы для администратора: входы, просмотры, письма.
 * Экспорт в CSV. Проверка SMTP.
 */
declare(strict_types=1);

require_once BASE_DIR . '/lib/logs.php';
require_once BASE_DIR . '/lib/mailer.php';

/** Escape spreadsheet formulas and stream a CSV file. */
function csv_safe_value($value): string
{
    $text = (string)$value;
    if (preg_match('/^[\\x00-\\x20]*[=+@-]/', $text)) return "'" . $text;
    return $text;
}

function csv_download(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map('csv_safe_value', $header), ';');
    foreach ($rows as $row) fputcsv($out, array_map('csv_safe_value', $row), ';');
    fclose($out);
    exit;
}

function handle_logs_action(string $action, string $method): void
{
    switch ($action) {

        /* Сводка для дашборда админа */
        case 'logs_summary':
            require_admin();
            $logins = log_read(CMK_LOG_LOGIN);
            $views = log_read(CMK_LOG_VIEWS);
            $mails = log_read(CMK_LOG_MAIL);
            json_response([
                'ok' => true,
                'counts' => [
                    'logins' => count($logins),
                    'logins_ok' => count(array_filter($logins, fn($x) => !empty($x['ok']))),
                    'views' => count($views),
                    'mails' => count($mails),
                    'mails_ok' => count(array_filter($mails, fn($x) => !empty($x['ok']))),
                ],
            ]);
            break;

        /* Данные журнала (последние N, новые сверху) */
        case 'logs_list':
            require_admin();
            $type = $_GET['type'] ?? 'views';
            $file = ['login' => CMK_LOG_LOGIN, 'views' => CMK_LOG_VIEWS, 'mail' => CMK_LOG_MAIL][$type] ?? CMK_LOG_VIEWS;
            $rows = array_reverse(log_read($file));
            json_response(['ok' => true, 'type' => $type, 'rows' => array_slice($rows, 0, 500)]);
            break;

        /* Экспорт журнала в CSV */
        case 'logs_export':
            require_admin();
            $type = $_GET['type'] ?? 'views';
            if ($type === 'login') {
                $rows = array_map(fn($r) => [$r['ts'] ?? '', $r['login'] ?? '', ($r['ok'] ?? false) ? 'успех' : 'ошибка', $r['reason'] ?? '', $r['ip'] ?? ''], log_read(CMK_LOG_LOGIN));
                csv_download('login_log.csv', ['Дата', 'Логин', 'Результат', 'Причина', 'IP'], $rows);
            } elseif ($type === 'mail') {
                $rows = array_map(fn($r) => [$r['ts'] ?? '', $r['by'] ?? '', $r['to'] ?? '', $r['mail_type'] ?? '', $r['subject'] ?? '', ($r['ok'] ?? false) ? 'отправлено' : 'ошибка', $r['error'] ?? ''], log_read(CMK_LOG_MAIL));
                csv_download('mail_log.csv', ['Дата', 'Отправитель', 'Получатель', 'Тип', 'Тема', 'Результат', 'Ошибка'], $rows);
            } else {
                $rows = array_map(fn($r) => [$r['ts'] ?? '', $r['login'] ?? '', $r['webinar_id'] ?? '', $r['title'] ?? '', $r['ip'] ?? ''], log_read(CMK_LOG_VIEWS));
                csv_download('views_log.csv', ['Дата', 'Логин', 'ID вебинара', 'Тема', 'IP'], $rows);
            }
            break;

        /* Просмотры конкретного пользователя (для админа) */
        case 'user_views':
            require_admin();
            $login = trim((string)($_GET['login'] ?? ''));
            json_response(['ok' => true, 'login' => $login, 'views' => user_view_summary($login)]);
            break;

        /* Проверка SMTP — отправка тестового письма */
        case 'smtp_test':
            require_admin();
            if (!rate_limit('smtp_test', 5, 300)) {
                json_response(['ok' => false, 'error' => 'Слишком часто. Повторите через несколько минут.'], 429);
            }
            $b = $GLOBALS['__body'] ?? [];
            $to = trim((string)($b['email'] ?? ''));
            if (!is_email($to)) json_response(['ok' => false, 'error' => 'Укажите корректный email для теста.'], 422);
            $html = '<div style="font-family:Arial,sans-serif;padding:20px">'
                . '<h2 style="color:#0E7B7B">Проверка SMTP — ' . h(BRAND_SHORT) . '</h2>'
                . '<p>Это тестовое письмо. Если вы его получили — почта настроена корректно.</p>'
                . '<p style="color:#6B7380;font-size:13px">Отправлено: ' . date('d.m.Y H:i') . '</p></div>';
            $res = send_mail($to, '', 'Проверка SMTP — ' . BRAND_SHORT, $html);
            $by = (string)(current_user()['login'] ?? '');
            log_mail($by, $to, 'test', 'Проверка SMTP', $res['ok'], $res['ok'] ? '' : ($res['error'] ?? ''));
            if (!$res['ok']) json_response(['ok' => false, 'error' => $res['error']], 502);
            json_response(['ok' => true]);
            break;
    }
}

<?php
/**
 * Отправка писем: доступ к вебинару и приглашение.
 * Доступно вошедшим пользователям (и админам). Требует настроенного SMTP.
 */
declare(strict_types=1);

require_once BASE_DIR . '/lib/email_templates.php';
require_once BASE_DIR . '/lib/mailer.php';
require_once BASE_DIR . '/lib/logs.php';

function handle_mail_action(string $action, string $method): void
{
    switch ($action) {

        /* Предпросмотр письма (HTML) — чтобы показать в модалке перед отправкой */
        case 'mail_preview':
            require_login();
            $b = $GLOBALS['__body'] ?? [];
            $type = (string)($b['type'] ?? 'invite');
            $w = webinar_find($b['webinar_id'] ?? null);
            if (!$w) json_response(['ok' => false, 'error' => 'Вебинар не найден.'], 404);
            $html = $type === 'access' ? build_access_email($w) : build_invite_email($w);
            $subject = $type === 'access' ? subject_access($w) : subject_invite($w);
            json_response(['ok' => true, 'html' => $html, 'subject' => $subject]);
            break;

        /* Отправка письма */
        case 'mail_send':
            require_login();
            // Защита от массовой рассылки: не более 20 писем за 10 минут на сессию
            if (!rate_limit('mail_send', 20, 600)) {
                json_response(['ok' => false, 'error' => 'Слишком много отправок. Повторите позже.'], 429);
            }
            $b = $GLOBALS['__body'] ?? [];
            $type = (string)($b['type'] ?? 'invite');
            // Проверка, что функция включена админом
            if ($type === 'access' && !feature_on('btn_access')) {
                json_response(['ok' => false, 'error' => 'Функция отправки доступа отключена.'], 403);
            }
            if ($type === 'invite' && !feature_on('btn_invite')) {
                json_response(['ok' => false, 'error' => 'Функция отправки приглашений отключена.'], 403);
            }
            $to = trim((string)($b['email'] ?? ''));
            if (!is_email($to)) json_response(['ok' => false, 'error' => 'Некорректный email получателя.'], 422);
            $w = webinar_find($b['webinar_id'] ?? null);
            if (!$w) json_response(['ok' => false, 'error' => 'Вебинар не найден.'], 404);

            if ($type === 'access') {
                $html = build_access_email($w);
                $subject = subject_access($w);
            } else {
                $html = build_invite_email($w);
                $subject = subject_invite($w);
            }
            $res = send_mail($to, '', $subject, $html);
            $by = (string)(current_user()['login'] ?? '');
            log_mail($by, $to, $type, $subject, $res['ok'], $res['ok'] ? '' : ($res['error'] ?? ''));
            if (!$res['ok']) json_response(['ok' => false, 'error' => $res['error']], 502);
            json_response(['ok' => true]);
            break;
    }
}

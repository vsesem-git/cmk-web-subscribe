<?php
/** Webinar access and invitation email actions. */
declare(strict_types=1);

require_once BASE_DIR . '/lib/email_templates.php';
require_once BASE_DIR . '/lib/mailer.php';
require_once BASE_DIR . '/lib/logs.php';

function handle_mail_action(string $action, string $method): void
{
    switch ($action) {
        case 'mail_preview':
            require_login();
            $body = $GLOBALS['__body'] ?? [];
            $type = (string)($body['type'] ?? 'invite');
            if (!in_array($type, ['access', 'invite'], true)) {
                json_response(['ok' => false, 'error' => 'Неизвестный тип письма.'], 422);
            }
            if ($type === 'access' && !feature_on('btn_access')) {
                json_response(['ok' => false, 'error' => 'Отправка доступа отключена.'], 403);
            }
            if ($type === 'invite' && !feature_on('btn_invite')) {
                json_response(['ok' => false, 'error' => 'Отправка приглашений отключена.'], 403);
            }
            $webinar = webinar_find_for_user($body['webinar_id'] ?? null, current_user());
            if (!$webinar) json_response(['ok' => false, 'error' => 'Вебинар не найден.'], 404);
            if (empty($webinar['link_participant'])) json_response(['ok' => false, 'error' => 'У вебинара нет безопасной ссылки для участия.'], 422);
            if ($type === 'access' && $webinar['date'] < date('Y-m-d')) json_response(['ok' => false, 'error' => 'Доступ нельзя отправить для прошедшего вебинара.'], 403);
            $html = $type === 'access' ? build_access_email($webinar) : build_invite_email($webinar);
            $subject = $type === 'access' ? subject_access($webinar) : subject_invite($webinar);
            json_response(['ok' => true, 'html' => $html, 'subject' => $subject]);
            break;

        case 'mail_send':
            require_login();
            if (!rate_limit('mail_send', 12, 600)) {
                json_response(['ok' => false, 'error' => 'Слишком много отправок. Повторите позже.'], 429);
            }
            $body = $GLOBALS['__body'] ?? [];
            $type = (string)($body['type'] ?? 'invite');
            if (!in_array($type, ['access', 'invite'], true)) {
                json_response(['ok' => false, 'error' => 'Неизвестный тип письма.'], 422);
            }
            if ($type === 'access' && !feature_on('btn_access')) {
                json_response(['ok' => false, 'error' => 'Функция отправки доступа отключена.'], 403);
            }
            if ($type === 'invite' && !feature_on('btn_invite')) {
                json_response(['ok' => false, 'error' => 'Функция отправки приглашений отключена.'], 403);
            }
            $recipient = trim((string)($body['email'] ?? ''));
            if (!is_email($recipient)) json_response(['ok' => false, 'error' => 'Некорректный email получателя.'], 422);
            $webinar = webinar_find_for_user($body['webinar_id'] ?? null, current_user());
            if (!$webinar) json_response(['ok' => false, 'error' => 'Вебинар не найден.'], 404);
            if (empty($webinar['link_participant'])) json_response(['ok' => false, 'error' => 'У вебинара нет безопасной ссылки для участия.'], 422);
            if ($type === 'access' && $webinar['date'] < date('Y-m-d')) json_response(['ok' => false, 'error' => 'Доступ нельзя отправить для прошедшего вебинара.'], 403);

            $html = $type === 'access' ? build_access_email($webinar) : build_invite_email($webinar);
            $subject = $type === 'access' ? subject_access($webinar) : subject_invite($webinar);
            $result = send_mail($recipient, '', $subject, $html);
            $by = (string)(current_user()['login'] ?? '');
            log_mail($by, $recipient, $type, $subject, $result['ok'], $result['ok'] ? '' : ($result['error'] ?? ''));
            if (!$result['ok']) json_response(['ok' => false, 'error' => $result['error']], 502);
            json_response(['ok' => true]);
            break;
    }
}

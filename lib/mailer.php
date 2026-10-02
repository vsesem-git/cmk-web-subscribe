<?php
/**
 * Отправка HTML-писем через SMTP (PHPMailer).
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

require_once BASE_DIR . '/vendor/PHPMailer/Exception.php';
require_once BASE_DIR . '/vendor/PHPMailer/PHPMailer.php';
require_once BASE_DIR . '/vendor/PHPMailer/SMTP.php';

/**
 * Отправить письмо. Возвращает [ok=>bool, error=>string].
 */
function send_mail(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): array
{
    $s = settings_get();
    $smtp = $s['smtp'] ?? [];

    $host = trim((string)($smtp['host'] ?? ''));
    $fromEmail = trim((string)($smtp['from_email'] ?? ''));
    $port = (int)($smtp['port'] ?? 465);
    $secure = strtolower((string)($smtp['secure'] ?? 'ssl'));
    if ($host === '' || $fromEmail === '') {
        return ['ok' => false, 'error' => 'SMTP не настроен. Заполните настройки в разделе «Настройки».'];
    }
    if (strlen($host) > 253 || preg_match('/[\\s\\r\\n\\x00]/', $host) || $port < 1 || $port > 65535
        || !in_array($secure, ['ssl', 'tls'], true) || !is_email($fromEmail)) {
        return ['ok' => false, 'error' => 'Некорректные параметры SMTP. Проверьте настройки почты.'];
    }
    if (!is_email($toEmail)) {
        return ['ok' => false, 'error' => 'Некорректный email получателя.'];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = !empty($smtp['user']);
        $mail->Username   = (string)($smtp['user'] ?? '');
        $mail->Password   = (string)($smtp['pass'] ?? '');
        $mail->Port       = $port;
        $mail->Timeout    = 10;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';
        $mail->SMTPSecure = $secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        // Строгая проверка сертификата (защита от MITM)
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
        ]];

        $fromName = preg_replace('/[\\r\\n\\x00-\\x1F\\x7F]/', ' ', (string)($smtp['from_name'] ?? MAIL_FROM_NAME));
        $mail->setFrom($fromEmail, substr($fromName ?: MAIL_FROM_NAME, 0, 120));
        $mail->addReplyTo((string)$smtp['from_email'], $fromName);
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: trim(strip_tags($htmlBody));

        $mail->send();
        return ['ok' => true];
    } catch (MailException $e) {
        error_log('SMTP delivery failed: ' . substr((string)$mail->ErrorInfo, 0, 500));
        return ['ok' => false, 'error' => 'Не удалось отправить письмо. Проверьте настройки SMTP или обратитесь к администратору.'];
    } catch (\Throwable $e) {
        error_log('Unexpected mailer failure: ' . substr($e->getMessage(), 0, 500));
        return ['ok' => false, 'error' => 'Не удалось отправить письмо. Проверьте настройки SMTP или обратитесь к администратору.'];
    }
}

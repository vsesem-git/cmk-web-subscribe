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

    if (empty($smtp['host']) || empty($smtp['from_email'])) {
        return ['ok' => false, 'error' => 'SMTP не настроен. Заполните настройки в разделе «Настройки».'];
    }
    if (!is_email($toEmail)) {
        return ['ok' => false, 'error' => 'Некорректный email получателя.'];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = (string)$smtp['host'];
        $mail->SMTPAuth   = !empty($smtp['user']);
        $mail->Username   = (string)($smtp['user'] ?? '');
        $mail->Password   = (string)($smtp['pass'] ?? '');
        $mail->Port       = (int)($smtp['port'] ?? 465);
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';

        $secure = strtolower((string)($smtp['secure'] ?? 'ssl'));
        if ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        // Строгая проверка сертификата (защита от MITM)
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
        ]];

        $fromName = (string)($smtp['from_name'] ?? MAIL_FROM_NAME);
        $mail->setFrom((string)$smtp['from_email'], $fromName);
        $mail->addReplyTo((string)$smtp['from_email'], $fromName);
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: trim(strip_tags($htmlBody));

        $mail->send();
        return ['ok' => true];
    } catch (MailException $e) {
        return ['ok' => false, 'error' => 'Ошибка отправки: ' . $mail->ErrorInfo];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Ошибка отправки письма.'];
    }
}

<?php
/**
 * Генераторы HTML-писем (совместимые с почтовыми клиентами: таблицы + inline-стили).
 */
declare(strict_types=1);

/** Русская дата "01 января 2026 г." из "2026-01-01". */
function ru_date(string $iso): string
{
    $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    $t = strtotime($iso);
    if (!$t) return $iso;
    return date('d', $t) . ' ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t) . ' г.';
}

/** Тема письма доступа: "Дата Лектор - Тема". */
function subject_access(array $w): string
{
    return trim(ru_date((string)$w['date']) . ' ' . ($w['speaker'] ?? '') . ' - ' . ($w['title'] ?? ''));
}

/** Тема приглашения. */
function subject_invite(array $w): string
{
    return 'Приглашение на вебинар: ' . ($w['title'] ?? '');
}

/** Общая обёртка письма. */
function email_wrapper(string $inner): string
{
    $brand = h(BRAND_SHORT);
    $full  = h(BRAND_FULL);
    return '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#0E1117;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 18px rgba(16,24,40,.08);">'
        // Шапка
        . '<tr><td style="background:linear-gradient(135deg,#0A5E5E 0%,#0E7B7B 55%,#5BA3A3 120%);padding:26px 32px;">'
        . '<div style="color:#fff;font-size:20px;font-weight:800;letter-spacing:.5px;">' . $brand . '</div>'
        . '<div style="color:rgba(255,255,255,.85);font-size:12px;margin-top:2px;">' . $full . '</div>'
        . '</td></tr>'
        // Тело
        . '<tr><td style="padding:32px;">' . $inner . '</td></tr>'
        // Подвал
        . '<tr><td style="padding:20px 32px;background:#F7F8FB;border-top:1px solid #E6E8EE;color:#6B7380;font-size:12px;line-height:1.6;">'
        . 'Это письмо отправлено сервисом «' . h(MAIL_FROM_NAME) . '». ' . $full . '.<br>'
        . 'Если вы не запрашивали доступ, просто проигнорируйте это сообщение.'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Кнопка (bulletproof-ish). */
function email_button(string $url, string $text, string $bg = '#0E7B7B'): string
{
    $url = h($url); $text = h($text);
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0;"><tr>'
        . '<td style="border-radius:10px;background:' . $bg . ';">'
        . '<a href="' . $url . '" target="_blank" style="display:inline-block;padding:13px 26px;'
        . 'font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">'
        . $text . '</a></td></tr></table>';
}

/**
 * Письмо с доступом к вебинару (по кнопке «Отправить доступ на email»).
 */
function build_access_email(array $w, ?array $creds = null): string
{
    $date = h(ru_date((string)$w['date']));
    $speaker = h((string)($w['speaker'] ?? ''));
    $title = h((string)($w['title'] ?? ''));
    $link = (string)($w['link_participant'] ?? '');
    $cabinet = CABINET_BASE . rawurlencode((string)($w['id'] ?? ''));
    $price = (int)($w['price'] ?? 0);

    $inner = '<h1 style="margin:0 0 8px;font-size:22px;color:#0E1117;">Доступ к вебинару</h1>'
        . '<p style="margin:0 0 20px;font-size:15px;color:#6B7380;">Здравствуйте! Ниже — ваши данные для участия в вебинаре.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#E4F1F1;border-radius:12px;padding:2px;margin-bottom:22px;">'
        . '<tr><td style="padding:18px 20px;">'
        . '<div style="font-size:13px;color:#0A5E5E;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">' . $date . '</div>'
        . '<div style="font-size:18px;font-weight:800;color:#0E1117;margin:6px 0 4px;line-height:1.3;">' . $title . '</div>'
        . '<div style="font-size:14px;color:#0E7B7B;font-weight:600;">Лектор: ' . $speaker . '</div>'
        . '</td></tr></table>';

    if ($creds) {
        $inner .= '<p style="margin:0 0 8px;font-size:14px;font-weight:700;">Данные для входа в личный кабинет:</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #E6E8EE;border-radius:10px;margin-bottom:20px;">'
            . '<tr><td style="padding:10px 16px;border-bottom:1px solid #E6E8EE;font-size:14px;">Логин: <b style="font-family:monospace;">' . h($creds['login']) . '</b></td></tr>'
            . '<tr><td style="padding:10px 16px;font-size:14px;">Пароль: <b style="font-family:monospace;">' . h($creds['password']) . '</b></td></tr>'
            . '</table>';
    }

    $inner .= email_button($link, 'Смотреть вебинар')
        . email_button($cabinet, 'Личный кабинет', '#5D4037')
        . '<p style="margin:18px 0 0;font-size:13px;color:#6B7380;">Подробнее: <a href="' . h($link) . '" style="color:#0E7B7B;">' . h($link) . '</a></p>';

    return email_wrapper($inner);
}

/**
 * Письмо-приглашение на вебинар.
 * Обязательные элементы: «Вы приглашены на вебинар {дата}, лектор {speaker}, {title}»,
 * «Подробнее: {link_participant}», ссылка на кабинет.
 */
function build_invite_email(array $w): string
{
    $date = h(ru_date((string)$w['date']));
    $speaker = h((string)($w['speaker'] ?? ''));
    $title = h((string)($w['title'] ?? ''));
    $link = (string)($w['link_participant'] ?? '');
    $cabinet = CABINET_BASE . rawurlencode((string)($w['id'] ?? ''));

    $inner = '<div style="display:inline-block;background:#FCEAF1;color:#A8285A;font-size:12px;font-weight:800;'
        . 'padding:6px 14px;border-radius:999px;letter-spacing:.5px;margin-bottom:14px;">ПРИГЛАШЕНИЕ НА ВЕБИНАР</div>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.25;color:#0E1117;">Вы приглашены на вебинар</h1>'
        . '<p style="margin:0 0 22px;font-size:16px;line-height:1.6;color:#0E1117;">'
        . 'Приглашаем вас принять участие в вебинаре <b>' . $date . '</b>.<br>'
        . 'Лектор: <b>' . $speaker . '</b>.'
        . '</p>'
        // Карточка темы
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:linear-gradient(160deg,#E4F1F1,#ffffff);border:1px solid #C9E3E3;border-radius:14px;margin-bottom:24px;">'
        . '<tr><td style="padding:22px 24px;">'
        . '<div style="font-size:13px;color:#0A5E5E;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Тема</div>'
        . '<div style="font-size:19px;font-weight:800;color:#0E1117;line-height:1.35;">' . $title . '</div>'
        . '<div style="margin-top:14px;font-size:14px;color:#0E7B7B;font-weight:600;">📅 ' . $date . ' &nbsp;·&nbsp; 🎓 ' . $speaker . '</div>'
        . '</td></tr></table>'
        // Что вы получите
        . '<p style="margin:0 0 10px;font-size:15px;font-weight:700;color:#0E1117;">На вебинаре вы:</p>'
        . '<ul style="margin:0 0 22px;padding-left:20px;font-size:14px;color:#0E1117;line-height:1.7;">'
        . '<li>разберёте актуальные изменения в законодательстве и практике;</li>'
        . '<li>получите ответы на вопросы от эксперта в прямом эфире;</li>'
        . '<li>сможете задать свой вопрос и получить материалы.</li>'
        . '</ul>'
        // CTA
        . email_button($link, 'Принять участие')
        . email_button($cabinet, 'Открыть личный кабинет', '#5D4037')
        . '<p style="margin:20px 0 0;font-size:13px;color:#6B7380;line-height:1.6;">'
        . 'Подробнее: <a href="' . h($link) . '" style="color:#0E7B7B;">' . h($link) . '</a><br>'
        . 'Личный кабинет: <a href="' . h($cabinet) . '" style="color:#0E7B7B;">' . h($cabinet) . '</a>'
        . '</p>';

    return email_wrapper($inner);
}

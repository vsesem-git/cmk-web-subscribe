<?php
/**
 * Логирование в JSON Lines (по одной записи-строке на событие).
 * Файлы: login.jsonl, views.jsonl, mail.jsonl в data/logs/.
 */
declare(strict_types=1);

/** Клиентский IP с учётом прокси (осторожно: доверять только если за доверенным прокси). */
function client_ip(): string
{
    // При необходимости включите доверие к прокси, раскомментировав X-Forwarded-For
    // if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    //     return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    // }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** Добавить запись в лог (append, с блокировкой). */
function log_append(string $file, array $entry): void
{
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0750, true);
    $entry['ts'] = date('c'); // ISO-8601
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/** Прочитать лог целиком (массив записей). Ограничение — на всякий случай. */
function log_read(string $file, int $limit = 5000): array
{
    if (!is_file($file)) return [];
    $out = [];
    $fp = fopen($file, 'rb');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    while (($l = fgets($fp)) !== false) {
        $l = trim($l);
        if ($l === '') continue;
        $d = json_decode($l, true);
        if (is_array($d)) $out[] = $d;
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    if (count($out) > $limit) $out = array_slice($out, -$limit);
    return $out;
}

/* ---- Событийные помощники (учитывают тумблеры функций) ---- */
function log_login(string $login, bool $ok, string $reason = ''): void
{
    if (!feature_on('log_login')) return;
    log_append(CMK_LOG_LOGIN, [
        'login' => $login, 'ok' => $ok, 'reason' => $reason,
        'ip' => client_ip(), 'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
    ]);
}

function log_view(string $login, $webinarId, string $title, string $type = 'watch'): void
{
    if (!feature_on('log_views')) return;
    log_append(CMK_LOG_VIEWS, [
        'login' => $login, 'webinar_id' => $webinarId, 'title' => $title,
        'type' => $type, 'ip' => client_ip(),
    ]);
}

function log_mail(string $byLogin, string $to, string $mailType, string $subject, bool $ok, string $error = ''): void
{
    if (!feature_on('log_mail')) return;
    log_append(CMK_LOG_MAIL, [
        'by' => $byLogin, 'to' => $to, 'mail_type' => $mailType,
        'subject' => $subject, 'ok' => $ok, 'error' => $error, 'ip' => client_ip(),
    ]);
}

/* ---- Авто-очистка (ротация) логов ---- */

/** Очистить один файл лога по правилам: старше N дней ИЛИ больше N строк. */
function log_prune_file(string $file, int $days, int $maxLines): int
{
    if (!is_file($file)) return 0;
    $rows = log_read($file, PHP_INT_MAX);
    $before = count($rows);
    if ($days > 0) {
        $cutoff = time() - $days * 86400;
        $rows = array_values(array_filter($rows, function ($r) use ($cutoff) {
            $t = strtotime($r['ts'] ?? '');
            return $t === false || $t >= $cutoff;
        }));
    }
    if ($maxLines > 0 && count($rows) > $maxLines) {
        $rows = array_slice($rows, -$maxLines);
    }
    $removed = $before - count($rows);
    if ($removed > 0) {
        $out = '';
        foreach ($rows as $r) {
            $out .= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
        @file_put_contents($file, $out, LOCK_EX);
    }
    return $removed;
}

/** Прогнать очистку по всем логам. Возвращает [файл => удалено]. */
function log_prune_all(int $days, int $maxLines): array
{
    return [
        'login' => log_prune_file(CMK_LOG_LOGIN, $days, $maxLines),
        'views' => log_prune_file(CMK_LOG_VIEWS, $days, $maxLines),
        'mail'  => log_prune_file(CMK_LOG_MAIL,  $days, $maxLines),
    ];
}

/**
 * Ленивая авто-очистка: запускается не чаще раза в сутки (метка в файле),
 * без cron. Вызывается на старте API.
 */
function log_maybe_autoprune(): void
{
    $s = settings_get();
    $r = $s['log_retention'] ?? [];
    if (empty($r['enabled'])) return;
    $stamp = LOG_DIR . '/.last_prune';
    $last = is_file($stamp) ? (int)@file_get_contents($stamp) : 0;
    if (time() - $last < 86400) return;      // не чаще 1 раза в сутки
    if (!is_dir(LOG_DIR)) return;
    log_prune_all((int)($r['days'] ?? 0), (int)($r['max_lines'] ?? 0));
    @file_put_contents($stamp, (string)time(), LOCK_EX);
}

/** Обновить в users.json поля последнего входа. */
function user_touch_login(string $login): void
{
    $users = users_all();
    foreach ($users as &$u) {
        if (($u['login'] ?? '') === $login) {
            $u['last_login'] = date('c');
            $u['last_ip'] = client_ip();
            $u['login_count'] = (int)($u['login_count'] ?? 0) + 1;
            break;
        }
    }
    unset($u);
    users_save($users);
}

/** Сводка просмотров пользователя: [{webinar_id,title,count,last}]. */
function user_view_summary(string $login): array
{
    $agg = [];
    foreach (log_read(CMK_LOG_VIEWS) as $r) {
        if (($r['login'] ?? '') !== $login) continue;
        $id = (string)($r['webinar_id'] ?? '');
        if (!isset($agg[$id])) {
            $agg[$id] = ['webinar_id' => $r['webinar_id'] ?? '', 'title' => $r['title'] ?? '', 'count' => 0, 'last' => ''];
        }
        $agg[$id]['count']++;
        if (($r['ts'] ?? '') > $agg[$id]['last']) $agg[$id]['last'] = $r['ts'] ?? '';
        if (!empty($r['title'])) $agg[$id]['title'] = $r['title'];
    }
    $list = array_values($agg);
    usort($list, fn($a, $b) => strcmp($b['last'], $a['last']));
    return $list;
}

<?php
/** JSON Lines logging with bounded reads, locking and retention. */
declare(strict_types=1);

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function log_append(string $file, array $entry): void
{
    if (!is_dir(LOG_DIR) && !@mkdir(LOG_DIR, 0700, true) && !is_dir(LOG_DIR)) return;
    $entry['ts'] = date('c');
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) return;
    @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    @chmod($file, 0600);
}

/** Read at most the newest $limit valid records. */
function log_read(string $file, int $limit = 5000): array
{
    if (!is_file($file) || $limit < 1) return [];
    $fp = @fopen($file, 'rb');
    if (!$fp) return [];
    if (!flock($fp, LOCK_SH)) { fclose($fp); return []; }
    $out = [];
    while (($line = fgets($fp)) !== false) {
        $data = json_decode(trim($line), true);
        if (!is_array($data)) continue;
        $out[] = $data;
        if (count($out) > $limit) array_shift($out);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $out;
}

function log_login(string $login, bool $ok, string $reason = ''): void
{
    if (!feature_on('log_login')) return;
    log_append(CMK_LOG_LOGIN, [
        'login' => substr($login, 0, 80), 'ok' => $ok, 'reason' => substr($reason, 0, 80),
        'ip' => client_ip(), 'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
    ]);
}

function log_view(string $login, $webinarId, string $title, string $type = 'watch'): void
{
    if (!feature_on('log_views')) return;
    log_append(CMK_LOG_VIEWS, [
        'login' => substr($login, 0, 80), 'webinar_id' => $webinarId,
        'title' => substr($title, 0, 1200), 'type' => substr($type, 0, 30), 'ip' => client_ip(),
    ]);
}

function log_mail(string $byLogin, string $to, string $mailType, string $subject, bool $ok, string $error = ''): void
{
    if (!feature_on('log_mail')) return;
    log_append(CMK_LOG_MAIL, [
        'by' => substr($byLogin, 0, 80), 'to' => substr($to, 0, 254),
        'mail_type' => substr($mailType, 0, 30), 'subject' => substr($subject, 0, 300),
        'ok' => $ok, 'error' => substr($error, 0, 500), 'ip' => client_ip(),
    ]);
}

/** Remove expired/over-limit lines while holding an exclusive lock throughout the rewrite. */
function log_prune_file(string $file, int $days, int $maxLines): int
{
    if (!is_file($file)) return 0;
    $fp = @fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) { if (is_resource($fp)) fclose($fp); return 0; }

    rewind($fp);
    $records = [];
    $before = 0;
    $cutoff = $days > 0 ? time() - $days * 86400 : null;
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $record = json_decode($line, true);
        if (!is_array($record)) continue;
        $before++;
        $timestamp = strtotime((string)($record['ts'] ?? ''));
        if ($cutoff !== null && $timestamp !== false && $timestamp < $cutoff) continue;
        $records[] = $record;
    }
    if ($maxLines > 0 && count($records) > $maxLines) $records = array_slice($records, -$maxLines);
    $removed = max(0, $before - count($records));

    if ($removed > 0) {
        rewind($fp);
        if (ftruncate($fp, 0)) {
            foreach ($records as $record) {
                $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json !== false) fwrite($fp, $json . "\n");
            }
            fflush($fp);
        }
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $removed;
}

function log_prune_all(int $days, int $maxLines): array
{
    return [
        'login' => log_prune_file(CMK_LOG_LOGIN, $days, $maxLines),
        'views' => log_prune_file(CMK_LOG_VIEWS, $days, $maxLines),
        'mail' => log_prune_file(CMK_LOG_MAIL, $days, $maxLines),
    ];
}

/** Lazy daily maintenance, synchronized so parallel requests cannot lose log writes. */
function log_maybe_autoprune(): void
{
    $retention = settings_get()['log_retention'] ?? [];
    if (empty($retention['enabled']) || !is_dir(LOG_DIR)) return;
    $stamp = LOG_DIR . '/.last_prune';
    $fp = @fopen($stamp, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) { if (is_resource($fp)) fclose($fp); return; }
    rewind($fp);
    $last = (int)stream_get_contents($fp);
    if (time() - $last >= 86400) {
        log_prune_all((int)($retention['days'] ?? 0), (int)($retention['max_lines'] ?? 0));
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, (string)time());
        fflush($fp);
        @chmod($stamp, 0600);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
}

function user_touch_login(string $login): void
{
    $users = users_all();
    foreach ($users as &$user) {
        if (($user['login'] ?? '') === $login) {
            $user['last_login'] = date('c');
            $user['last_ip'] = client_ip();
            $user['login_count'] = (int)($user['login_count'] ?? 0) + 1;
            break;
        }
    }
    unset($user);
    users_save($users);
}

function user_view_summary(string $login): array
{
    $aggregate = [];
    foreach (log_read(CMK_LOG_VIEWS) as $record) {
        if (($record['login'] ?? '') !== $login) continue;
        $id = (string)($record['webinar_id'] ?? '');
        if (!isset($aggregate[$id])) {
            $aggregate[$id] = ['webinar_id' => $record['webinar_id'] ?? '', 'title' => $record['title'] ?? '', 'count' => 0, 'last' => ''];
        }
        $aggregate[$id]['count']++;
        if (($record['ts'] ?? '') > $aggregate[$id]['last']) $aggregate[$id]['last'] = $record['ts'] ?? '';
        if (!empty($record['title'])) $aggregate[$id]['title'] = $record['title'];
    }
    $list = array_values($aggregate);
    usort($list, function ($a, $b) { return strcmp((string)$b['last'], (string)$a['last']); });
    return $list;
}

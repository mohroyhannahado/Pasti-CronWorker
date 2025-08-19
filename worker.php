<?php
declare(strict_types=1);
/*
	https://github.com/mohroyhannahado/Pasti-CronWorker
*/


/**
 * Pasti CronWorker (aman & robust)
 * - Jalankan via cron setiap 20 detik (lihat crontab).
 * - Global flock: cegah overlap worker.
 * - Per-task flock: cegah task ganda.
 * - Args aman: support JSON array (tiap arg di-escapeshellarg).
 * - Fallback mb_substr -> substr.
 * - Retry dengan exponential backoff (jika gagal & retries < max_retries).
 * - Auto-reset status 'running' yang nyangkut > 30 menit.
 */

require __DIR__ . '/db.php';

// ============== Konfigurasi dasar ==============
$PHP_CLI   = '/usr/bin/php';
$TIMEZONE  = 'Asia/Jakarta';
$LOCK_DIR  = '/tmp';
$GLOBAL_LOCK = $LOCK_DIR . '/pasti_cronworker.lock';
$TASK_LOCK_PREFIX = $LOCK_DIR . '/pasti_cron_task_';
$MAX_TASKS_PER_TICK = 10;

date_default_timezone_set($TIMEZONE);

// ============== Global flock ==============
$gfp = fopen($GLOBAL_LOCK, 'c');
if ($gfp === false) {
    fwrite(STDERR, "Cannot open global lock file\n");
    exit(1);
}
if (!flock($gfp, LOCK_EX | LOCK_NB)) {
    // masih ada worker lain
    exit(0);
}

// ============== Helpers ==============
function str_ends_with_php8(string $haystack, string $needle): bool {
    $len = strlen($needle);
    if ($len === 0) return true;
    return (substr($haystack, -$len) === $needle);
}

function validate_cmd(string $cmd): void {
    if (!str_ends_with_php8($cmd, '.php')) {
        throw new RuntimeException("CMD harus file .php: {$cmd}");
    }
    if (!file_exists($cmd)) {
        throw new RuntimeException("File tidak ditemukan: {$cmd}");
    }
    if (!is_readable($cmd)) {
        throw new RuntimeException("File tidak bisa dibaca: {$cmd}");
    }
}

function nowDT(): DateTime { return new DateTime('now'); }

function compute_next_run(array $task, DateTime $base): DateTime {
    if ($task['schedule_type'] === 'interval') {
        $sec = max(1, (int)$task['interval_sec']);
        $n = clone $base;
        $n->modify("+{$sec} seconds");
        return $n;
    }
    if ($task['schedule_type'] === 'daily') {
        $parts = explode(':', (string)$task['daily_time']);
        $h = (int)($parts[0] ?? 0);
        $m = (int)($parts[1] ?? 0);
        $today = new DateTime('today');
        $cand = (clone $today)->setTime($h, $m, 0);
        if ($cand <= $base) $cand->modify('+1 day');
        return $cand;
    }
    $fb = clone $base; $fb->modify('+300 seconds'); return $fb;
}

/** Retry backoff: 30s * 2^retries (maks 15 menit) */
function next_retry_time(DateTime $base, int $retries): DateTime {
    $sec = min(900, 30 * (2 ** max(0, $retries)));
    $n = clone $base; $n->modify("+{$sec} seconds"); return $n;
}

/** Jalankan PHP script dengan timeout, capture stdout+stderr, aman untuk args JSON array */
function run_php_script(string $phpCli, string $script, ?string $args, int $timeoutSec): array {
    $cmd = escapeshellcmd($phpCli) . ' ' . escapeshellarg($script);

    if ($args !== null && $args !== '') {
        $decoded = json_decode($args, true);
        if (is_array($decoded)) {
            foreach ($decoded as $a) {
                $cmd .= ' ' . escapeshellarg((string)$a);
            }
        } else {
            // fallback: seluruh string jadi satu arg
            $cmd .= ' ' . escapeshellarg($args);
        }
    }

    $descriptors = [
        0 => ['pipe','r'],
        1 => ['pipe','w'],
        2 => ['pipe','w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException("Gagal menjalankan: {$cmd}");
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    fclose($pipes[0]);

    $out = ''; $err = '';
    $start = time(); $exit = null;

    while (true) {
        $read = [$pipes[1], $pipes[2]]; $w = $e = [];
        @stream_select($read, $w, $e, 1);
        foreach ($read as $r) {
            $chunk = fread($r, 8192);
            if ($r === $pipes[1]) $out .= $chunk ?: '';
            if ($r === $pipes[2]) $err .= $chunk ?: '';
        }
        $st = proc_get_status($proc);
        if (!$st['running']) { $exit = $st['exitcode']; break; }
        if ((time() - $start) >= $timeoutSec) {
            proc_terminate($proc);
            sleep(2);
            $st = proc_get_status($proc);
            if ($st['running']) proc_terminate($proc, 9);
            $exit = 124;
            $err .= "\n[timeout] proses > {$timeoutSec} detik";
            break;
        }
    }
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    proc_close($proc);

    $combined = trim($out . (strlen($err) ? "\n[stderr]\n".$err : ''));
    return [$exit, $combined];
}

function mb_sub($s, $n) {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $n);
    return substr($s, 0, $n);
}

// ============== Main loop ==============
$db = new DB();

// Auto-reset task yang 'running' terlalu lama (30 menit)
$db->exec(
  "UPDATE cron_tasks
   SET status='failed',
       last_output=CONCAT(IFNULL(last_output,''), '\n[worker] auto-reset stale running'),
       next_run_at=NOW(),
       updated_at=NOW()
   WHERE status='running' AND last_run_at < (NOW() - INTERVAL 30 MINUTE)"
);

// Ambil due tasks
$tasks = $db->fetchAll(
  "SELECT * FROM cron_tasks
   WHERE enabled=1
     AND next_run_at <= NOW()
     AND (status IS NULL OR status IN ('idle','done','failed'))
   ORDER BY next_run_at ASC
   LIMIT ?",
  'i',
  [$MAX_TASKS_PER_TICK]
);

foreach ($tasks as $t) {
    $id = (int)$t['id'];
    $taskLock = $TASK_LOCK_PREFIX . $id . '.lock';
    $tfp = fopen($taskLock, 'c');
    if ($tfp === false) continue;
    if (!flock($tfp, LOCK_EX | LOCK_NB)) { fclose($tfp); continue; }

    try {
        // mark running
        $db->exec("UPDATE cron_tasks SET status='running', last_run_at=NOW(), updated_at=NOW() WHERE id=?", 'i', [$id]);

        validate_cmd($t['cmd']);

        [$code, $out] = run_php_script(
            $PHP_CLI,
            $t['cmd'],
            $t['args'] ?? null,
            (int)$t['run_timeout_sec']
        );

        $now = nowDT();

        if ((int)$code === 0) {
            $next = compute_next_run($t, $now)->format('Y-m-d H:i:s');
            $db->exec(
                "UPDATE cron_tasks
                 SET status='done',
                     last_exit_code=?,
                     last_output=?,
                     next_run_at=?,
                     retries=0,
                     updated_at=NOW()
                 WHERE id=?",
                'issi',
                [0, mb_sub($out, 1000000), $next, $id]
            );
        } else {
            // gagal: retry jika masih ada jatah
            $retries = (int)$t['retries'];
            $maxr    = (int)$t['max_retries'];
            if ($retries < $maxr) {
                $nr = next_retry_time($now, $retries);
                $db->exec(
                    "UPDATE cron_tasks
                     SET status='failed',
                         last_exit_code=?,
                         last_output=?,
                         next_run_at=?,
                         retries=retries+1,
                         updated_at=NOW()
                     WHERE id=?",
                    'issi',
                    [$code, mb_sub($out, 1000000), $nr->format('Y-m-d H:i:s'), $id]
                );
            } else {
                // habis jatah retry -> jadwalkan sesuai jadwal normal berikutnya
                $next = compute_next_run($t, $now)->format('Y-m-d H:i:s');
                $db->exec(
                    "UPDATE cron_tasks
                     SET status='failed',
                         last_exit_code=?,
                         last_output=?,
                         next_run_at=?,
                         retries=0,
                         updated_at=NOW()
                     WHERE id=?",
                    'issi',
                    [$code, mb_sub($out, 1000000), $next, $id]
                );
            }
        }
    } catch (Throwable $e) {
        $msg = "[worker error] ".$e->getMessage();
        $now = nowDT();
        $retryNext = next_retry_time($now, (int)$t['retries'])->format('Y-m-d H:i:s');
        $db->exec(
            "UPDATE cron_tasks
             SET status='failed',
                 last_exit_code=1,
                 last_output=?,
                 next_run_at=?,
                 retries=LEAST(retries+1, max_retries),
                 updated_at=NOW()
             WHERE id=?",
            'ssi',
            [mb_sub($msg, 1000000), $retryNext, $id]
        );
    } finally {
        flock($tfp, LOCK_UN);
        fclose($tfp);
    }
}
// lepas global lock
flock($gfp, LOCK_UN);
fclose($gfp);

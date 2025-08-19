<?php
declare(strict_types=1);
/*
	https://github.com/mohroyhannahado/Pasti-CronWorker
*/


/**
 * Pasti CronWorker — index.php
 * - CSRF
 * - Validasi cmd & waktu
 * - Interval: value + unit (detik/menit/jam/hari)
 * - next_run_at: default NOW() saat create; tidak muncul di form
 */

session_start();
require __DIR__ . '/db.php';
date_default_timezone_set('Asia/Jakarta');

$db = new DB();

// ===== helpers
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function ensure_csrf(): void { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
            http_response_code(400);
            exit('Invalid CSRF token');
        }
    }
}

function validate_daily_time(?string $t): bool {
    if ($t === null || $t === '') return false;
    if (!preg_match('/^\d{2}:\d{2}$/', $t)) return false;
    [$hh, $mm] = array_map('intval', explode(':', $t));
    return $hh>=0 && $hh<=23 && $mm>=0 && $mm<=59;
}

function validate_cmd_path(string $cmd): void {
    if (!preg_match('/\.php$/', $cmd)) throw new RuntimeException('CMD harus file .php');
    if (!file_exists($cmd)) throw new RuntimeException('File CMD tidak ditemukan di server');
    if (!is_readable($cmd)) throw new RuntimeException('File CMD tidak bisa dibaca');
}

function get_task(int $id, DB $db): ?array {
    $r = $db->fetchAll("SELECT * FROM cron_tasks WHERE id=?", 'i', [$id]);
    return $r[0] ?? null;
}

ensure_csrf();
verify_csrf();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$errors = [];

$unitsMap = [1=>'detik', 60=>'menit', 3600=>'jam', 86400=>'hari'];

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create' || $action === 'update') {
            $isUpdate = ($action === 'update');
            $id = (int)($_POST['id'] ?? 0);

            if ($isUpdate) {
                $exist = get_task($id, $db);
                if (!$exist) throw new RuntimeException('Task tidak ditemukan');
            }

            $name          = trim($_POST['name'] ?? '');
            $cmd           = trim($_POST['cmd'] ?? '');
            $args          = trim($_POST['args'] ?? '');
            $schedule_type = $_POST['schedule_type'] ?? '';
            $interval_value= (int)($_POST['interval_value'] ?? 0);
            $interval_unit = (int)($_POST['interval_unit'] ?? 1);
            $interval_unit = in_array($interval_unit, array_keys($unitsMap), true) ? $interval_unit : 1;
            $interval_sec  = max(0, $interval_value * $interval_unit);
            $daily_time    = trim($_POST['daily_time'] ?? '');
            $run_timeout   = max(5, (int)($_POST['run_timeout_sec'] ?? 600));
            $enabled       = isset($_POST['enabled']) ? 1 : 0;
            $max_retries   = max(0, (int)($_POST['max_retries'] ?? 0));

            if ($name === '') $errors[] = 'Nama wajib diisi';
            if ($cmd === '')  $errors[] = 'CMD wajib diisi';
            if (!in_array($schedule_type, ['interval','daily'], true)) $errors[] = 'Schedule type tidak valid';

            if ($schedule_type === 'interval') {
                if ($interval_sec < 1) $errors[] = 'Interval minimal 1 detik';
            } else {
                if (!validate_daily_time($daily_time)) $errors[] = 'Daily time harus format HH:MM';
            }

            // validasi cmd path (boleh dimatikan kalau file belum ada)
            try { validate_cmd_path($cmd); } catch (Throwable $e) { $errors[] = $e->getMessage(); }

            if (!$errors) {
                if ($isUpdate) {
                    if ($schedule_type === 'interval') {
						$db->exec(
						  "UPDATE cron_tasks
						   SET name=?, cmd=?, args=?, schedule_type=?, interval_sec=?, interval_unit=?, daily_time=NULL,
							   enabled=?, run_timeout_sec=?, max_retries=?, updated_at=NOW()
						   WHERE id=?",
						  'ssssiiiiii',
						  [$name, $cmd, $args, $schedule_type, $interval_sec, $interval_unit, $enabled, $run_timeout, $max_retries, $id]
						);
                    } else {
						$db->exec(
						  "UPDATE cron_tasks
						   SET name=?, cmd=?, args=?, schedule_type=?, interval_sec=NULL, interval_unit=1, daily_time=?,
							   enabled=?, run_timeout_sec=?, max_retries=?, updated_at=NOW()
						   WHERE id=?",
						  'sssssiiii',
						  [$name, $cmd, $args, $schedule_type, $daily_time, $enabled, $run_timeout, $max_retries, $id]
						);
                    }
                    $_SESSION['flash'] = 'Task berhasil diperbarui.';
                    header('Location: '.$_SERVER['PHP_SELF']);
                    exit;
                } else {
                    if ($schedule_type === 'interval') {
						$db->exec(
						  "INSERT INTO cron_tasks
						   (name, cmd, args, schedule_type, interval_sec, interval_unit, enabled, next_run_at, run_timeout_sec, max_retries, status)
						   VALUES (?,?,?,?,?,?,?,NOW(),?,?, 'idle')",
						  'ssssiiiii',
						  [$name, $cmd, $args, $schedule_type, $interval_sec, $interval_unit, $enabled, $run_timeout, $max_retries]
						);

                    } else {
						$db->exec(
						  "INSERT INTO cron_tasks
						   (name, cmd, args, schedule_type, daily_time, enabled, next_run_at, run_timeout_sec, max_retries, status)
						   VALUES (?,?,?,?,?,?,NOW(),?,?, 'idle')",
						  'sssssiii',
						  [$name, $cmd, $args, $schedule_type, $daily_time, $enabled, $run_timeout, $max_retries]
						);

                    }
                    $_SESSION['flash'] = 'Task berhasil ditambahkan.';
                    header('Location: '.$_SERVER['PHP_SELF']);
                    exit;
                }
            }
        }
        elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $db->exec("DELETE FROM cron_tasks WHERE id=?", 'i', [$id]);
            $_SESSION['flash'] = 'Task dihapus.';
            header('Location: '.$_SERVER['PHP_SELF']); exit;
        }
        elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $t = get_task($id, $db); if ($t) {
                $new = $t['enabled'] ? 0 : 1;
                $db->exec("UPDATE cron_tasks SET enabled=?, updated_at=NOW() WHERE id=?", 'ii', [$new, $id]);
                $_SESSION['flash'] = $new ? 'Task diaktifkan.' : 'Task dinonaktifkan.';
            }
            header('Location: '.$_SERVER['PHP_SELF']); exit;
        }
        elseif ($action === 'run_now') {
            $id = (int)($_POST['id'] ?? 0);
            $db->exec("UPDATE cron_tasks SET next_run_at=NOW(), status='idle', updated_at=NOW() WHERE id=?", 'i', [$id]);
            $_SESSION['flash'] = 'Task dijadwalkan segera.';
            header('Location: '.$_SERVER['PHP_SELF']); exit;
        }
        elseif ($action === 'clear_output') {
            $id = (int)($_POST['id'] ?? 0);
            $db->exec("UPDATE cron_tasks SET last_output=NULL WHERE id=?", 'i', [$id]);
            $_SESSION['flash'] = 'Output dibersihkan.';
            header('Location: '.$_SERVER['PHP_SELF'].'?edit='.$id); exit;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// data
$editId   = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editTask = $editId ? get_task($editId, $db) : null;
$tasks    = $db->fetchAll("SELECT * FROM cron_tasks ORDER BY enabled DESC, next_run_at ASC, id DESC");
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Pasti CronWorker — Task Manager</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;line-height:1.45;margin:20px;background:#f8fafc;color:#0f172a}
 h1{font-size:20px;margin:0 0 12px}
 .wrap{display:grid;grid-template-columns:1.1fr 1.6fr;gap:18px}
 @media (max-width:960px){.wrap{grid-template-columns:1fr}}
 .card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
 label{display:block;margin:8px 0 4px;font-weight:600}
 input[type=text],input[type=number],select,textarea{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}
 .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
 .muted{color:#475569;font-size:12px}
 .btn{display:inline-block;border:1px solid #1e293b;background:#0ea5e9;color:#fff;padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:600}
 .btn.sec{background:#64748b;border-color:#475569}
 .btn.warn{background:#ef4444;border-color:#dc2626}
 .btn.gray{background:#94a3b8;border-color:#64748b}
 table{width:100%;border-collapse:collapse}
 th,td{border-bottom:1px solid #e2e8f0;padding:8px 6px;text-align:left;font-size:14px;vertical-align:top}
 th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#475569}
 .good{color:#16a34a;font-weight:600}
 .bad{color:#dc2626;font-weight:600}
 .mono{font-family:ui-monospace,Menlo,Consolas,monospace}
 .flash{margin:10px 0;padding:10px;border-radius:8px;background:#ecfeff;border:1px solid #7dd3fc}
 .errors{margin:10px 0;padding:10px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca}
 .small{font-size:12px}
 details summary{cursor:pointer}
</style>
<script>
function onSchedTypeChange(){
  var sel=document.getElementById('schedule_type');
  var isInterval=sel.value==='interval';
  document.getElementById('interval_wrap').style.display=isInterval?'block':'none';
  document.getElementById('daily_wrap').style.display=isInterval?'none':'block';
}
document.addEventListener('DOMContentLoaded', onSchedTypeChange);
</script>
</head>
<body>
<h1>🛠️ Pasti CronWorker — Task Manager</h1>

<?php if ($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="errors"><strong>Terjadi kesalahan:</strong><ul><?php foreach($errors as $e){echo '<li>'.h($e).'</li>'; } ?></ul></div>
<?php endif; ?>

<div class="wrap">
  <div class="card">
    <h2><?= $editTask ? 'Edit Task' : 'Tambah Task' ?></h2>
    <form method="post" action="?action=<?= $editTask ? 'update' : 'create' ?>">
      <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
      <?php if ($editTask): ?><input type="hidden" name="id" value="<?= (int)$editTask['id'] ?>"><?php endif; ?>

      <label>Nama</label>
      <input type="text" name="name" required value="<?=h($editTask['name'] ?? '')?>">

      <label>CMD (path PHP di server)</label>
      <input type="text" name="cmd" required class="mono" placeholder="/var/www/shopee.php" value="<?=h($editTask['cmd'] ?? '')?>">
      <div class="muted small">Harus file <code>.php</code> yang ada di server.</div>

      <label>Args (opsional)</label>
      <input type="text" name="args" class="mono" placeholder='contoh JSON: ["--foo=bar","keyword test"]' value="<?=h($editTask['args'] ?? '')?>">
      <div class="muted small">Boleh string biasa (jadi 1 argumen) atau JSON array (tiap item jadi 1 argumen, lebih aman).</div>

      <div class="row">
        <div>
          <label>Schedule Type</label>
          <select name="schedule_type" id="schedule_type" onchange="onSchedTypeChange()">
            <?php $st=$editTask['schedule_type']??'interval'; ?>
            <option value="interval" <?= $st==='interval'?'selected':''; ?>>interval</option>
            <option value="daily"    <?= $st==='daily'?'selected':'';    ?>>daily (HH:MM WIB)</option>
          </select>
        </div>
      </div>

      <div id="interval_wrap">
        <label>Interval</label>
        <?php
          $eu = (int)($editTask['interval_unit'] ?? 60);
          if (!in_array($eu, array_keys($unitsMap), true)) $eu = 60;
          $iv = (int)max(1, (int)round(((int)($editTask['interval_sec'] ?? 60)) / max(1,$eu)));
        ?>
        <div style="display:flex;gap:6px;align-items:center">
          <input type="number" name="interval_value" min="1" step="1" value="<?=h((string)$iv)?>">
          <select name="interval_unit">
            <?php foreach($unitsMap as $u=>$label): ?>
              <option value="<?=$u?>" <?= $eu===$u?'selected':''; ?>><?=$label?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="daily_wrap" style="display:none">
        <label>Daily time (HH:MM)</label>
        <input type="text" name="daily_time" placeholder="21:00" value="<?=h($editTask['daily_time'] ?? '21:00')?>">
      </div>

      <div class="row">
        <div>
          <label>Timeout (detik)</label>
          <input type="number" name="run_timeout_sec" min="5" step="1" value="<?=h((string)($editTask['run_timeout_sec'] ?? 600))?>">
        </div>
        <div>
          <label>Max Retries</label>
          <input type="number" name="max_retries" min="0" step="1" value="<?=h((string)($editTask['max_retries'] ?? 0))?>">
        </div>
      </div>

      <label><input type="checkbox" name="enabled" value="1" <?= ($editTask ? (int)$editTask['enabled'] : 1)?'checked':''; ?>> Enabled</label>
      <div class="muted small">Saat create, <code>next_run_at</code> otomatis <code>NOW()</code>.</div>

      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn" type="submit"><?= $editTask ? 'Simpan Perubahan' : 'Tambah Task' ?></button>
        <?php if ($editTask): ?>
          <a class="btn sec" href="<?=h($_SERVER['PHP_SELF'])?>">Batal</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Daftar Task</h2>
    <table>
      <thead><tr>
        <th>ID</th><th>Nama / CMD</th><th>Jadwal</th><th>Status</th><th>Next Run</th><th>Exit</th><th>Aksi</th>
      </tr></thead>
      <tbody>
      <?php if (!$tasks): ?>
        <tr><td colspan="7">Belum ada task.</td></tr>
      <?php else: foreach ($tasks as $t): ?>
        <tr>
          <td class="mono"><?= (int)$t['id'] ?></td>
          <td>
            <div><strong><?=h($t['name'])?></strong> <?= $t['enabled']?'<span class="good">• enabled</span>':'<span class="bad">• disabled</span>' ?></div>
            <div class="mono small"><?=h($t['cmd'])?><?= $t['args'] ? ' '.h($t['args']) : '' ?></div>
          </td>
          <td class="small">
            <?php if ($t['schedule_type']==='interval'):
                $u = (int)$t['interval_unit']; $u = in_array($u, array_keys($unitsMap), true)?$u:1;
                $val = (int)round(((int)$t['interval_sec'])/max(1,$u));
                $label = $unitsMap[$u] ?? 'detik';
            ?>
              interval: <strong><?= $val ?></strong> <?=h($label)?>
            <?php else: ?>
              daily: <strong><?=h($t['daily_time'])?></strong> WIB
            <?php endif; ?>
            <div class="muted">timeout: <?= (int)$t['run_timeout_sec'] ?>s, retries: <?= (int)$t['retries'] ?>/<?= (int)$t['max_retries'] ?></div>
          </td>
          <td class="small">
            <div><strong><?=h($t['status'] ?? 'idle')?></strong></div>
            <?php if (!is_null($t['last_run_at'])): ?><div class="muted">last: <?=h($t['last_run_at'])?></div><?php endif; ?>
          </td>
          <td class="small"><?=h($t['next_run_at'])?></td>
          <td class="small"><?= is_null($t['last_exit_code'])?'-':(int)$t['last_exit_code'] ?></td>
          <td class="small">
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <a class="btn gray" href="<?=h($_SERVER['PHP_SELF'].'?edit='.$t['id'])?>">Edit</a>
              <form method="post" action="?action=toggle" onsubmit="return confirm('Toggle enable/disable task ini?')">
                <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn sec" type="submit"><?= $t['enabled']?'Disable':'Enable' ?></button>
              </form>
              <form method="post" action="?action=run_now">
                <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn" type="submit">Run Now</button>
              </form>
              <form method="post" action="?action=delete" onsubmit="return confirm('Yakin hapus task ini?')">
                <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn warn" type="submit">Hapus</button>
              </form>
              <?php if (!empty($t['last_output'])): ?>
              <form method="post" action="?action=clear_output" onsubmit="return confirm('Bersihkan output terakhir?')">
                <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn sec" type="submit">Clear Output</button>
              </form>
              <?php endif; ?>
            </div>
            <?php if (!empty($t['last_output'])): ?>
              <details style="margin-top:6px">
                <summary>Last Output</summary>
                <pre class="mono" style="white-space:pre-wrap;max-height:200px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px;background:#f8fafc;"><?=h($t['last_output'])?></pre>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <p class="muted small">Tips: gunakan format JSON array untuk <strong>Args</strong> agar aman, misal: <code>["--foo=bar","--fast"]</code></p>
  </div>
</div>
</body>
</html>
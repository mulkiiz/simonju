<?php
$page_title = 'Akun Jurnal';
require_once __DIR__ . '/../includes/header_jurnal.php';

$jid = current_jurnal_id();
$ja  = fetch_one("SELECT * FROM jurnal_accounts WHERE jurnal_id=? LIMIT 1", 'i', [$jid]);

$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old = $_POST['old_pass'] ?? '';
    $new = $_POST['new_pass'] ?? '';
    $cnf = $_POST['confirm_pass'] ?? '';
    if ($new !== $cnf) {
        $err = 'Konfirmasi password tidak cocok.';
    } else {
        [$ok, $m] = change_jurnal_password($jid, $old, $new);
        $ok ? $msg = $m : $err = $m;
    }
}
?>

<div class="page-head">
  <h1>🔐 Akun</h1>
</div>

<?php if ($msg): ?>
  <div class="alert alert-info"><?= h($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="alert alert-error"><?= h($err) ?></div>
<?php endif; ?>

<div style="max-width:480px">
  <div class="card" style="padding:24px">
    <h3 style="margin:0 0 4px">👤 Info Akun</h3>
    <p class="muted" style="font-size:13px;margin:0 0 20px">
      Username: <strong class="mono"><?= h($ja['username'] ?? '-') ?></strong>
    </p>

    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <fieldset>
        <legend>🔒 Ganti Password</legend>
        <label>Password / token lama
          <input type="password" name="old_pass" required autocomplete="current-password">
        </label>
        <label>Password baru <span class="muted small">(min. 8 karakter)</span>
          <input type="password" name="new_pass" minlength="8" required autocomplete="new-password">
        </label>
        <label>Konfirmasi password baru
          <input type="password" name="confirm_pass" minlength="8" required autocomplete="new-password">
        </label>
      </fieldset>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 Simpan Password</button>
        <a href="index.php" class="btn btn-secondary">Batal</a>
      </div>
    </form>
  </div>
</div>

<?php
// Riwayat login akun jurnal ini
$uname = $ja['username'] ?? '';
$logs = $uname !== ''
    ? fetch_all("SELECT * FROM login_log WHERE username=? ORDER BY created_at DESC LIMIT 30", 's', [$uname])
    : [];
?>
<div style="max-width:480px;margin-top:22px">
  <div class="card" style="padding:24px">
    <h3 style="margin:0 0 12px">🔑 Riwayat Login</h3>
    <?php if (empty($logs)): ?>
      <p class="muted small">Belum ada catatan login.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Waktu</th><th>IP</th><th>Hasil</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td class="small"><?= h($l['created_at']) ?></td>
          <td class="small muted"><?= h($l['ip'] ?: '—') ?></td>
          <td><?= $l['success'] ? '<span class="badge badge-success">sukses</span>' : '<span class="badge badge-failed">gagal</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

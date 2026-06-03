<?php
$page_title = 'Akun';
require_once __DIR__ . '/_header.php';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old = $_POST['old_pass'] ?? '';
    $new = $_POST['new_pass'] ?? '';
    $cnf = $_POST['confirm_pass'] ?? '';
    if ($new !== $cnf) {
        $err = 'Konfirmasi password tidak cocok.';
    } else {
        [$ok, $m] = change_password($_SESSION['uid'], $old, $new);
        $ok ? $msg = $m : $err = $m;
    }
}
?>
<div class="page-head">
  <h1>Akun: <?= h($_SESSION['uname']) ?></h1>
  <a href="dashboard.php" class="btn">&larr; Dashboard</a>
</div>

<?php if ($msg): ?><div class="alert alert-info"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="form-grid" style="max-width:480px">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Ganti Password</legend>
    <label>Password lama
      <input type="password" name="old_pass" required>
    </label>
    <label>Password baru (min. 8 karakter)
      <input type="password" name="new_pass" minlength="8" required>
    </label>
    <label>Konfirmasi password baru
      <input type="password" name="confirm_pass" minlength="8" required>
    </label>
  </fieldset>
  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Simpan</button>
  </div>
</form>

<?php require_once __DIR__ . '/_footer.php'; ?>

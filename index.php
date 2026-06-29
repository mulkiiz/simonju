<?php
require_once __DIR__ . '/includes/auth.php';

// Already logged in → redirect to appropriate dashboard
if (is_logged_in()) {
    if (is_jurnal_user()) {
        header('Location: jurnal/');
    } elseif (is_doi_admin()) {
        header('Location: admin/doi_requests.php');
    } else {
        header('Location: admin/');
    }
    exit;
}

$error = '';
$msg = $_GET['msg'] ?? '';
if ($msg === 'timeout') $error = 'Sesi habis. Silakan login ulang.';
if ($msg === 'logout')  $error = 'Anda telah keluar.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($u === '' || $p === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        [$ok, $result] = attempt_login($u, $p);
        if ($ok) {
            if ($result === 'jurnal') {
                header('Location: jurnal/');
            } elseif ($result === 'doi') {
                header('Location: admin/doi_requests.php');
            } else {
                header('Location: admin/');
            }
            exit;
        }
        $error = $result;
    }
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login &middot; Simonju Unsoed</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/logo_unsoed.png">
</head>
<body class="login-body">
<div class="login-wrap">
  <div class="login-card">
    <div class="login-header">
      <img src="assets/logo_unsoed.png" alt="Logo Unsoed" class="login-logo">
      <h1 class="brand-title">SIMONJU</h1>
      <div class="brand-divider"></div>
      <p class="brand-org">Pusat Pengelolaan Jurnal</p>
      <p class="brand-org-sub">Universitas Jenderal Soedirman</p>
    </div>

    <?php if ($error): ?>
      <div class="alert <?= ($msg==='logout'?'alert-info':'alert-error') ?>"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off" class="login-form">
      <?= csrf_field() ?>
      <label>
        <span class="lbl"><span class="ico">&#128100;</span> Username</span>
        <input type="text" name="username" required autofocus placeholder="Username admin atau slug jurnal">
      </label>
      <label>
        <span class="lbl"><span class="ico">&#128274;</span> Password</span>
        <input type="password" name="password" required placeholder="Password">
      </label>
      <button type="submit" class="btn btn-primary btn-block btn-login">
        Masuk
      </button>
    </form>

    <p class="login-foot">
      &copy; <?= date('Y') ?> Pusat Pengelolaan Jurnal &middot; Unsoed
    </p>
  </div>
</div>
</body>
</html>

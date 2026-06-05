<?php
require_once __DIR__ . '/auth.php';
require_admin();
$page_title = $page_title ?? 'Dashboard';
$body_class = $body_class ?? '';
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($page_title) ?> &middot; <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="icon" type="image/png" href="../assets/logo_unsoed.png">
</head>
<body class="<?= h($body_class) ?>">
<header class="topbar">
  <div class="container topbar-inner">
    <div class="brand">
      <a href="dashboard.php">
        <span class="brand-logo-wrap">
          <img src="../assets/logo_unsoed.png" alt="Unsoed" class="brand-logo">
        </span>
        <span class="brand-text">
          <span class="brand-name"><?= h(APP_NAME) ?></span>
          <span class="brand-tag">Pusat Pengelolaan Jurnal</span>
          <span class="brand-tag-sub">Universitas Jenderal Soedirman</span>
        </span>
      </a>
    </div>
    <nav class="nav">
      <a href="dashboard.php">📋 Dashboard</a>
      <a href="statistik.php">📊 Statistik</a>
      <a href="konfirmasi_admin.php">✅ Konfirmasi</a>
      <a href="account.php">⚙️ Akun</a>
      <a href="../logout.php" class="logout">🚪 Keluar</a>
    </nav>
  </div>
</header>
<main class="container">

<?php
require_once __DIR__ . '/auth.php';
require_jurnal();
$page_title = $page_title ?? 'Dashboard Jurnal';
$body_class = $body_class ?? '';
$_jid = current_jurnal_id();
$_jnama = $_SESSION['jurnal_nama'] ?? 'Jurnal';
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
      <a href="index.php">
        <span class="brand-logo-wrap">
          <img src="../assets/logo_unsoed.png" alt="Unsoed" class="brand-logo">
        </span>
        <span class="brand-text">
          <span class="brand-name"><?= h(APP_NAME) ?></span>
          <span class="brand-tag"><?= h($_jnama) ?></span>
        </span>
      </a>
    </div>
    <nav class="nav">
      <a href="index.php">📋 Dashboard</a>
      <a href="doi.php">🔗 DOI</a>
      <a href="edit.php">✏️ Edit Data</a>
      <a href="../logout.php" class="logout">🚪 Log out</a>
    </nav>
  </div>
</header>
<main class="container">

<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/scanner_judol.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: scan_judol_log.php'); exit;
}
csrf_check();

$jid = (int)($_POST['jurnal_id'] ?? 0);
if (!$jid) { header('Location: scan_judol_log.php'); exit; }

@set_time_limit(120);
$r = scan_judol($jid);

// Tentukan halaman tujuan redirect berdasarkan referer
//   - dari scan_judol_log.php  → balik ke scan_judol_log.php
//   - dari jurnal_view.php     → balik ke jurnal_view.php
//   - dari dashboard.php       → balik ke scan_judol_log.php (sesuai permintaan user)
//   - lain-lain                → scan_judol_log.php
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if (stripos($ref, 'jurnal_view.php') !== false) {
    $dest = "jurnal_view.php?id={$jid}";
} else {
    // Default: balik ke halaman Scanner Judol
    $dest = "scan_judol_log.php";
}

if (!$r['ok']) {
    $msg = "Scan gagal: " . ($r['message'] ?? 'unknown');
    $sep = strpos($dest, '?') === false ? '?' : '&';
    header("Location: {$dest}{$sep}scanned=fail&msg=" . urlencode($msg));
    exit;
}

$score = $r['risk']['score'];
$label = $r['risk']['label'];
$cloak = !empty($r['cloaking']['cloaking']) ? ' (CLOAKING)' : '';

// Untuk UNREACHABLE, score = null → tampilkan "—" daripada "/100"
if ($score === null) {
    $msg = "Scan judol selesai. Label: {$label}{$cloak}";
} else {
    $msg = "Scan judol selesai. Skor: {$score}/100, Label: {$label}{$cloak}";
}

$sep = strpos($dest, '?') === false ? '?' : '&';
header("Location: {$dest}{$sep}scanned=ok&jid={$jid}&msg=" . urlencode($msg));
exit;

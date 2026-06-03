<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crawler.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php'); exit;
}
csrf_check();

$jid = (int)($_POST['jurnal_id'] ?? 0);
if (!$jid) { header('Location: dashboard.php'); exit; }

@set_time_limit(120);
$result = crawl_jurnal($jid, 'manual', true);

$status = $result['ok'] ? 'ok' : 'fail';
$msg = $result['ok']
    ? "Berhasil. Ditemukan {$result['found']} terbitan, {$result['new']} baru."
    : "Gagal: " . ($result['message'] ?? 'unknown');

header("Location: jurnal_view.php?id={$jid}&crawled={$status}&msg=" . urlencode($msg));
exit;

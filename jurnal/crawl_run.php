<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/crawler.php';
require_jurnal();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$jid = current_jurnal_id();
$j   = fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$jid]);
if (!$j) { header('Location: index.php'); exit; }

[$status, $msg] = crawl_single($j);
header("Location: index.php?crawled={$status}&msg=" . urlencode($msg));
exit;

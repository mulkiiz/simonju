<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/scanner_judol.php';
require_jurnal();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$jid = current_jurnal_id();
$j   = fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$jid]);
if (!$j) { header('Location: index.php'); exit; }

[$ok, $msg] = scan_judol_single($j);
$st = $ok ? 'ok' : 'fail';
header("Location: index.php?scanned={$st}&msg=" . urlencode($msg));
exit;

<?php
require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php'); exit;
}
csrf_check();
$id = (int)($_POST['id'] ?? 0);
if ($id) {
    exec_q("DELETE FROM jurnals WHERE id=?", 'i', [$id]);
}
header('Location: dashboard.php');
exit;

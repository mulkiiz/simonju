<?php
require_once __DIR__ . '/../includes/header_admin.php';
require_admin();
csrf_check();

$ja_id = (int)($_POST['ja_id'] ?? 0);
if ($ja_id <= 0) {
    header('Location: account.php?tab=jurnal&err=' . urlencode('ID tidak valid.'));
    exit;
}

[$ok, $msg] = reset_jurnal_password_to_token($ja_id);

$param = $ok ? 'reset_ok' : 'reset_err';
header('Location: account.php?tab=jurnal&' . $param . '=' . urlencode($msg));
exit;

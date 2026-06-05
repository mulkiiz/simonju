<?php
<<<<<<< HEAD
require_once __DIR__ . '/auth.php';
=======
require_once __DIR__ . '/includes/auth.php';
>>>>>>> 344f8fb (perapihan folder, login akun jurnal, dll)
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: index.php?msg=logout');
exit;

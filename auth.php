<?php
require_once __DIR__ . '/db.php';

// Secure session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    if (!empty($_SERVER['HTTPS'])) {
        ini_set('session.cookie_secure', '1');
    }
    session_set_cookie_params(SESSION_LIFETIME);
    session_name('PPJSESSID');
    session_start();
}

function is_logged_in() {
    return !empty($_SESSION['uid']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
    // session timeout
    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        header('Location: index.php?msg=timeout');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check() {
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token. Refresh halaman & coba lagi.');
    }
}

function client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function log_login($username, $success) {
    exec_q(
        "INSERT INTO login_log (username, ip, success) VALUES (?, ?, ?)",
        'ssi', [$username, client_ip(), $success ? 1 : 0]
    );
}

function attempt_login($username, $password) {
    $user = fetch_one("SELECT * FROM users WHERE username=? LIMIT 1", 's', [$username]);
    if (!$user) {
        log_login($username, false);
        return [false, 'Username atau password salah.'];
    }

    // locked?
    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $sisa = ceil((strtotime($user['locked_until']) - time()) / 60);
        return [false, "Akun terkunci. Coba lagi {$sisa} menit lagi."];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int)$user['failed_attempts'] + 1;
        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            $until = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
            exec_q("UPDATE users SET failed_attempts=?, locked_until=? WHERE id=?",
                'isi', [$attempts, $until, $user['id']]);
            log_login($username, false);
            return [false, "Terlalu banyak percobaan. Akun terkunci " . LOGIN_LOCK_MINUTES . " menit."];
        }
        exec_q("UPDATE users SET failed_attempts=? WHERE id=?", 'ii', [$attempts, $user['id']]);
        log_login($username, false);
        return [false, 'Username atau password salah.'];
    }

    // success
    exec_q("UPDATE users SET failed_attempts=0, locked_until=NULL WHERE id=?", 'i', [$user['id']]);
    log_login($username, true);
    session_regenerate_id(true);
    $_SESSION['uid'] = $user['id'];
    $_SESSION['uname'] = $user['username'];
    $_SESSION['last_activity'] = time();
    return [true, 'OK'];
}

function change_password($uid, $old, $new) {
    $user = fetch_one("SELECT * FROM users WHERE id=?", 'i', [$uid]);
    if (!$user || !password_verify($old, $user['password_hash'])) {
        return [false, 'Password lama salah.'];
    }
    if (strlen($new) < 8) {
        return [false, 'Password baru minimal 8 karakter.'];
    }
    $hash = password_hash($new, PASSWORD_BCRYPT);
    exec_q("UPDATE users SET password_hash=? WHERE id=?", 'si', [$hash, $uid]);
    return [true, 'Password berhasil diganti.'];
}

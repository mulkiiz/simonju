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

// =========================================================
// Session helpers
// =========================================================
function is_logged_in() {
    return !empty($_SESSION['uid']) || !empty($_SESSION['jurnal_id']);
}

function is_admin() {
    return !empty($_SESSION['uid']) && ($_SESSION['role'] ?? '') === 'admin';
}

function is_jurnal_user() {
    return !empty($_SESSION['jurnal_id']);
}

function current_jurnal_id() {
    return (int)($_SESSION['jurnal_id'] ?? 0);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: ' . app_url('/index.php'));
        exit;
    }
    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        header('Location: ' . app_url('/index.php?msg=timeout'));
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function require_admin() {
    require_login();
    if (!is_admin()) {
        header('Location: ' . app_url('/jurnal/'));
        exit;
    }
}

function require_jurnal() {
    require_login();
    if (!is_jurnal_user()) {
        header('Location: ' . app_url('/admin/'));
        exit;
    }
}

/** Build absolute URL path from app root */
function app_url($path = '/') {
    // Detect base path from script (works on subdirectory installs)
    static $base = null;
    if ($base === null) {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        // If we're in a subfolder (admin/, jurnal/), go up
        if (preg_match('#/(admin|jurnal|konfirmasi|cron|includes|lib)$#', $base)) {
            $base = dirname($base);
        }
        $base = rtrim($base, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

// =========================================================
// CSRF
// =========================================================
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

// =========================================================
// Login: cek admin (tabel users) lalu jurnal (tabel jurnal_accounts)
// =========================================================
function log_login($username, $success) {
    exec_q(
        "INSERT INTO login_log (username, ip, success) VALUES (?, ?, ?)",
        'ssi', [$username, client_ip(), $success ? 1 : 0]
    );
}

function attempt_login($username, $password) {
    // --- 1. Cek tabel users (admin/operator) ---
    $user = fetch_one("SELECT * FROM users WHERE username=? LIMIT 1", 's', [$username]);
    if ($user) {
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

        // Admin login success
        exec_q("UPDATE users SET failed_attempts=0, locked_until=NULL WHERE id=?", 'i', [$user['id']]);
        log_login($username, true);
        session_regenerate_id(true);
        $_SESSION['uid']   = $user['id'];
        $_SESSION['uname'] = $user['username'];
        $_SESSION['role']  = $user['role'] ?? 'admin';
        $_SESSION['last_activity'] = time();
        return [true, 'admin'];
    }

    // --- 2. Cek tabel jurnal_accounts (password bcrypt di password_hash) ---
    $ja = fetch_one(
        "SELECT ja.*, j.nama_jurnal
         FROM jurnal_accounts ja
         JOIN jurnals j ON j.id = ja.jurnal_id
         WHERE ja.username = ?
         LIMIT 1",
        's', [$username]
    );
    if ($ja) {
        // locked?
        if (!empty($ja['locked_until']) && strtotime($ja['locked_until']) > time()) {
            $sisa = ceil((strtotime($ja['locked_until']) - time()) / 60);
            return [false, "Akun terkunci. Coba lagi {$sisa} menit lagi."];
        }

        if (!password_verify($password, $ja['password_hash'])) {
            $attempts = (int)$ja['failed_attempts'] + 1;
            if ($attempts >= LOGIN_MAX_ATTEMPTS) {
                $until = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
                exec_q("UPDATE jurnal_accounts SET failed_attempts=?, locked_until=? WHERE id=?",
                    'isi', [$attempts, $until, $ja['id']]);
                log_login($username, false);
                return [false, "Terlalu banyak percobaan. Akun terkunci " . LOGIN_LOCK_MINUTES . " menit."];
            }
            exec_q("UPDATE jurnal_accounts SET failed_attempts=? WHERE id=?", 'ii', [$attempts, $ja['id']]);
            log_login($username, false);
            return [false, 'Username atau password salah.'];
        }

        // Journal login success
        exec_q("UPDATE jurnal_accounts SET failed_attempts=0, locked_until=NULL WHERE id=?", 'i', [$ja['id']]);
        log_login($username, true);
        session_regenerate_id(true);
        $_SESSION['jurnal_id']   = (int)$ja['jurnal_id'];
        $_SESSION['jurnal_user'] = $ja['username'];
        $_SESSION['jurnal_nama'] = $ja['nama_jurnal'];
        $_SESSION['role']        = 'jurnal';
        $_SESSION['last_activity'] = time();
        return [true, 'jurnal'];
    }

    log_login($username, false);
    return [false, 'Username atau password salah.'];
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

/**
 * Ganti password akun jurnal editor. Verifikasi password lama dulu.
 * @param int    $jurnal_id  dari session jurnal_id
 * @param string $old        password lama (plain text)
 * @param string $new        password baru (plain text)
 */
function change_jurnal_password(int $jurnal_id, string $old, string $new): array
{
    $ja = fetch_one("SELECT * FROM jurnal_accounts WHERE jurnal_id=? LIMIT 1", 'i', [$jurnal_id]);
    if (!$ja) {
        return [false, 'Akun jurnal tidak ditemukan.'];
    }
    if (!password_verify($old, $ja['password_hash'])) {
        return [false, 'Password / token lama salah.'];
    }
    if (strlen($new) < 8) {
        return [false, 'Password baru minimal 8 karakter.'];
    }
    $hash = password_hash($new, PASSWORD_BCRYPT);
    exec_q("UPDATE jurnal_accounts SET password_hash=? WHERE jurnal_id=?", 'si', [$hash, $jurnal_id]);
    return [true, 'Password berhasil diganti.'];
}

/**
 * Reset password akun jurnal ke konfirmasi_token asal (hanya oleh admin).
 * @param int $ja_id  id di tabel jurnal_accounts
 */
function reset_jurnal_password_to_token(int $ja_id): array
{
    $ja = fetch_one(
        "SELECT ja.id, j.konfirmasi_token
         FROM jurnal_accounts ja JOIN jurnals j ON j.id = ja.jurnal_id
         WHERE ja.id = ? LIMIT 1",
        'i', [$ja_id]
    );
    if (!$ja || empty($ja['konfirmasi_token'])) {
        return [false, 'Akun atau token tidak ditemukan.'];
    }
    $hash = password_hash($ja['konfirmasi_token'], PASSWORD_BCRYPT);
    exec_q(
        "UPDATE jurnal_accounts SET password_hash=?, failed_attempts=0, locked_until=NULL WHERE id=?",
        'si', [$hash, $ja_id]
    );
    return [true, 'Password berhasil direset ke token asal.'];
}

/**
 * Ambil slug username dari link_editor (segment setelah /index.php/).
 * Hanya alfanumerik, dash, underscore. '' bila tidak ada.
 */
function jurnal_username_from_link_editor(?string $link_editor): string
{
    $link   = (string)$link_editor;
    $marker = '/index.php/';
    $pos    = stripos($link, $marker);
    if ($pos === false) return '';
    $rest = substr($link, $pos + strlen($marker));
    $slug = explode('/', $rest)[0] ?? '';
    return preg_replace('/[^A-Za-z0-9_-]/', '', trim($slug));
}

/**
 * Buat username akun jurnal yang valid: min 4 karakter & unik.
 * Slug dari link_editor; bila < 4 char ditambah jurnal_id, lalu di-pad.
 * Tabrakan username diselesaikan dengan suffix angka.
 */
function generate_jurnal_username(int $jurnal_id, ?string $link_editor): string
{
    $base = jurnal_username_from_link_editor($link_editor);
    if ($base === '') $base = 'jurnal';
    if (strlen($base) < 4) $base .= (string)$jurnal_id;   // cth: JOS -> JOS12
    if (strlen($base) < 4) $base = str_pad($base, 4, '0');
    if (strlen($base) > 50) $base = substr($base, 0, 50);

    $username = $base;
    $n = 1;
    while (fetch_one(
        "SELECT id FROM jurnal_accounts WHERE username=? AND jurnal_id<>? LIMIT 1",
        'si', [$username, $jurnal_id]
    )) {
        $suffix   = (string)$n;
        $username = substr($base, 0, 50 - strlen($suffix)) . $suffix;
        $n++;
    }
    return $username;
}

/**
 * Pastikan jurnal punya akun login dengan username valid (min 4 char).
 * - Belum ada akun  -> INSERT (password_hash = bcrypt token bila ada).
 * - Akun ada & username < 4 char -> rename ke username valid.
 * - Akun ada & username valid     -> biarkan.
 * Return: ['action'=>'created|renamed|kept', 'username'=>...].
 */
function ensure_jurnal_account(int $jurnal_id, ?string $link_editor, ?string $token = null): array
{
    $existing = fetch_one(
        "SELECT id, username FROM jurnal_accounts WHERE jurnal_id=? LIMIT 1",
        'i', [$jurnal_id]
    );

    if ($existing) {
        $cur = trim((string)$existing['username']);
        if (strlen($cur) < 4) {
            $u = generate_jurnal_username($jurnal_id, $link_editor);
            exec_q("UPDATE jurnal_accounts SET username=? WHERE id=?", 'si', [$u, (int)$existing['id']]);
            return ['action' => 'renamed', 'username' => $u];
        }
        return ['action' => 'kept', 'username' => $cur];
    }

    $u    = generate_jurnal_username($jurnal_id, $link_editor);
    $tok  = (string)$token;
    if ($tok !== '') {
        $hash = password_hash($tok, PASSWORD_BCRYPT);
        exec_q("INSERT INTO jurnal_accounts (jurnal_id, username, password_hash) VALUES (?,?,?)",
               'iss', [$jurnal_id, $u, $hash]);
    } else {
        exec_q("INSERT INTO jurnal_accounts (jurnal_id, username) VALUES (?,?)",
               'is', [$jurnal_id, $u]);
    }
    return ['action' => 'created', 'username' => $u];
}

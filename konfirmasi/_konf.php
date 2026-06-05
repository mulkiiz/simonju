<?php
/**
 * SIMONJU - Helper halaman publik /konfirmasi
 * ===========================================
 * File ini dipakai oleh halaman konfirmasi yang TIDAK perlu login.
 * Tetap pakai db.php dari project utama (path: ../db.php).
 */
<<<<<<< HEAD
require_once __DIR__ . '/../db.php';
=======
require_once __DIR__ . '/../includes/db.php';
>>>>>>> 344f8fb (perapihan folder, login akun jurnal, dll)

// --- Session khusus untuk CSRF di halaman publik ---
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    if (!empty($_SERVER['HTTPS'])) {
        ini_set('session.cookie_secure', '1');
    }
    session_name('PPJKONFSID');
    session_start();
}

// --- Rate limit: max 5 submit / IP / jam ---
define('KONF_RL_MAX',     5);
define('KONF_RL_WINDOW',  3600); // detik

function konf_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function konf_csrf_token() {
    if (empty($_SESSION['konf_csrf'])) {
        $_SESSION['konf_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['konf_csrf'];
}

function konf_csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . h(konf_csrf_token()) . '">';
}

function konf_csrf_check() {
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['konf_csrf'] ?? '', $token)) {
        http_response_code(403);
        die('Token keamanan tidak valid. Silakan refresh halaman & coba lagi.');
    }
}

/** True jika IP masih boleh submit. */
function konf_rate_ok() {
    $ip  = konf_client_ip();
    $row = fetch_one(
        "SELECT COUNT(*) AS n FROM konfirmasi_ratelimit
         WHERE ip=? AND created_at > (NOW() - INTERVAL ? SECOND)",
        'si', [$ip, KONF_RL_WINDOW]
    );
    return (int)($row['n'] ?? 0) < KONF_RL_MAX;
}

function konf_rate_hit() {
    exec_q("INSERT INTO konfirmasi_ratelimit (ip) VALUES (?)", 's', [konf_client_ip()]);
}

/** Ambil jurnal berdasarkan token akses. NULL jika token salah. */
function konf_get_jurnal_by_token($token) {
    $token = trim((string)$token);
    if ($token === '' || !preg_match('/^[0-9a-f]{16}$/', $token)) return null;
    return fetch_one(
        "SELECT * FROM jurnals WHERE konfirmasi_token=? LIMIT 1",
        's', [$token]
    );
}

/** Daftar unit kerja (fakultas) Unsoed untuk dropdown form konfirmasi. */
function konf_unit_kerja_list() {
    return [
        'Fakultas Pertanian (Faperta)',
        'Fakultas Biologi (Fabio)',
        'Fakultas Ekonomi dan Bisnis (FEB)',
        'Fakultas Peternakan (Fapet)',
        'Fakultas Hukum (FH)',
        'Fakultas Ilmu Sosial dan Ilmu Politik (FISIP)',
        'Fakultas Kedokteran (FK)',
        'Fakultas Teknik (FT)',
        'Fakultas Ilmu-Ilmu Kesehatan (FIKES)',
        'Fakultas Ilmu Budaya (FIB)',
        'Fakultas Matematika dan Ilmu Pengetahuan Alam (FMIPA)',
        'Fakultas Perikanan dan Ilmu Kelautan (FPIK)',
        'LPPM',
        'Unit kerja lainnya',
    ];
}

/** Header HTML publik (mirip login-card, tanpa nav admin). */
function konf_header($title) {
    $app = defined('APP_NAME') ? APP_NAME : 'Simonju';
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> &middot; <?= h($app) ?></title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="icon" type="image/png" href="../assets/logo_unsoed.png">
<style>
  .konf-wrap{max-width:860px;margin:0 auto;padding:28px 18px 60px}
  .konf-head{text-align:center;margin-bottom:26px}
  .konf-head img{height:74px;width:auto}
  .konf-head h1{margin:14px 0 4px;font-size:1.5rem;letter-spacing:2px;color:#1c3a6e}
  .konf-head p{margin:2px 0;color:#5a6675;font-size:.92rem}
  .konf-card{background:#fff;border:1px solid #e3e8ef;border-radius:14px;
             padding:22px 24px;box-shadow:0 2px 10px rgba(20,40,80,.05);margin-bottom:18px}
  .konf-card,.konf-card p,.konf-card h2,.konf-card h3{color:#1c2b46}
  .konf-jlist{list-style:none;margin:0;padding:0}
  .konf-jlist li{display:flex;align-items:center;justify-content:space-between;
                 gap:12px;padding:13px 4px;border-bottom:1px solid #eef1f5}
  .konf-jlist li:last-child{border-bottom:none}
  .konf-jname{font-weight:600;color:#1c2b46}
  .konf-jmeta{font-size:.8rem;color:#8a94a3}
  .konf-status{font-size:.72rem;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap}
  .st-belum{background:#eef1f5;color:#6b7785}
  .st-pending{background:#fff4d6;color:#9a6b00}
  .st-terkonfirmasi{background:#d8f3e3;color:#1c7a47}
  .konf-search{width:100%;padding:11px 14px;border:1px solid #cdd5e0;
               border-radius:9px;font-size:.95rem;margin-bottom:14px;color:#1c2b46}
  .konf-form label{display:block;margin-bottom:14px;font-size:.88rem;
                   font-weight:600;color:#33415c}
  .konf-form input[type=text],.konf-form input[type=url],
  .konf-form input[type=email],.konf-form select,.konf-form textarea{
    width:100%;margin-top:5px;padding:9px 11px;border:1px solid #cdd5e0;
    border-radius:8px;font-size:.92rem;font-weight:400;color:#1c2b46;background:#fff}
  .konf-form .row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .konf-form fieldset{border:1px solid #e3e8ef;border-radius:10px;
                      padding:16px 18px;margin-bottom:18px}
  .konf-form legend{font-weight:700;color:#1c3a6e;padding:0 8px;font-size:.9rem}
  /* teks bantu di luar kartu */
  .konf-note{color:#46546b;font-size:.88rem;line-height:1.6;text-align:center;
             margin:0 auto 18px;max-width:620px}
  .konf-foot{text-align:center;color:#8a94a3;font-size:.8rem;margin-top:24px}
  .req{color:#c0392b}
  /* background terang supaya teks gelap terbaca jelas */
  body.konf-body{
    margin:0;min-height:100vh;
    background:#eef2f7;
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;
  }
  @media(max-width:560px){.konf-form .row-2{grid-template-columns:1fr}}
</style>
</head>
<body class="konf-body">
<div class="konf-wrap">
  <div class="konf-head">
    <img src="../assets/logo_unsoed.png" alt="Unsoed">
    <h1>KONFIRMASI DATA JURNAL</h1>
    <div class="brand-divider" style="margin:8px auto"></div>
    <p>Pusat Pengelolaan Jurnal &middot; Universitas Jenderal Soedirman</p>
  </div>
<?php
}

function konf_footer() {
    ?>
  <p class="konf-foot">&copy; <?= date('Y') ?> SIMONJU &middot; ppj.jurnalsinta.id</p>
</div>
</body>
</html>
<?php
}

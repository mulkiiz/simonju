<?php
require_once __DIR__ . '/../includes/header_admin.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();
csrf_check();

/* ── TEST EMAIL (data random) ───────────────────── */
if (!empty($_POST['test_email'])) {
    $sample_jurnals = [
        'Jurnal Inovasi Teknologi Pertanian',
        'Journal of Soedirman Mathematics',
        'Jurnal Ilmu Kelautan Tropis',
        'Dinamika Ekonomi dan Bisnis',
        'Jurnal Kedokteran Hewan Nusantara',
    ];
    $rand_nama  = $sample_jurnals[array_rand($sample_jurnals)];
    $rand_user  = strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $rand_nama)[0]))
                . random_int(10, 99);
    $rand_token = bin2hex(random_bytes(4));

    $subject = '[TEST] Info Login Simonju - ' . $rand_nama;
    $body    = build_jurnal_email($rand_nama, $rand_user, $rand_token);

    [$ok, $msg] = send_smtp_mail(
        'admiportfolio@gmail.com',
        'Test Email Admin',
        $subject,
        $body,
        true
    );
    $param = $ok ? 'email_ok' : 'email_err';
    header('Location: account.php?tab=jurnal&' . $param . '=' . urlencode($msg));
    exit;
}

/* ── KIRIM KE EDITOR JURNAL ─────────────────────── */
$ja_id = (int)($_POST['ja_id'] ?? 0);
if ($ja_id <= 0) {
    header('Location: account.php?tab=jurnal&email_err=' . urlencode('ID tidak valid.'));
    exit;
}

$row = fetch_one(
    "SELECT ja.username, j.nama_jurnal, j.konfirmasi_token,
            e.email AS email_editor, e.nama AS nama_editor
     FROM jurnal_accounts ja
     JOIN jurnals j ON j.id = ja.jurnal_id
     LEFT JOIN editor e ON e.jurnal_id = ja.jurnal_id
     WHERE ja.id = ? LIMIT 1",
    'i', [$ja_id]
);

if (!$row) {
    header('Location: account.php?tab=jurnal&email_err=' . urlencode('Data tidak ditemukan.'));
    exit;
}

$to_email = trim($row['email_editor'] ?? '');
if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
    header('Location: account.php?tab=jurnal&email_err=' . urlencode('Email ketua editor belum diisi atau tidak valid.'));
    exit;
}

$subject = 'Info Login Simonju - ' . $row['nama_jurnal'];
$body    = build_jurnal_email(
    $row['nama_jurnal'],
    $row['username'],
    $row['konfirmasi_token'] ?? '(lihat admin)'
);

[$ok, $msg] = send_smtp_mail(
    $to_email,
    $row['nama_editor'] ?: $row['nama_jurnal'],
    $subject,
    $body,
    true
);

$param = $ok ? 'email_ok' : 'email_err';
header('Location: account.php?tab=jurnal&' . $param . '=' . urlencode($msg));
exit;

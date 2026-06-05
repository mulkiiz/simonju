<?php
/**
 * jurnal/upload.php — Handle upload sertifikat PDF & cover image.
 * Bisa dipakai oleh akun jurnal maupun admin.
 */
require_once __DIR__ . '/../includes/auth.php';

// Tentukan jurnal_id berdasarkan tipe user
if (is_jurnal_user()) {
    require_jurnal();
    $jid = current_jurnal_id();
    $back = 'index.php';
} elseif (is_admin()) {
    require_admin();
    $jid = (int)($_POST['jurnal_id'] ?? 0);
    $back = 'jurnal_view.php?id=' . $jid;
} else {
    header('Location: /'); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: {$back}"); exit; }
csrf_check();

$j = fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$jid]);
if (!$j) { header("Location: {$back}"); exit; }

$type = $_POST['upload_type'] ?? '';
if (!in_array($type, ['sertifikat', 'cover'])) {
    header("Location: {$back}?upload=fail&msg=" . urlencode('Tipe upload tidak valid.')); exit;
}

$upload_dir = __DIR__ . '/../uploads/jurnal/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    $err_msg = 'Gagal upload file.';
    if ($file && $file['error'] === UPLOAD_ERR_INI_SIZE) $err_msg = 'File terlalu besar (melebihi batas server).';
    header("Location: {$back}?upload=fail&msg=" . urlencode($err_msg)); exit;
}

// ── Validasi per tipe ──────────────────────────────────
if ($type === 'sertifikat') {
    $max_size = 2 * 1024 * 1024; // 2MB
    $allowed_mime = ['application/pdf'];
    $ext = 'pdf';
    $col = 'file_sertifikat';
    $label = 'Sertifikat';
} else {
    $max_size = 2 * 1024 * 1024; // 2MB
    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];
    $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $col = 'file_cover';
    $label = 'Cover';
}

// Cek ukuran
if ($file['size'] > $max_size) {
    header("Location: {$back}?upload=fail&msg=" . urlencode("{$label} maksimal 2MB.")); exit;
}

// Cek MIME type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);

if ($type === 'sertifikat') {
    if (!in_array($mime, $allowed_mime)) {
        header("Location: {$back}?upload=fail&msg=" . urlencode('File harus berformat PDF.')); exit;
    }
} else {
    if (!isset($ext_map[$mime])) {
        header("Location: {$back}?upload=fail&msg=" . urlencode('File harus berformat JPG, PNG, atau WebP.')); exit;
    }
    $ext = $ext_map[$mime];
}

// ── Hapus file lama jika ada ────────────────────────────
$old_file = $j[$col] ?? '';
if ($old_file !== '' && file_exists($upload_dir . $old_file)) {
    unlink($upload_dir . $old_file);
}

// ── Simpan file baru ───────────────────────────────────
$filename = $type . '_' . $jid . '_' . time() . '.' . $ext;
$dest = $upload_dir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    header("Location: {$back}?upload=fail&msg=" . urlencode('Gagal menyimpan file.')); exit;
}

// ── Update database ────────────────────────────────────
exec_q("UPDATE jurnals SET {$col}=? WHERE id=?", 'si', [$filename, $jid]);

header("Location: {$back}?upload=ok&msg=" . urlencode("{$label} berhasil diupload."));
exit;

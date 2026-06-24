<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
csrf_check();

// Terima jurnal_id (utama) atau id (kompat lama)
$jid = (int)($_POST['jurnal_id'] ?? $_POST['id'] ?? 0);
if (!$jid) { header('Location: dashboard.php?deleted=fail&msg=' . urlencode('ID jurnal tidak valid.')); exit; }

$j = fetch_one("SELECT id, nama_jurnal, file_cover, file_sertifikat FROM jurnals WHERE id=?", 'i', [$jid]);
if (!$j) { header('Location: dashboard.php?deleted=fail&msg=' . urlencode('Jurnal tidak ditemukan (mungkin sudah dihapus).')); exit; }

// Safety: admin harus ketik nama jurnal persis untuk konfirmasi
$confirm = trim($_POST['confirm_name'] ?? '');
if ($confirm === '' || $confirm !== trim($j['nama_jurnal'])) {
    header('Location: jurnal_view.php?id=' . $jid . '&deleted=fail&msg=' . urlencode('Nama konfirmasi tidak cocok. Hapus dibatalkan.'));
    exit;
}

$conn = db();
$conn->begin_transaction();

try {
    // Tabel anak = semua tabel di DB ini yang punya kolom jurnal_id,
    // kecuali jurnals (induk) & jurnal_baru (di-NULL-kan, bukan dihapus).
    // Diambil dinamis agar tahan beda skema antar server (mis. tabel
    // *_bak yang hanya ada di lokal).
    $child_tables = array_column(
        fetch_all(
            "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'jurnal_id'
                AND TABLE_NAME NOT IN ('jurnals','jurnal_baru')",
            's', [DB_NAME]
        ),
        't'
    );
    foreach ($child_tables as $tbl) {
        // Validasi nama tabel (whitelist karakter) sebelum interpolasi
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tbl)) {
            throw new Exception("Nama tabel tidak valid: {$tbl}");
        }
        $stmt = $conn->prepare("DELETE FROM `{$tbl}` WHERE jurnal_id=?");
        if (!$stmt) throw new Exception("Prepare gagal ({$tbl}): " . $conn->error);
        $stmt->bind_param('i', $jid);
        if (!$stmt->execute()) throw new Exception("Delete {$tbl} gagal: " . $stmt->error);
        $stmt->close();
    }

    // jurnal_baru: simpan histori request, lepas tautannya saja
    $stmt = $conn->prepare("UPDATE jurnal_baru SET jurnal_id=NULL WHERE jurnal_id=?");
    if (!$stmt) throw new Exception("Prepare gagal (jurnal_baru): " . $conn->error);
    $stmt->bind_param('i', $jid);
    if (!$stmt->execute()) throw new Exception("Update jurnal_baru gagal: " . $stmt->error);
    $stmt->close();

    // Induk
    $stmt = $conn->prepare("DELETE FROM jurnals WHERE id=?");
    if (!$stmt) throw new Exception("Prepare gagal (jurnals): " . $conn->error);
    $stmt->bind_param('i', $jid);
    if (!$stmt->execute()) throw new Exception("Delete jurnals gagal: " . $stmt->error);
    $deleted = $stmt->affected_rows;
    $stmt->close();

    if ($deleted < 1) throw new Exception("Baris jurnal tidak terhapus.");

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log('jurnal_delete failed (id=' . $jid . '): ' . $e->getMessage());
    header('Location: jurnal_view.php?id=' . $jid . '&deleted=fail&msg=' . urlencode('Gagal hapus: ' . $e->getMessage()));
    exit;
}

// Best-effort: hapus file cover & sertifikat di disk (setelah commit)
$updir = __DIR__ . '/../uploads/jurnal/';
foreach (['file_cover', 'file_sertifikat'] as $col) {
    $fn = trim((string)($j[$col] ?? ''));
    if ($fn === '') continue;
    $path = $updir . basename($fn); // basename: cegah path traversal
    if (is_file($path)) @unlink($path);
}

$nama = $j['nama_jurnal'];
header('Location: dashboard.php?deleted=ok&msg=' . urlencode("Jurnal \"{$nama}\" (id {$jid}) beserta semua data terkait dihapus."));
exit;

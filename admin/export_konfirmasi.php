<?php
/**
 * export_konfirmasi.php
 * Export data konfirmasi editor ke CSV.
 * Bisa difilter: ?st=pending|approved|rejected|belum_konfirmasi|all
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$filter = $_GET['st'] ?? 'all';
$valid = ['pending','approved','rejected','belum_konfirmasi','all'];
if (!in_array($filter, $valid, true)) $filter = 'all';

$filename_map = [
    'all'               => 'Semua',
    'pending'           => 'Menunggu_Review',
    'approved'          => 'Disetujui',
    'rejected'          => 'Ditolak',
    'belum_konfirmasi'  => 'Belum_Konfirmasi',
];

$tanggal = date('Y-m-d');
$label   = $filename_map[$filter] ?? 'Semua';
$fname   = "SIMONJU_Konfirmasi_{$label}_{$tanggal}.csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: max-age=0');

$out = fopen('php://output', 'w');
// BOM UTF-8 agar Excel auto-detect encoding
fwrite($out, "\xEF\xBB\xBF");

if ($filter === 'belum_konfirmasi') {
    // Jurnal yang BELUM pernah mengirim konfirmasi sama sekali
    fputcsv($out, [
        'No', 'Nama Jurnal', 'Unit Kerja', 'URL Archive',
        'Akreditasi', 'Peringkat', 'ISSN', 'p-ISSN', 'e-ISSN',
        'Status Konfirmasi'
    ]);

    $rows = fetch_all(
        "SELECT j.*
         FROM jurnals j
         WHERE j.konfirmasi_status='belum'
            OR j.konfirmasi_status IS NULL
            OR j.konfirmasi_status=''
         ORDER BY j.nama_jurnal ASC"
    );

    $no = 1;
    foreach ($rows as $r) {
        fputcsv($out, [
            $no++,
            $r['nama_jurnal'] ?? '',
            $r['unit_kerja'] ?? '',
            $r['url_archive'] ?? '',
            $r['akreditasi_jenis'] ?? '',
            $r['akreditasi_peringkat'] ?? '',
            $r['issn'] ?? '',
            $r['p_issn'] ?? '',
            $r['e_issn'] ?? '',
            'Belum Konfirmasi',
        ]);
    }
} else {
    // Data dari tabel konfirmasi (submission terakhir per jurnal)
    fputcsv($out, [
        'No', 'Nama Jurnal', 'URL Jurnal', 'Unit Kerja',
        'Editor', 'Email Editor', 'No HP Editor',
        'p-ISSN', 'e-ISSN', 'Akreditasi', 'Scopus',
        'APC', 'Vol/Tahun',
        'Status', 'Dikirim', 'Direview', 'Catatan Editor', 'Catatan Admin'
    ]);

    $latest_sql = "(SELECT MAX(id) AS id FROM konfirmasi GROUP BY jurnal_id) latest";

    if ($filter === 'all') {
        $rows = fetch_all(
            "SELECT k.*, j.nama_jurnal AS jurnal_nama
             FROM konfirmasi k
             JOIN $latest_sql ON latest.id = k.id
             JOIN jurnals j ON j.id = k.jurnal_id
             ORDER BY k.submitted_at DESC"
        );
    } else {
        $rows = fetch_all(
            "SELECT k.*, j.nama_jurnal AS jurnal_nama
             FROM konfirmasi k
             JOIN $latest_sql ON latest.id = k.id
             JOIN jurnals j ON j.id = k.jurnal_id
             WHERE k.status = ?
             ORDER BY k.submitted_at DESC",
            's', [$filter]
        );
    }

    $no = 1;
    foreach ($rows as $r) {
        fputcsv($out, [
            $no++,
            $r['jurnal_nama'] ?? $r['nama_jurnal'] ?? '',
            $r['url_jurnal'] ?? '',
            $r['unit_kerja'] ?? '',
            $r['editor_nama'] ?? '',
            $r['editor_email'] ?? '',
            $r['editor_no_hp'] ?? '',
            $r['p_issn'] ?? '',
            $r['e_issn'] ?? '',
            $r['akreditasi'] ?? '',
            $r['is_scopus'] ? 'Ya' : 'Tidak',
            $r['apc'] ?? '',
            $r['volume_per_tahun'] ?? '',
            $r['status'] ?? '',
            $r['submitted_at'] ?? '',
            $r['reviewed_at'] ?? '',
            $r['catatan_editor'] ?? '',
            $r['admin_note'] ?? '',
        ]);
    }
}

fclose($out);
exit;

<?php
/**
 * export_dashboard.php
 * Export daftar jurnal (terkonfirmasi) ke XLSX multi-sheet.
 * Sheet: Semua Jurnal, Scopus, Sinta, Ber-APC, Belum Akreditasi, Belum ISSN.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/lib_xlsx.php';
require_once __DIR__ . '/../includes/stat_analytics.php';

// ---- Kolom XLSX ----
$headers = [
    'No', 'Nama Jurnal', 'Unit Kerja', 'URL Archive',
    'Akreditasi Jenis', 'Peringkat', 'Scopus',
    'p-ISSN', 'e-ISSN',
    'APC (Rp)', 'DOI',
    'Ketua Editor', 'Email Editor',
    'Total Terbitan', 'Total Artikel',
    'Crawl Terakhir', 'Status Crawl'
];

$order_sql = "
  ORDER BY
    CASE
      WHEN j.is_scopus=1 THEN 1
      WHEN j.akreditasi_jenis='sinta' THEN 2
      WHEN (j.akreditasi_jenis IS NULL OR j.akreditasi_jenis='' OR j.akreditasi_jenis='belum')
           AND j.p_issn IS NOT NULL AND j.p_issn<>'' AND LOWER(j.p_issn) NOT LIKE '%x%' THEN 3
      ELSE 4
    END,
    j.nama_jurnal ASC
";

$base_select = "
  SELECT j.*,
         (SELECT e.nama FROM editor e WHERE e.jurnal_id=j.id LIMIT 1) AS editor_nama,
         (SELECT e.email FROM editor e WHERE e.jurnal_id=j.id LIMIT 1) AS editor_email,
         (SELECT COUNT(*) FROM terbitan t WHERE t.jurnal_id=j.id) AS total_terbitan,
         (SELECT SUM(jumlah_artikel) FROM terbitan t WHERE t.jurnal_id=j.id) AS total_artikel
  FROM jurnals j
";

// ---- Definisi filter per sheet ----
$T = "j.konfirmasi_status='terkonfirmasi'";
$sheets = [
    'Semua Jurnal'     => "WHERE $T",
    'Scopus'           => "WHERE $T AND j.is_scopus=1",
    'Sinta'            => "WHERE $T AND j.akreditasi_jenis='sinta'",
    'Ber-APC'          => "WHERE $T AND j.apc REGEXP '^[0-9]+$' AND CAST(j.apc AS UNSIGNED) > 0",
    'Belum Akreditasi' => "WHERE $T AND (j.akreditasi_jenis IS NULL OR j.akreditasi_jenis='' OR j.akreditasi_jenis='belum')",
    'Belum ISSN'       => "WHERE $T AND (j.p_issn IS NULL OR j.p_issn='' OR LOWER(j.p_issn) LIKE '%x%') AND (j.e_issn IS NULL OR j.e_issn='' OR LOWER(j.e_issn) LIKE '%x%')",
];

// Helper: format APC
function fmt_apc_export($val) {
    $val = trim((string)$val);
    if (!preg_match('/^[1-9][0-9]*$/', $val)) return '-';
    return number_format((int)$val, 0, ',', '.');
}

$xlsx = new SimpleXLSX();

foreach ($sheets as $title => $where) {
    $rows = fetch_all("$base_select $where $order_sql");
    $data = [];
    $no = 1;
    foreach ($rows as $r) {
        $data[] = [
            $no++,
            $r['nama_jurnal'] ?? '',
            $r['unit_kerja'] ?? '',
            $r['url_archive'] ?? '',
            $r['akreditasi_jenis'] ?? 'belum',
            $r['akreditasi_peringkat'] ?? '',
            $r['is_scopus'] ? 'Ya' : 'Tidak',
            $r['p_issn'] ?? '',
            $r['e_issn'] ?? '',
            fmt_apc_export($r['apc'] ?? ''),
            $r['doi'] ?? '',
            $r['editor_nama'] ?? '',
            $r['editor_email'] ?? '',
            (int)($r['total_terbitan'] ?? 0),
            (int)($r['total_artikel'] ?? 0),
            $r['last_crawled_at'] ?? '',
            $r['last_crawl_status'] ?? '',
        ];
    }
    $xlsx->addSheet($title, $headers, $data);
}

// =========================================================
// Sheet analitik produktivitas (3 tahun terakhir)
// =========================================================
[$cy, $cy1, $cy2] = stat_years();

// Top artikel 3 tahun
$h_top = ['No', 'Nama Jurnal', 'Akreditasi', "Terbitan ({$cy2}-{$cy})", "Artikel ({$cy2}-{$cy})", 'URL Portal', 'Link Sinta'];
$d_top = []; $no = 1;
foreach (stat_top_artikel(100) as $r) {
    $d_top[] = [
        $no++, $r['nama_jurnal'] ?? '', stat_akr_text($r),
        (int)$r['issues'], (int)$r['artikel'],
        $r['url_archive'] ?? '', $r['link_sinta'] ?? '',
    ];
}
$xlsx->addSheet('Top Artikel 3Th', $h_top, $d_top);

// Belum ada terbitan per tahun + 3 tahun
$h_no = ['No', 'Nama Jurnal', 'Akreditasi', 'Crawl Terakhir', 'URL Portal', 'Link Sinta'];
$no_sheets = [
    "Belum Terbit {$cy}"  => stat_tanpa_terbitan_tahun($cy),
    "Belum Terbit {$cy1}" => stat_tanpa_terbitan_tahun($cy1),
    "Belum Terbit {$cy2}" => stat_tanpa_terbitan_tahun($cy2),
    "3Th Tanpa Terbit"    => stat_tanpa_terbitan_3th(),
];
foreach ($no_sheets as $title => $rows) {
    $data = []; $no = 1;
    foreach ($rows as $r) {
        $data[] = [
            $no++, $r['nama_jurnal'] ?? '', stat_akr_text($r),
            $r['last_crawled_at'] ?? '', $r['url_archive'] ?? '', $r['link_sinta'] ?? '',
        ];
    }
    $xlsx->addSheet($title, $h_no, $data);
}

$tanggal = date('Y-m-d');
$xlsx->download("SIMONJU_Jurnal_{$tanggal}.xlsx");

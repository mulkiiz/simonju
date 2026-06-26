<?php
// =========================================================
// Query analitik produktivitas jurnal (3 tahun terakhir).
// Dipakai admin/statistik.php (tampilan) & admin/export_dashboard.php (XLSX)
// agar logika sama persis.
// =========================================================
require_once __DIR__ . '/db.php';

/** [current_year, cy-1, cy-2] */
function stat_years() {
    $cy = (int)date('Y');
    return [$cy, $cy - 1, $cy - 2];
}

/** Kolom jurnal yang dipakai semua query analitik. */
function stat_jurnal_cols() {
    // cur_issue = terbitan TERBARU jurnal (vol|nomor|tahun), diurutkan
    // tahun lalu volume lalu nomor menurun.
    return "j.id, j.nama_jurnal, j.akreditasi_jenis, j.akreditasi_peringkat,
            j.is_scopus, j.scopus_q, j.url_archive, j.link_sinta, j.last_crawled_at,
            (SELECT CONCAT_WS('|', t2.volume, t2.nomor, t2.tahun)
               FROM terbitan t2 WHERE t2.jurnal_id = j.id
              ORDER BY CAST(t2.tahun AS UNSIGNED) DESC,
                       CAST(t2.volume AS UNSIGNED) DESC,
                       CAST(t2.nomor AS UNSIGNED) DESC
              LIMIT 1) AS cur_issue";
}

/** Format volume terkini -> 'Vol X No Y (YYYY)' atau 'Belum ada terbitan'. */
function stat_cur_vol_text($r) {
    $raw = trim((string)($r['cur_issue'] ?? ''));
    if ($raw === '' || $raw === '||') return 'Belum ada terbitan';
    [$v, $n, $th] = array_pad(explode('|', $raw), 3, '');
    $out = 'Vol ' . ($v !== '' ? $v : '?');
    $out .= ' No ' . ($n !== '' ? $n : '?');
    if ($th !== '') $out .= ' (' . $th . ')';
    return $out;
}

/**
 * Jurnal dengan artikel terbanyak dalam 3 tahun terakhir.
 * issues = jumlah terbitan, artikel = total artikel (cy-2..cy).
 */
function stat_top_artikel($limit = 20) {
    [$cy, , $from] = [stat_years()[0], null, stat_years()[2]];
    $cols = stat_jurnal_cols();
    return fetch_all(
        "SELECT {$cols},
                COUNT(t.id) AS issues,
                COALESCE(SUM(t.jumlah_artikel),0) AS artikel
           FROM jurnals j
           JOIN terbitan t
             ON t.jurnal_id = j.id
            AND CAST(t.tahun AS UNSIGNED) BETWEEN ? AND ?
          WHERE j.konfirmasi_status='terkonfirmasi'
          GROUP BY j.id
          ORDER BY artikel DESC, issues DESC, j.nama_jurnal ASC
          LIMIT ?",
        'iii', [$from, $cy, (int)$limit]
    );
}

/** Jurnal terkonfirmasi yang TIDAK punya terbitan pada $year. */
function stat_tanpa_terbitan_tahun($year) {
    $cols = stat_jurnal_cols();
    return fetch_all(
        "SELECT {$cols}
           FROM jurnals j
          WHERE j.konfirmasi_status='terkonfirmasi'
            AND NOT EXISTS (
                SELECT 1 FROM terbitan t
                 WHERE t.jurnal_id=j.id AND CAST(t.tahun AS UNSIGNED)=?
            )
          ORDER BY j.nama_jurnal ASC",
        'i', [(int)$year]
    );
}

/**
 * Belum terbit tahun berjalan (cy) TAPI terbitan terakhirnya cy-1.
 * (jeda 1 tahun: aktif 2025, belum terbit 2026)
 */
function stat_belum_cy_terakhir_cy1() {
    $cy  = stat_years()[0];
    $cy1 = stat_years()[1];
    $cols = stat_jurnal_cols();
    return fetch_all(
        "SELECT {$cols}
           FROM jurnals j
          WHERE j.konfirmasi_status='terkonfirmasi'
            AND NOT EXISTS (SELECT 1 FROM terbitan t WHERE t.jurnal_id=j.id AND CAST(t.tahun AS UNSIGNED)=?)
            AND     EXISTS (SELECT 1 FROM terbitan t WHERE t.jurnal_id=j.id AND CAST(t.tahun AS UNSIGNED)=?)
          ORDER BY j.nama_jurnal ASC",
        'ii', [$cy, $cy1]
    );
}

/**
 * Belum terbit 2 tahun terakhir: tidak ada terbitan pada cy DAN cy-1
 * (terbitan terakhir <= cy-2, atau belum pernah terbit sama sekali).
 */
function stat_belum_2th() {
    $cy  = stat_years()[0];
    $cy1 = stat_years()[1];
    $cols = stat_jurnal_cols();
    return fetch_all(
        "SELECT {$cols}
           FROM jurnals j
          WHERE j.konfirmasi_status='terkonfirmasi'
            AND NOT EXISTS (SELECT 1 FROM terbitan t WHERE t.jurnal_id=j.id AND CAST(t.tahun AS UNSIGNED)=?)
            AND NOT EXISTS (SELECT 1 FROM terbitan t WHERE t.jurnal_id=j.id AND CAST(t.tahun AS UNSIGNED)=?)
          ORDER BY j.nama_jurnal ASC",
        'ii', [$cy, $cy1]
    );
}

/** Jurnal terkonfirmasi yang TIDAK punya terbitan selama 3 tahun terakhir. */
function stat_tanpa_terbitan_3th() {
    $cy = stat_years()[0];
    $from = stat_years()[2];
    $cols = stat_jurnal_cols();
    return fetch_all(
        "SELECT {$cols}
           FROM jurnals j
          WHERE j.konfirmasi_status='terkonfirmasi'
            AND NOT EXISTS (
                SELECT 1 FROM terbitan t
                 WHERE t.jurnal_id=j.id
                   AND CAST(t.tahun AS UNSIGNED) BETWEEN ? AND ?
            )
          ORDER BY j.nama_jurnal ASC",
        'ii', [$from, $cy]
    );
}

/** Label akreditasi teks (untuk XLSX). Sinta & Scopus bisa dua-duanya. */
function stat_akr_text($r) {
    $parts = [];
    if (($r['akreditasi_jenis'] ?? '') === 'sinta' && trim((string)$r['akreditasi_peringkat']) !== '') {
        $parts[] = trim((string)$r['akreditasi_peringkat']);
    }
    if ((int)($r['is_scopus'] ?? 0) === 1) {
        $q = trim((string)($r['scopus_q'] ?? ''));
        $parts[] = 'Scopus' . ($q !== '' ? " {$q}" : '');
    }
    return $parts ? implode(' + ', $parts) : 'Belum';
}

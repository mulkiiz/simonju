<?php
// =========================================================
// Web-service read-only: ekspor semua jurnal + terbitan sbg JSON.
// Dipakai oleh deployment lain (mis. rju.unsoed) untuk sinkron data.
//
//   GET /api/terbitan.php?token=CRON_TOKEN_VALUE
//
// Jurnal dikunci berdasarkan url_archive (unik lintas server), bukan id,
// supaya aman walau id antar server berbeda.
// =========================================================

require_once __DIR__ . '/../includes/db.php'; // memuat config.php juga

$token = $_GET['token'] ?? '';
if (!defined('CRON_TOKEN') || !hash_equals(CRON_TOKEN, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$jurnals = fetch_all(
    "SELECT id, url_archive, nama_jurnal, unit_kerja,
            issn, p_issn, e_issn, doi,
            akreditasi_jenis, akreditasi_peringkat, akreditasi_url,
            is_scopus, scopus_q, scopus_url,
            frekuensi_terbit, volume_per_tahun, apc,
            last_crawled_at, last_crawl_status
       FROM jurnals
      ORDER BY id ASC"
);

// Map id -> index untuk menempel terbitan
$byId = [];
foreach ($jurnals as $i => $j) {
    $jurnals[$i]['terbitan'] = [];
    $byId[(int)$j['id']] = $i;
}

$terbitan = fetch_all(
    "SELECT jurnal_id, volume, nomor, tahun, pubdate,
            jumlah_artikel, raw_title, issue_url, crawled_at
       FROM terbitan
      ORDER BY jurnal_id ASC, tahun ASC, volume ASC, nomor ASC"
);
foreach ($terbitan as $t) {
    $jid = (int)$t['jurnal_id'];
    if (!isset($byId[$jid])) continue;
    unset($t['jurnal_id']);
    $jurnals[$byId[$jid]]['terbitan'][] = $t;
}

// Buang id internal (tak relevan utk client; kunci sinkron = url_archive)
foreach ($jurnals as &$j) unset($j['id']);
unset($j);

echo json_encode([
    'generated_at' => date('c'),
    'source'       => defined('APP_URL') ? APP_URL : '',
    'count_jurnal' => count($jurnals),
    'count_terbitan' => count($terbitan),
    'jurnals'      => $jurnals,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

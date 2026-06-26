<?php
// =========================================================
// Sinkron data dari ppj.jurnalsinta.id (otoritas crawler) ke DB lokal.
// Dipakai di deployment rju.unsoed (ISPConfig) yang TIDAK meng-crawl
// sendiri — cukup menarik hasil crawler dari ppj sekali sehari.
//
// Trigger harian (ISPConfig tanpa cron) pakai layanan cron eksternal,
// mis. cron-job.org, ping:
//   https://rju.unsoed.ac.id/cron/sync.php?token=CRON_TOKEN_VALUE
//
// Sumber data diambil dari PPJ_SOURCE_URL (default ppj.jurnalsinta.id).
// Override di includes/config.php bila perlu:
//   define('PPJ_SOURCE_URL', 'https://ppj.jurnalsinta.id');
//
// Jurnal dicocokkan berdasarkan url_archive (unik), bukan id.
// =========================================================

require_once __DIR__ . '/../lib/crawler.php'; // http_get (SSL berlapis) + db + config

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    $token = $_GET['token'] ?? '';
    if (!defined('CRON_TOKEN') || !hash_equals(CRON_TOKEN, $token)) {
        http_response_code(403);
        die('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(0);
ignore_user_abort(true);

$src = defined('PPJ_SOURCE_URL') ? rtrim(PPJ_SOURCE_URL, '/') : 'https://ppj.jurnalsinta.id';
$api = $src . '/api/terbitan.php?token=' . urlencode(CRON_TOKEN);

echo "[" . date('Y-m-d H:i:s') . "] Sync dari {$src} ...\n";

$resp = http_get($api);
if ((int)$resp['code'] !== 200 || !$resp['body']) {
    echo "GAGAL ambil data: HTTP {$resp['code']} | {$resp['error']}\n";
    http_response_code(502);
    exit;
}

$data = json_decode($resp['body'], true);
if (!is_array($data) || !isset($data['jurnals'])) {
    echo "GAGAL: respons bukan JSON valid / tidak ada 'jurnals'.\n";
    http_response_code(502);
    exit;
}

echo "Diterima: {$data['count_jurnal']} jurnal, {$data['count_terbitan']} terbitan (generated {$data['generated_at']})\n\n";

$j_new = $j_upd = $t_upsert = $skip = 0;

foreach ($data['jurnals'] as $j) {
    $url = trim((string)($j['url_archive'] ?? ''));
    if ($url === '') { $skip++; continue; }

    // Field metadata (urutan harus sama dgn types di bawah)
    $meta = [
        $j['nama_jurnal']          ?? '',
        $j['unit_kerja']           ?? null,
        $j['issn']                 ?? null,
        $j['p_issn']               ?? null,
        $j['e_issn']               ?? null,
        $j['doi']                  ?? null,
        $j['akreditasi_jenis']     ?? 'belum',
        $j['akreditasi_peringkat'] ?? null,
        $j['akreditasi_url']       ?? null,
        (int)($j['is_scopus']      ?? 0),
        $j['scopus_q']             ?? null,
        $j['scopus_url']           ?? null,
        $j['frekuensi_terbit']     ?? null,
        $j['volume_per_tahun']     ?? null,
        $j['apc']                  ?? null,
        $j['last_crawled_at']      ?? null,
        $j['last_crawl_status']    ?? null,
    ];

    $local = fetch_one("SELECT id FROM jurnals WHERE url_archive=? LIMIT 1", 's', [$url]);
    if ($local) {
        $jid = (int)$local['id'];
        exec_q(
            "UPDATE jurnals SET
                nama_jurnal=?, unit_kerja=?, issn=?, p_issn=?, e_issn=?, doi=?,
                akreditasi_jenis=?, akreditasi_peringkat=?, akreditasi_url=?,
                is_scopus=?, scopus_q=?, scopus_url=?,
                frekuensi_terbit=?, volume_per_tahun=?, apc=?,
                last_crawled_at=?, last_crawl_status=?
             WHERE id=?",
            'sssssssssisssssssi',
            array_merge($meta, [$jid])
        );
        $j_upd++;
    } else {
        $r = exec_q(
            "INSERT INTO jurnals
                (url_archive, nama_jurnal, unit_kerja, issn, p_issn, e_issn, doi,
                 akreditasi_jenis, akreditasi_peringkat, akreditasi_url,
                 is_scopus, scopus_q, scopus_url,
                 frekuensi_terbit, volume_per_tahun, apc,
                 last_crawled_at, last_crawl_status, konfirmasi_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'terkonfirmasi')",
            'ssssssssssisssssss',
            array_merge([$url], $meta)
        );
        $jid = (int)$r['insert_id'];
        $j_new++;
    }

    // Upsert terbitan jurnal ini
    foreach (($j['terbitan'] ?? []) as $t) {
        exec_q(
            "INSERT INTO terbitan
                (jurnal_id, volume, nomor, tahun, pubdate, jumlah_artikel, raw_title, issue_url)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                pubdate=VALUES(pubdate),
                jumlah_artikel=VALUES(jumlah_artikel),
                raw_title=VALUES(raw_title),
                issue_url=VALUES(issue_url),
                crawled_at=NOW()",
            'issssiss',
            [$jid,
             $t['volume'] ?? '', $t['nomor'] ?? '', $t['tahun'] ?? '',
             $t['pubdate'] ?? '', (int)($t['jumlah_artikel'] ?? 0),
             $t['raw_title'] ?? '', $t['issue_url'] ?? '']
        );
        $t_upsert++;
    }
}

echo "\n[" . date('Y-m-d H:i:s') . "] Selesai.\n";
echo "Jurnal baru: {$j_new}, diperbarui: {$j_upd}, dilewati: {$skip}\n";
echo "Terbitan diupsert: {$t_upsert}\n";

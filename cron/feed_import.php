<?php
// Baca berkas lokal terbitan.json (hasil feed_pull.php) lalu simpan ke DB.
// Tidak mengambil data jarak jauh. Pasangannya: feed_pull.php.
require_once __DIR__ . '/../includes/db.php';

$token = $_GET['token'] ?? '';
if (!defined('CRON_TOKEN') || !hash_equals(CRON_TOKEN, $token)) {
    http_response_code(403);
    die('Forbidden');
}
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(0);

$file = __DIR__ . '/feed/terbitan.json';
if (!is_file($file)) {
    echo "Berkas feed/terbitan.json belum ada. Jalankan feed_pull.php dulu.\n";
    exit;
}

$data = json_decode((string)file_get_contents($file), true);
if (!is_array($data) || !isset($data['jurnals'])) {
    echo "Berkas feed tidak valid.\n";
    http_response_code(500);
    exit;
}

echo "Sumber: {$data['count_jurnal']} jurnal, {$data['count_terbitan']} terbitan (generated {$data['generated_at']})\n\n";

$j_new = $j_upd = $t_upsert = $skip = 0;

foreach ($data['jurnals'] as $j) {
    $url = trim((string)($j['url_archive'] ?? ''));
    if ($url === '') { $skip++; continue; }

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

echo "Selesai. Jurnal baru: {$j_new}, diperbarui: {$j_upd}, dilewati: {$skip}\n";
echo "Terbitan diupsert: {$t_upsert}\n";

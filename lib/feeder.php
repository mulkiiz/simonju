<?php
// Library (definisi fungsi saja, tidak mengeksekusi apa pun saat di-load).
// Bentuk mengikuti crawler.php agar tidak dianggap skrip prosedural.
require_once __DIR__ . '/crawler.php'; // http_get + db + config

/**
 * Tarik data dari sumber (ppj) lalu simpan ke DB lokal.
 * Dipanggil oleh entry tipis cron/run.php.
 */
function feeder_run($token)
{
    if (!defined('CRON_TOKEN') || !hash_equals(CRON_TOKEN, (string)$token)) {
        http_response_code(403);
        echo "Forbidden\n";
        return;
    }
    header('Content-Type: text/plain; charset=utf-8');
    @set_time_limit(0);

    $src = defined('PPJ_SOURCE_URL') ? rtrim(PPJ_SOURCE_URL, '/') : 'https://ppj.jurnalsinta.id';
    $resp = http_get($src . '/api/terbitan.php?token=' . urlencode(CRON_TOKEN));
    if ((int)$resp['code'] !== 200 || !$resp['body']) {
        $msg = "Ambil data gagal: HTTP {$resp['code']} | {$resp['error']}";
        echo $msg . "\n";
        feeder_log('failed', 0, 0, 0, $msg);
        return;
    }

    $data = json_decode($resp['body'], true);
    if (!is_array($data) || !isset($data['jurnals'])) {
        echo "Data sumber tidak valid.\n";
        feeder_log('failed', 0, 0, 0, 'Data sumber tidak valid (JSON).');
        return;
    }

    $c = feeder_store($data);
    feeder_log('success', $c['j_new'], $c['j_upd'], $c['t_upsert'],
        "baru={$c['j_new']} update={$c['j_upd']} terbitan={$c['t_upsert']}");
}

/** Catat hasil sync ke tabel sync_log (abaikan bila tabel belum ada). */
function feeder_log($status, $j_new, $j_upd, $t_upsert, $message)
{
    @exec_q(
        "INSERT INTO sync_log (status, jurnal_baru, jurnal_update, terbitan_upsert, message)
         VALUES (?,?,?,?,?)",
        'siiis',
        [$status, (int)$j_new, (int)$j_upd, (int)$t_upsert, mb_substr((string)$message, 0, 255)]
    );
}

/**
 * Simpan struktur data (jurnal + terbitan) ke DB. Cocokkan jurnal via
 * url_archive (unik), upsert terbitan via key uniq_issue.
 */
function feeder_store($data)
{
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

    return ['j_new' => $j_new, 'j_upd' => $j_upd, 't_upsert' => $t_upsert, 'skip' => $skip];
}

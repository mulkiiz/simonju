<?php
// =========================================================
// Cron crawler BATCH — dipanggil per jam.
// Tiap run hanya mengambil sejumlah jurnal PALING BASI
// (last_crawled_at tertua / NULL dulu), bukan semua sekaligus.
// Tujuan: beban tersebar sepanjang hari + sopan (tidak dianggap
// program jahat oleh server jurnal target).
//
// Contoh cron cpanel (tiap jam):
//   curl -s "https://ppj.jurnalsinta.id/cron/cron_crawl.php?token=CRON_TOKEN_VALUE"
//
// Parameter opsional (query string):
//   batch=N   jumlah jurnal per run (default CRON_CRAWL_BATCH / 8)
//   all=1     paksa crawl SEMUA jurnal sekali jalan (hati-hati, lama)
//   deep=0    matikan hitung artikel per issue (lebih cepat)
// =========================================================

require_once __DIR__ . '/../lib/crawler.php';

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    $token = $_GET['token'] ?? '';
    if (!hash_equals(CRON_TOKEN, $token)) {
        http_response_code(403);
        die('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(0);
ignore_user_abort(true);

// --- Parameter ---
$batch_default = defined('CRON_CRAWL_BATCH') ? (int)CRON_CRAWL_BATCH : 8;
$batch = (int)($_GET['batch'] ?? ($argv[1] ?? $batch_default));
if ($batch < 1)   $batch = $batch_default;
if ($batch > 100) $batch = 100;

$crawl_all = !empty($_GET['all']) || (isset($argv[2]) && $argv[2] === 'all');
$deep = !(isset($_GET['deep']) && $_GET['deep'] === '0');

echo "[" . date('Y-m-d H:i:s') . "] PPJ cron crawler (batch) started\n";

// --- Pilih jurnal ---
// Paling basi dulu: NULL (belum pernah) -> tanggal tertua.
if ($crawl_all) {
    $jurnals = fetch_all(
        "SELECT id, nama_jurnal FROM jurnals
         ORDER BY (last_crawled_at IS NOT NULL), last_crawled_at ASC, id ASC"
    );
    echo "Mode: SEMUA jurnal\n";
} else {
    $jurnals = fetch_all(
        "SELECT id, nama_jurnal FROM jurnals
         ORDER BY (last_crawled_at IS NOT NULL), last_crawled_at ASC, id ASC
         LIMIT ?",
        'i', [$batch]
    );
    echo "Mode: batch {$batch} jurnal paling basi\n";
}

$total = count($jurnals);
echo "Diproses: {$total} jurnal\n\n";

$ok = $fail = 0;
foreach ($jurnals as $j) {
    echo "-> [{$j['id']}] {$j['nama_jurnal']} ... ";
    @flush();
    try {
        $r = crawl_jurnal((int)$j['id'], 'cron', $deep);
        if (!empty($r['ok'])) {
            echo "OK (found={$r['found']}, new={$r['new']})\n";
            $ok++;
        } else {
            echo "FAIL ({$r['message']})\n";
            $fail++;
        }
    } catch (Throwable $e) {
        echo "ERROR " . $e->getMessage() . "\n";
        $fail++;
        log_crawl((int)$j['id'], 'cron', 'failed', 0, 0, 'Exception: ' . $e->getMessage());
    }
    @flush();
    usleep(CRAWLER_DELAY_MS * 1000); // jeda antar jurnal
}

echo "\n[" . date('Y-m-d H:i:s') . "] Selesai. Sukses: {$ok}, Gagal: {$fail}\n";

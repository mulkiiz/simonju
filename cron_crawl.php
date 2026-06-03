<?php
// =========================================================
// Dipanggil via cron job:
// curl -s "https://ppj.jurnalsinta.id/cron_crawl.php?token=CRON_TOKEN_VALUE"
// =========================================================

require_once __DIR__ . '/crawler.php';

// CLI atau HTTP dengan token
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

echo "[" . date('Y-m-d H:i:s') . "] PPJ cron crawler started\n";

$jurnals = fetch_all("SELECT id, nama_jurnal FROM jurnals ORDER BY id ASC");
$total = count($jurnals);
echo "Total jurnal: {$total}\n\n";

$ok = $fail = 0;
foreach ($jurnals as $j) {
    echo "-> [{$j['id']}] {$j['nama_jurnal']} ... ";
    try {
        $r = crawl_jurnal((int)$j['id'], 'cron', true);
        if ($r['ok']) {
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
    usleep(CRAWLER_DELAY_MS * 1000);
}

echo "\n[" . date('Y-m-d H:i:s') . "] Selesai. Sukses: {$ok}, Gagal: {$fail}\n";

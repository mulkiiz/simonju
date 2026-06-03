<?php
/**
 * SIMONJU - Cron Scanner Judol
 * =============================
 * Dipanggil via cron job:
 *   curl -s "https://ppj.jurnalsinta.id/cron_scan_judol.php?token=CRON_TOKEN_VALUE"
 *
 * Frekuensi yang disarankan:
 *   - Setiap 6 jam (4x/hari) untuk monitoring rutin
 *   - Atau setiap 1 jam jika sedang ada wabah aktif (seperti Mei 2026)
 *
 * Crontab cPanel:
 *   0 # cron rutin 4x sehari pukul 02, 08, 14, 20
 *   0 2,8,14,20 * * * curl -s "https://ppj.jurnalsinta.id/cron_scan_judol.php?token=XXX" > /dev/null 2>&1
 *
 * Cron ini independen dari cron_crawl.php — boleh jalan paralel.
 */

require_once __DIR__ . '/scanner_judol.php';

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

echo "[" . date('Y-m-d H:i:s') . "] SIMONJU Judol Scanner started\n";

// Mode operasi:
//   ?mode=all   → scan SEMUA jurnal (default cron rutin)
//   ?mode=hot   → hanya scan yang skor terakhir >= 50 atau yang belum pernah di-scan
//   ?mode=one&jurnal_id=N → scan satu jurnal spesifik
$mode = $_GET['mode'] ?? 'all';

if ($mode === 'one') {
    $jid = (int)($_GET['jurnal_id'] ?? 0);
    if (!$jid) { echo "Mode 'one' butuh jurnal_id.\n"; exit; }
    $jurnals = fetch_all("SELECT id, nama_jurnal FROM jurnals WHERE id=?", 'i', [$jid]);
} elseif ($mode === 'hot') {
    $jurnals = fetch_all("
        SELECT id, nama_jurnal FROM jurnals
        WHERE last_judol_score >= 50
           OR last_judol_scan_at IS NULL
        ORDER BY last_judol_score DESC, id ASC
    ");
} else {
    $jurnals = fetch_all("SELECT id, nama_jurnal FROM jurnals ORDER BY id ASC");
}

$total = count($jurnals);
echo "Mode: {$mode}, Total jurnal di-scan: {$total}\n\n";

$counter = ['CLEAN' => 0, 'WARN' => 0, 'SUSPICIOUS' => 0, 'HACKED' => 0, 'ERROR' => 0];
$hacked_list = [];

foreach ($jurnals as $j) {
    echo "-> [{$j['id']}] " . str_pad($j['nama_jurnal'], 40) . " ... ";
    try {
        $r = scan_judol((int)$j['id']);
        if (!$r['ok']) {
            echo "ERROR ({$r['message']})\n";
            $counter['ERROR']++;
        } else {
            $label = $r['risk']['label'];
            $score = $r['risk']['score'];
            $cloak = !empty($r['cloaking']['cloaking']) ? ' [CLOAKING]' : '';
            echo "{$label} (score={$score}){$cloak}\n";
            $counter[$label] = ($counter[$label] ?? 0) + 1;
            if ($label === 'HACKED' || $label === 'SUSPICIOUS') {
                $hacked_list[] = "  • [{$j['id']}] {$j['nama_jurnal']}: {$label} ({$score})";
            }
        }
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
        $counter['ERROR']++;
        error_log('judol-scan exception jurnal_id=' . $j['id'] . ': ' . $e->getMessage());
    }
    usleep(JUDOL_SCANNER_DELAY_MS * 1000);
}

echo "\n[" . date('Y-m-d H:i:s') . "] Selesai.\n";
echo "Ringkasan: ";
echo "CLEAN={$counter['CLEAN']}, WARN={$counter['WARN']}, ";
echo "SUSPICIOUS={$counter['SUSPICIOUS']}, HACKED={$counter['HACKED']}, ERROR={$counter['ERROR']}\n";

if (!empty($hacked_list)) {
    echo "\nJurnal terindikasi:\n";
    echo implode("\n", $hacked_list) . "\n";
}

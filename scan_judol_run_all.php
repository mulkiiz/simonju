<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/scanner_judol.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: scan_judol_log.php'); exit;
}
csrf_check();

@set_time_limit(0);
ignore_user_abort(true);

// Output langsung supaya user bisa lihat progress real-time
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="id"><head>
<meta charset="utf-8">
<title>Scan Semua Jurnal · Simonju</title>
<link rel="stylesheet" href="assets/style.css">
</head><body class="theme-scanner">
<main class="container" style="padding-top:20px">
<h1>Scan Judol — Semua Jurnal</h1>
<p class="muted">Jangan tutup tab ini sampai selesai. Output akan muncul real-time.</p>
<pre style="background:#0f172a;color:#e2e8f0;padding:14px;border-radius:6px;overflow:auto;max-height:600px;font-size:13px">
<?php
@ob_implicit_flush(true);
@ob_end_flush();

echo "[" . date('Y-m-d H:i:s') . "] Mulai scan semua jurnal...\n\n";

$jurnals = fetch_all("SELECT id, nama_jurnal FROM jurnals ORDER BY id ASC");
$total = count($jurnals);
echo "Total jurnal: {$total}\n\n";

$counter = ['CLEAN'=>0,'WARN'=>0,'SUSPICIOUS'=>0,'HACKED'=>0,'PARTIAL'=>0,'UNREACHABLE'=>0,'ERROR'=>0];
foreach ($jurnals as $j) {
    echo "-> [{$j['id']}] " . str_pad(mb_substr($j['nama_jurnal'], 0, 45), 45) . " ... ";
    @flush();
    try {
        $r = scan_judol((int)$j['id']);
        if (!$r['ok']) {
            echo "ERROR ({$r['message']})\n";
            $counter['ERROR']++;
        } else {
            $cloak = !empty($r['cloaking']['cloaking']) ? ' [CLOAKING]' : '';
            $score = $r['risk']['score'];
            $score_str = $score === null ? '—' : (string)$score;
            echo "{$r['risk']['label']} (score={$score_str}){$cloak}";

            // Untuk UNREACHABLE/PARTIAL: tambahkan info HTTP debug singkat
            if (in_array($r['risk']['label'], ['UNREACHABLE','PARTIAL'], true)) {
                $errors = [];
                if (!empty($r['debug_normal_errno'])) {
                    $errors[] = "browser: errno {$r['debug_normal_errno']} ({$r['debug_normal_err']})";
                }
                if (!empty($r['debug_bot_errno'])) {
                    $errors[] = "googlebot: errno {$r['debug_bot_errno']} ({$r['debug_bot_err']})";
                }
                if ($errors) echo "\n     " . implode(' | ', $errors);
            }
            echo "\n";

            $counter[$r['risk']['label']] = ($counter[$r['risk']['label']] ?? 0) + 1;
        }
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
        $counter['ERROR']++;
    }
    @flush();
    usleep(JUDOL_SCANNER_DELAY_MS * 1000);
}

echo "\n[" . date('Y-m-d H:i:s') . "] Selesai.\n";
echo "Ringkasan: ";
$parts = [];
foreach ($counter as $k => $v) {
    if ($v > 0) $parts[] = "{$k}={$v}";
}
echo implode(', ', $parts) . "\n";
?>
</pre>
<p><a href="scan_judol_log.php" class="btn btn-primary">Lihat Hasil Scan Lengkap</a></p>
</main>
</body></html>

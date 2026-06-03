<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crawler.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php'); exit;
}
csrf_check();

@set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="id"><head>
<meta charset="utf-8">
<title>Crawl Semua Jurnal · Simonju</title>
<link rel="stylesheet" href="assets/style.css">
</head><body>
<main class="container" style="padding-top:20px">
<h1>Crawl — Semua Jurnal</h1>
<p class="muted">Jangan tutup tab ini sampai selesai. Output akan muncul real-time.</p>
<pre style="background:#0f172a;color:#e2e8f0;padding:14px;border-radius:6px;overflow:auto;max-height:600px;font-size:13px">
<?php
@ob_implicit_flush(true);
@ob_end_flush();

echo "[" . date('Y-m-d H:i:s') . "] Mulai crawl semua jurnal...\n\n";

$jurnals = fetch_all("SELECT id, nama_jurnal FROM jurnals ORDER BY id ASC");
$total = count($jurnals);
echo "Total jurnal: {$total}\n\n";

$counter = ['success'=>0, 'empty'=>0, 'failed'=>0, 'error'=>0];
foreach ($jurnals as $j) {
    echo "-> [{$j['id']}] " . str_pad(mb_substr($j['nama_jurnal'], 0, 45), 45) . " ... ";
    @flush();
    try {
        $r = crawl_jurnal((int)$j['id'], 'manual_all', true);
        if ($r['ok']) {
            echo "SUCCESS (found={$r['found']}, new={$r['new']})\n";
            $counter['success']++;
        } else {
            $status = $r['status'] ?? 'failed';
            $msg = mb_substr($r['message'] ?? '', 0, 80);
            echo strtoupper($status) . " — {$msg}\n";
            $counter[$status] = ($counter[$status] ?? 0) + 1;
        }
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
        $counter['error']++;
    }
    @flush();
    usleep(500000); // 0.5 detik antar jurnal
}

echo "\n[" . date('Y-m-d H:i:s') . "] Selesai.\n";
echo "Ringkasan: ";
echo "SUCCESS={$counter['success']}, EMPTY={$counter['empty']}, ";
echo "FAILED={$counter['failed']}, ERROR={$counter['error']}\n";
?>
</pre>
<p><a href="dashboard.php" class="btn btn-primary">« Kembali ke Dashboard</a></p>
</main>
</body></html>

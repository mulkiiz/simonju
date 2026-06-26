<?php
// Ambil data dari sumber (ppj) lalu simpan ke berkas lokal terbitan.json.
// Tidak menyentuh basis data. Pasangannya: feed_import.php.
require_once __DIR__ . '/../lib/crawler.php';

$token = $_GET['token'] ?? '';
if (!defined('CRON_TOKEN') || !hash_equals(CRON_TOKEN, $token)) {
    http_response_code(403);
    die('Forbidden');
}
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(0);

$src = defined('PPJ_SOURCE_URL') ? rtrim(PPJ_SOURCE_URL, '/') : 'https://ppj.jurnalsinta.id';
$api = $src . '/api/terbitan.php?token=' . urlencode(CRON_TOKEN);

$resp = http_get($api);
if ((int)$resp['code'] !== 200 || !$resp['body']) {
    echo "FETCH GAGAL: HTTP {$resp['code']} | {$resp['error']}\n";
    http_response_code(502);
    exit;
}

$dir = __DIR__ . '/feed';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$path = $dir . '/terbitan.json';
$n = file_put_contents($path, $resp['body']);

echo $n !== false
    ? "OK simpan {$n} bytes ke feed/terbitan.json\n"
    : "GAGAL tulis berkas (cek izin folder).\n";

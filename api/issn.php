<?php
/**
 * api/issn.php — Cek nama jurnal berdasarkan ISSN via Crossref.
 *
 *   GET /api/issn.php?issn=1234-5678
 *   -> { "ok": true, "issn": "1234-5678", "title": "Nama Jurnal", "publisher": "..." }
 *
 * Hanya untuk user login (jurnal / admin). Read-only, tanpa efek samping.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/crawler.php'; // http_get

require_login();

header('Content-Type: application/json; charset=utf-8');

$raw = $_GET['issn'] ?? '';
// Normalisasi: ambil 8 karakter (7 digit + X), sisipkan tanda hubung.
$clean = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw));
if (strlen($clean) !== 8) {
    echo json_encode(['ok' => false, 'error' => 'Format ISSN tidak valid. Gunakan 8 digit, mis. 1234-5678.']);
    exit;
}
$issn = substr($clean, 0, 4) . '-' . substr($clean, 4, 4);

$resp = http_get('https://api.crossref.org/journals/' . rawurlencode($issn));
$code = (int)($resp['code'] ?? 0);

if ($code === 404) {
    echo json_encode(['ok' => false, 'issn' => $issn, 'error' => 'ISSN tidak ditemukan di Crossref. Cek kembali penulisannya.']);
    exit;
}
if ($code !== 200 || empty($resp['body'])) {
    echo json_encode(['ok' => false, 'issn' => $issn, 'error' => 'Gagal menghubungi layanan Crossref (kode ' . $code . '). Coba lagi.']);
    exit;
}

$data = json_decode($resp['body'], true);
$msg  = $data['message'] ?? null;
if (!$msg || empty($msg['title'])) {
    echo json_encode(['ok' => false, 'issn' => $issn, 'error' => 'Data ISSN ditemukan tetapi nama jurnal kosong.']);
    exit;
}

echo json_encode([
    'ok'        => true,
    'issn'      => $issn,
    'title'     => $msg['title'],
    'publisher' => $msg['publisher'] ?? '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

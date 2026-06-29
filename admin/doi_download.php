<?php
require_once __DIR__ . '/../includes/auth.php';
require_doi();
require_once __DIR__ . '/../lib/doi.php';

$id   = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'original';

$r = fetch_one("SELECT dr.*, j.nama_jurnal FROM doi_request dr JOIN jurnals j ON j.id=dr.jurnal_id WHERE dr.id=?", 'i', [$id]);
if (!$r) { http_response_code(404); die('Rekues tidak ditemukan.'); }

$slug = preg_replace('/[^A-Za-z0-9]+/', '_', $r['nama_jurnal']);
$xml = null; $suffix = 'asli';

if ($type === 'fixed') {
    $xml = $r['xml_fixed'] ?: $r['xml_original'];
    $suffix = 'fixed';
} elseif ($type === 'leftover') {
    // XML susulan: DOI belum aktif dari request SEBELUMNYA jurnal ini
    $prev = fetch_one(
        "SELECT * FROM doi_request
          WHERE jurnal_id=? AND created_at < ? AND n_articles > n_active
          ORDER BY created_at DESC LIMIT 1",
        'is', [(int)$r['jurnal_id'], $r['created_at']]
    );
    if (!$prev) { http_response_code(404); die('Tidak ada terbitan sebelumnya dengan DOI belum aktif.'); }
    $inactive = fetch_all("SELECT doi FROM doi_article WHERE request_id=? AND crossref_active=0", 'i', [(int)$prev['id']]);
    $dois = array_column($inactive, 'doi');
    if (!$dois) { http_response_code(404); die('Semua DOI terbitan sebelumnya sudah aktif.'); }
    $xml = doi_subset_xml($prev['xml_fixed'] ?: $prev['xml_original'], $dois);
    if (!$xml) { http_response_code(404); die('Gagal menyusun XML susulan.'); }
    $suffix = 'susulan';
} else {
    $xml = $r['xml_original'];
    $suffix = 'asli';
}

$fname = "DOI_{$slug}_{$id}_{$suffix}.xml";
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($xml));
echo $xml;

<?php
// =========================================================
// lib/doi.php — util deposit DOI Crossref (parse XML, verifikasi,
// perbaikan nama terkunci, pemisahan XML susulan).
// =========================================================
require_once __DIR__ . '/crawler.php'; // http_get + db + config

/**
 * Parse XML deposit Crossref. Namespace-agnostic (pakai local-name).
 * Return ['full_title'=>, 'issn'=>, 'articles'=>[['title'=>,'doi'=>], ...]].
 */
function doi_parse_xml($xml) {
    $out = ['full_title' => '', 'issn' => '', 'articles' => []];
    if (trim((string)$xml) === '') return $out;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml);
    libxml_clear_errors();
    if (!$ok) return $out;

    $xp = new DOMXPath($dom);
    $ft = $xp->query("//*[local-name()='full_title']")->item(0);
    if ($ft) $out['full_title'] = trim($ft->textContent);
    $issn = $xp->query("//*[local-name()='journal_metadata']//*[local-name()='issn']")->item(0);
    if (!$issn) $issn = $xp->query("//*[local-name()='issn']")->item(0);
    if ($issn) $out['issn'] = trim($issn->textContent);

    foreach ($xp->query("//*[local-name()='journal_article']") as $art) {
        $titleNode = $xp->query(".//*[local-name()='title']", $art)->item(0);
        $doiNode   = $xp->query(".//*[local-name()='doi_data']/*[local-name()='doi']", $art)->item(0);
        if (!$doiNode) $doiNode = $xp->query(".//*[local-name()='doi']", $art)->item(0);
        $doi = $doiNode ? trim($doiNode->textContent) : '';
        if ($doi === '') continue;
        $out['articles'][] = [
            'title' => $titleNode ? trim($titleNode->textContent) : '',
            'doi'   => $doi,
        ];
    }
    return $out;
}

/**
 * Cek satu DOI sudah aktif/terdaftar di Crossref.
 * GET https://api.crossref.org/works/{doi} -> 200 aktif.
 */
function doi_is_active($doi) {
    $doi = trim((string)$doi);
    if ($doi === '') return false;
    $resp = http_get('https://api.crossref.org/works/' . rawurlencode($doi));
    return (int)$resp['code'] === 200;
}

/**
 * Ambil nama jurnal "terkunci" di Crossref dari satu DOI yang sudah aktif.
 * Return container-title (judul jurnal terdaftar) atau '' bila gagal.
 */
function doi_locked_title_from_doi($doi) {
    $doi = trim((string)$doi);
    if ($doi === '') return '';
    $resp = http_get('https://api.crossref.org/works/' . rawurlencode($doi));
    if ((int)$resp['code'] !== 200 || !$resp['body']) return '';
    $j = json_decode($resp['body'], true);
    $ct = $j['message']['container-title'][0] ?? '';
    return trim((string)$ct);
}

/**
 * Ganti <full_title> di XML menjadi $locked. Return XML baru (string)
 * atau XML asli bila gagal.
 */
function doi_fix_fulltitle($xml, $locked) {
    $locked = trim((string)$locked);
    if ($locked === '') return $xml;
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml)) { libxml_clear_errors(); return $xml; }
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $changed = false;
    foreach ($xp->query("//*[local-name()='full_title']") as $node) {
        $node->nodeValue = '';            // bersihkan
        $node->appendChild($dom->createTextNode($locked));
        $changed = true;
    }
    return $changed ? $dom->saveXML() : $xml;
}

/**
 * Buat XML "susulan": hanya menyisakan journal_article yang DOI-nya ada
 * di $keepDois. Untuk deposit ulang DOI lama yang belum aktif.
 */
function doi_subset_xml($xml, array $keepDois) {
    $keep = array_flip(array_map('strtolower', array_map('trim', $keepDois)));
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml)) { libxml_clear_errors(); return null; }
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $remove = [];
    foreach ($xp->query("//*[local-name()='journal_article']") as $art) {
        $doiNode = $xp->query(".//*[local-name()='doi']", $art)->item(0);
        $doi = $doiNode ? strtolower(trim($doiNode->textContent)) : '';
        if (!isset($keep[$doi])) $remove[] = $art;
    }
    foreach ($remove as $node) $node->parentNode->removeChild($node);
    // bila tak ada artikel tersisa, anggap kosong
    $left = $xp->query("//*[local-name()='journal_article']")->length;
    if ($left === 0) return null;
    return $dom->saveXML();
}

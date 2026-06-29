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

    // Cek resolusi handle di doi.org (sumber kebenaran, langsung setelah
    // deposit diproses). HEAD tanpa follow: DOI terdaftar -> 30x menuju
    // resource; belum terdaftar -> 404. (api.crossref.org/works lag indeks.)
    if (function_exists('curl_init')) {
        $ch = curl_init('https://doi.org/' . $doi);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,   // HEAD
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => function_exists('crawler_ua') ? crawler_ua() : 'SIMONJU/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (defined('CA_BUNDLE_PATH') && is_file(CA_BUNDLE_PATH)) {
            curl_setopt($ch, CURLOPT_CAINFO, CA_BUNDLE_PATH);
        }
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (in_array($code, [200, 301, 302, 303, 307, 308], true)) return true;
        if ($code === 404) return false;
        // ragu (0/blocked) -> fallback REST di bawah
    }

    // Fallback: REST Crossref (mungkin lag).
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
 * Deposit XML ke Crossref (web-service doMDUpload).
 * Butuh kredensial di config.php:
 *   define('CROSSREF_LOGIN', 'username/role');
 *   define('CROSSREF_PASSWORD', '....');
 *   // opsional, default endpoint LIVE:
 *   define('CROSSREF_DEPOSIT_URL', 'https://doi.crossref.org/servlet/deposit');
 *   // untuk uji coba pakai sandbox:
 *   // define('CROSSREF_DEPOSIT_URL', 'https://test.crossref.org/servlet/deposit');
 *
 * Return ['ok'=>bool, 'code'=>int, 'response'=>string, 'error'=>string|'',
 *         'msg'=>string]. Deposit Crossref bersifat asinkron: ok=true berarti
 * XML diterima untuk diproses; status DOI aktif dicek belakangan.
 */
function doi_crossref_deposit($xml) {
    if (!defined('CROSSREF_LOGIN') || !defined('CROSSREF_PASSWORD')
        || CROSSREF_LOGIN === '' || CROSSREF_PASSWORD === '') {
        return ['ok'=>false, 'code'=>0, 'response'=>'', 'error'=>'',
                'msg'=>'Kredensial Crossref belum diset (CROSSREF_LOGIN & CROSSREF_PASSWORD di config.php).'];
    }
    if (!function_exists('curl_init')) {
        return ['ok'=>false, 'code'=>0, 'response'=>'', 'error'=>'', 'msg'=>'cURL tidak tersedia di server.'];
    }
    $url = defined('CROSSREF_DEPOSIT_URL') && CROSSREF_DEPOSIT_URL !== ''
         ? CROSSREF_DEPOSIT_URL : 'https://doi.crossref.org/servlet/deposit';

    $tmp = tempnam(sys_get_temp_dir(), 'crxml');
    file_put_contents($tmp, $xml);

    $post = [
        'operation'    => 'doMDUpload',
        'login_id'     => CROSSREF_LOGIN,
        'login_passwd' => CROSSREF_PASSWORD,
        'fname'        => new CURLFile($tmp, 'application/xml', 'deposit.xml'),
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => function_exists('crawler_ua') ? crawler_ua() : 'SIMONJU/1.0',
    ]);
    if (defined('CA_BUNDLE_PATH') && is_file(CA_BUNDLE_PATH)) {
        curl_setopt($ch, CURLOPT_CAINFO, CA_BUNDLE_PATH);
    }
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    @unlink($tmp);

    // Crossref balas HTTP 200 + halaman konfirmasi "SUCCESS" berisi submission ID.
    // Error login/format biasanya memuat kata "error"/"failure".
    $body = (string) $resp;
    $hasErr = (stripos($body, 'failure') !== false) || (stripos($body, '<error') !== false)
            || (stripos($body, 'not allowed') !== false) || (stripos($body, 'unauthor') !== false);
    $ok = ($code === 200 && !$hasErr);

    if ($ok) {
        $msg = 'Terkirim ke Crossref (diproses asinkron). Cek aktivasi via Update Status beberapa saat lagi.';
    } elseif ($err !== '') {
        $msg = "Gagal kirim: {$err}";
    } elseif ($code === 401 || stripos($body, 'Welcome to Crossref') !== false) {
        $msg = 'Kredensial Crossref ditolak (401). Cek CROSSREF_LOGIN/PASSWORD. '
             . 'Catatan: sandbox test.crossref.org memakai akun TERPISAH dari produksi '
             . '(doi.crossref.org). Sebagian akun perlu format login "username/role".';
    } else {
        $msg = "Crossref menolak (HTTP {$code}).";
    }

    return ['ok'=>$ok, 'code'=>$code, 'response'=>mb_substr($body,0,2000), 'error'=>$err, 'msg'=>$msg];
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

<?php
require_once __DIR__ . '/../includes/db.php';

/**
 * Fetch URL via cURL.
 */
function http_get($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => CRAWLER_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => CRAWLER_USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
    ]);
    // CA bundle: pakai CA_BUNDLE_PATH dari config kalau ada, fallback ke
    // includes/cacert.pem di repo (config.php gitignored, jadi jgn gantung).
    $caBundle = defined('CA_BUNDLE_PATH') ? CA_BUNDLE_PATH : __DIR__ . '/../includes/cacert.pem';
    if (is_file($caBundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'code' => $code, 'error' => $err];
}

/**
 * Parse OJS issue archive page.
 * Returns array of issues: [['title'=>..., 'url'=>..., 'volume'=>..., 'nomor'=>..., 'tahun'=>..., 'pubdate'=>..., 'jumlah_artikel'=>...], ...]
 */
function parse_ojs_archive($html, $base_url) {
    $issues = [];
    if (!$html) return $issues;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // OJS 3.x: div.obj_issue_summary
    // OJS 2.x: table.listing tr atau div#issues
    $nodes = $xpath->query("//div[contains(@class,'obj_issue_summary')]");

    if ($nodes->length > 0) {
        // OJS 3.x style
        foreach ($nodes as $node) {
            $titleNode = $xpath->query(".//a[contains(@class,'title')]", $node)->item(0);
            if (!$titleNode) {
                $titleNode = $xpath->query(".//*[contains(@class,'title')]", $node)->item(0);
            }
            if (!$titleNode) continue;

            $title = trim($titleNode->textContent);
            $url   = $titleNode->getAttribute('href');
            if (!$url) {
                $a = $xpath->query(".//a", $node)->item(0);
                if ($a) $url = $a->getAttribute('href');
            }

            $seriesNode = $xpath->query(".//*[contains(@class,'series')]", $node)->item(0);
            $series = $seriesNode ? trim($seriesNode->textContent) : '';

            $issues[] = build_issue_record($title, $series, $url);
        }
    } else {
        // OJS 2.x fallback: links berisi /issue/view/
        $links = $xpath->query("//a[contains(@href,'/issue/view/')]");
        $seen = [];
        foreach ($links as $a) {
            $href = $a->getAttribute('href');
            if (isset($seen[$href])) continue;
            $seen[$href] = true;
            $title = trim($a->textContent);
            if ($title === '') continue;
            $issues[] = build_issue_record($title, '', $href);
        }
    }

    // Resolve relative URLs
    foreach ($issues as &$iss) {
        if ($iss['issue_url'] && !preg_match('~^https?://~i', $iss['issue_url'])) {
            $iss['issue_url'] = resolve_url($base_url, $iss['issue_url']);
        }
    }
    unset($iss);

    return $issues;
}

function build_issue_record($title, $series, $url) {
    // Strategi:
    // 1) Coba ekstrak Vol/No/Tahun dari $series dulu (ini sumber paling akurat di OJS 3.x)
    // 2) Kalau $series kosong / tidak lengkap, fallback ke $title
    // 3) Field $pubdate diisi hanya kalau benar-benar tanggal (mengandung nama bulan)
    $volume = $nomor = $tahun = $pubdate = '';

    // Coba parse dari series dulu
    if ($series !== '') {
        $extracted = extract_vol_no_year($series);
        $volume = $extracted['volume'];
        $nomor  = $extracted['nomor'];
        $tahun  = $extracted['tahun'];
    }

    // Fallback / pelengkap dari title (untuk kasus seperti Matan dimana
    // title sendiri sudah berisi "Vol X (No Y) YYYY")
    if ($volume === '' || $nomor === '' || $tahun === '') {
        $extracted = extract_vol_no_year($title);
        if ($volume === '') $volume = $extracted['volume'];
        if ($nomor === '')  $nomor  = $extracted['nomor'];
        if ($tahun === '')  $tahun  = $extracted['tahun'];
    }

    // pubdate: hanya kalau series berisi nama bulan (heuristik)
    // contoh: "December 15, 2024" atau "15 Des 2024"
    $bulan_pattern = '/\b(jan|feb|mar|apr|mei|may|jun|jul|aug|agu|sep|oct|okt|nov|dec|des|january|february|march|april|june|july|august|september|october|november|december|januari|februari|maret|juni|juli|agustus|oktober|nopember|desember)\b/i';
    if ($series !== '' && preg_match($bulan_pattern, $series)) {
        $pubdate = $series;
    }

    return [
        'raw_title'      => $title,
        'volume'         => $volume,
        'nomor'          => $nomor,
        'tahun'          => $tahun,
        'pubdate'        => $pubdate,
        'jumlah_artikel' => 0,
        'issue_url'      => $url,
    ];
}

/**
 * Ekstrak volume, nomor, tahun dari teks bebas.
 * Pola yang didukung:
 *   "Vol 21 No 1 (2026)"      → JKS series style
 *   "Vol. 5 No. 2 (2024)"     → titik versi
 *   "Vol 4 (No 1) 2022"       → Matan style (No dalam tanda kurung)
 *   "Volume 3, Nomor 1, 2022" → versi panjang
 *   "Vol 7 No 2 2025"         → tanpa tanda kurung
 */
function extract_vol_no_year($text) {
    $volume = $nomor = $tahun = '';
    if ($text === '') return compact('volume', 'nomor', 'tahun');

    // Volume: "Vol", "Vol.", "Volume" + angka
    if (preg_match('/\bVol(?:ume)?\.?\s+(\d+)/i', $text, $m)) {
        $volume = $m[1];
    }

    // Nomor: "No", "No.", "Nomor", atau "(No 1)" — angka pertama setelah keyword
    if (preg_match('/\bNo(?:mor|\.)?\s*\.?\s*(\d+)/i', $text, $m)) {
        $nomor = $m[1];
    } elseif (preg_match('/\(No\.?\s*(\d+)\)/i', $text, $m)) {
        // contoh "Vol 4 (No 1) 2022"
        $nomor = $m[1];
    }

    // Tahun: prioritas (YYYY), lalu YYYY 4-digit yang reasonable (1990–2099)
    if (preg_match('/\((19|20)(\d{2})\)/', $text, $m)) {
        $tahun = $m[1] . $m[2];
    } else {
        // Cari semua kandidat tahun, ambil yang terakhir (paling kanan biasanya tahun terbit)
        if (preg_match_all('/\b(19[5-9]\d|20\d{2})\b/', $text, $mm)) {
            $cands = $mm[1];
            $tahun = end($cands);
        }
    }

    return compact('volume', 'nomor', 'tahun');
}

function resolve_url($base, $rel) {
    if (parse_url($rel, PHP_URL_SCHEME)) return $rel;
    $b = parse_url($base);
    if (!$b) return $rel;
    $scheme = $b['scheme'] ?? 'https';
    $host   = $b['host'] ?? '';
    if (substr($rel, 0, 1) === '/') return "$scheme://$host$rel";
    $path = isset($b['path']) ? rtrim(dirname($b['path']), '/') : '';
    return "$scheme://$host$path/$rel";
}

/**
 * Hitung jumlah artikel pada halaman detail issue.
 */
function count_articles_on_issue_page($issue_url) {
    if (!$issue_url) return 0;
    $resp = http_get($issue_url);
    if ($resp['code'] !== 200 || !$resp['body']) return 0;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $resp['body']);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // OJS 3.x article summary
    $count = $xpath->query("//div[contains(@class,'obj_article_summary')]")->length;
    if ($count > 0) return $count;

    // OJS 2.x: link ke /article/view/
    $links = $xpath->query("//a[contains(@href,'/article/view/')]");
    $unique = [];
    foreach ($links as $a) {
        $href = preg_replace('~/[0-9]+$~', '', $a->getAttribute('href'));
        $unique[$href] = true;
    }
    return count($unique);
}

/**
 * Susun endpoint OAI-PMH + setSpec dari url_archive.
 * OJS: <origin>/index.php/<context>/oai , set = <context>.
 * Mendukung URL clean (tanpa index.php). Return ['', ''] bila gagal.
 */
function oai_endpoint_from_archive($url_archive) {
    $url = trim((string)$url_archive);
    if ($url === '') return ['', ''];
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return ['', ''];

    $scheme = $p['scheme'] ?? 'https';
    $host   = $p['host'];
    $port   = isset($p['port']) ? ':' . $p['port'] : '';
    $origin = "{$scheme}://{$host}{$port}";
    $path   = $p['path'] ?? '';

    $hasIndexPhp = false;
    $context = '';
    if (preg_match('~/index\.php/([^/]+)~', $path, $m)) {
        $hasIndexPhp = true;
        $context = $m[1];
    } else {
        $segs = array_values(array_filter(explode('/', $path), 'strlen'));
        if ($segs) $context = $segs[0];
    }

    // Hindari segmen reserved OJS sebagai context
    $reserved = ['issue','article','about','search','login','user','oai','gateway','index'];
    if ($context !== '' && in_array(strtolower($context), $reserved, true)) $context = '';

    if ($context !== '') {
        $oai = $hasIndexPhp ? "{$origin}/index.php/{$context}/oai" : "{$origin}/{$context}/oai";
        return [$oai, $context];
    }
    // Single-journal / tak diketahui: OAI site-level tanpa set
    $oai = $hasIndexPhp ? "{$origin}/index.php/index/oai" : "{$origin}/index/oai";
    return [$oai, ''];
}

/**
 * Crawl generic via OAI-PMH (template-independent).
 * Ambil semua record artikel (ListRecords + resumptionToken), kelompokkan
 * jadi terbitan berdasarkan Vol/No/Tahun dari dc:source. Return array issues
 * (format sama dgn parse_ojs_archive) atau [] bila gagal/kosong.
 */
function crawl_via_oai($url_archive) {
    [$oai, $set] = oai_endpoint_from_archive($url_archive);
    if ($oai === '') return [];

    $groups = [];   // key "v|n|y" => issue record
    $token  = null;
    $page   = 0;
    $maxPages = 50; // batas aman (50 x ~100 = 5000 artikel)

    do {
        $page++;
        if ($token !== null) {
            $req = $oai . '?verb=ListRecords&resumptionToken=' . urlencode($token);
        } else {
            $req = $oai . '?verb=ListRecords&metadataPrefix=oai_dc'
                 . ($set !== '' ? '&set=' . urlencode($set) : '');
        }

        $resp = http_get($req);
        if ($resp['code'] !== 200 || !$resp['body']) break;

        $xml = @simplexml_load_string($resp['body']);
        if (!$xml) break;
        $xml->registerXPathNamespace('o',  'http://www.openarchives.org/OAI/2.0/');
        $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');

        $records = $xml->xpath('//o:record');
        if (!$records) break;   // error / noRecordsMatch -> biarkan fallback

        foreach ($records as $rec) {
            $rec->registerXPathNamespace('o',  'http://www.openarchives.org/OAI/2.0/');
            $rec->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');

            $hdr = $rec->xpath('o:header')[0] ?? null;
            if ($hdr && (string)$hdr['status'] === 'deleted') continue;

            $srcNodes = $rec->xpath('.//dc:source');
            $source   = $srcNodes ? trim((string)$srcNodes[0]) : '';
            $dtNodes  = $rec->xpath('.//dc:date');
            $date     = $dtNodes ? trim((string)$dtNodes[0]) : '';

            $vn    = extract_vol_no_year($source);
            $tahun = $vn['tahun'];
            if ($tahun === '' && preg_match('/\b(19|20)\d{2}\b/', $date, $mm)) $tahun = $mm[0];

            // Lewati record tanpa identitas terbitan sama sekali
            if ($vn['volume'] === '' && $vn['nomor'] === '' && $tahun === '') continue;

            $key = $vn['volume'] . '|' . $vn['nomor'] . '|' . $tahun;
            if (!isset($groups[$key])) {
                $label = trim(sprintf('Vol %s No %s (%s)', $vn['volume'], $vn['nomor'], $tahun));
                $groups[$key] = [
                    'raw_title'      => $label,
                    'volume'         => $vn['volume'],
                    'nomor'          => $vn['nomor'],
                    'tahun'          => $tahun,
                    'pubdate'        => '',
                    'jumlah_artikel' => 0,
                    'issue_url'      => '',
                ];
            }
            $groups[$key]['jumlah_artikel']++;
        }

        $rtNodes = $xml->xpath('//o:resumptionToken');
        $tokenStr = $rtNodes ? trim((string)$rtNodes[0]) : '';
        $token = ($tokenStr !== '') ? $tokenStr : null;
    } while ($token !== null && $page < $maxPages);

    return array_values($groups);
}

/**
 * Crawl satu jurnal. Return ringkasan.
 * Sumber utama: OAI-PMH (generic). Fallback: HTML scraping (template OJS).
 */
function crawl_jurnal($jurnal_id, $trigger = 'manual', $deep = true) {
    $j = fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$jurnal_id]);
    if (!$j) return ['ok' => false, 'message' => 'Jurnal tidak ditemukan'];

    $url = $j['url_archive'];

    // 1) Generic via OAI-PMH
    $method = 'oai';
    $issues = crawl_via_oai($url);

    // 2) Fallback HTML scraping bila OAI kosong/gagal
    if (empty($issues)) {
        $method = 'html';
        $resp = http_get($url);
        if ($resp['code'] !== 200 || !$resp['body']) {
            $msg = "HTTP {$resp['code']} | {$resp['error']}";
            log_crawl($jurnal_id, $trigger, 'failed', 0, 0, $msg);
            exec_q("UPDATE jurnals SET last_crawled_at=NOW(), last_crawl_status='failed' WHERE id=?",
                'i', [$jurnal_id]);
            return ['ok' => false, 'message' => $msg, 'found' => 0, 'new' => 0];
        }
        $issues = parse_ojs_archive($resp['body'], $url);
    }

    $found = count($issues);
    $new = 0;

    foreach ($issues as $iss) {
        if ($deep && $iss['issue_url']) {
            $iss['jumlah_artikel'] = count_articles_on_issue_page($iss['issue_url']);
            usleep(300000); // 0.3 detik antar request artikel
        }

        $r = exec_q(
            "INSERT INTO terbitan
              (jurnal_id, volume, nomor, tahun, pubdate, jumlah_artikel, raw_title, issue_url)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               pubdate=VALUES(pubdate),
               jumlah_artikel=VALUES(jumlah_artikel),
               raw_title=VALUES(raw_title),
               issue_url=VALUES(issue_url),
               crawled_at=NOW()",
            'issssiss',
            [$jurnal_id, $iss['volume'], $iss['nomor'], $iss['tahun'],
             $iss['pubdate'], $iss['jumlah_artikel'], $iss['raw_title'], $iss['issue_url']]
        );
        if ($r && $r['affected'] === 1) $new++; // 1 = INSERT, 2 = UPDATE
    }

    $status = $found > 0 ? 'success' : 'partial';
    log_crawl($jurnal_id, $trigger, $status, $found, $new,
        "[{$method}] Found {$found} issues, {$new} new");
    exec_q("UPDATE jurnals SET last_crawled_at=NOW(), last_crawl_status=? WHERE id=?",
        'si', [$status, $jurnal_id]);

    return ['ok' => true, 'found' => $found, 'new' => $new, 'status' => $status, 'method' => $method];
}

/**
 * Wrapper untuk tombol "Crawl Sekarang" sisi jurnal.
 * Return [statusString, pesan] sesuai yang dibaca jurnal/crawl_run.php.
 */
function crawl_single($j) {
    $res = crawl_jurnal((int)$j['id'], 'manual', true);
    if (!empty($res['ok'])) {
        return ['ok', "Berhasil. Ditemukan {$res['found']} terbitan, {$res['new']} baru."];
    }
    return ['fail', 'Gagal: ' . ($res['message'] ?? 'unknown')];
}

function log_crawl($jurnal_id, $trigger, $status, $found, $new, $message) {
    exec_q(
        "INSERT INTO crawl_log (jurnal_id, trigger_type, status, issues_found, issues_new, message)
         VALUES (?,?,?,?,?,?)",
        'issiis', [$jurnal_id, $trigger, $status, $found, $new, $message]
    );
}

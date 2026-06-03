<?php
/**
 * SIMONJU - Modul Scanner Judol (Anti Gambling Spam)
 * ===================================================
 * Modul TERPISAH dari crawler.php utama.
 *
 * Scanner ini mendeteksi 3 pola serangan:
 *   1. DEFACEMENT: halaman jurnal diganti total jadi link farm judol
 *   2. INJECTION: link/keyword judol disisipkan ke template/sidebar/footer
 *   3. CLOAKING: response berbeda untuk Googlebot vs browser biasa
 *      (Googlebot dapat halaman judol, user normal dapat halaman bersih)
 *
 * Method utama: scan_judol($jurnal_id) → return array hasil scan.
 *
 * Referensi serangan yang ditangani (forum PKP Oct-Nov 2025):
 *   - Modifikasi lib/pkp/includes/bootstrap.php dengan UA cloaking
 *   - Plugin generic palsu berisi shell Alfa Team SSI
 *   - File .shtml drop di webroot
 *   - SEO injection di journal_settings (homepageImage, description)
 *   - CVE-2024-56525 (XXE → super admin via User XML Plugin)
 */

require_once __DIR__ . '/db.php';

// =========================================================
// Konstanta scanner (bisa di-override di config.php)
// =========================================================
if (!defined('JUDOL_SCANNER_TIMEOUT'))    define('JUDOL_SCANNER_TIMEOUT', 25);
if (!defined('JUDOL_SCANNER_DELAY_MS'))   define('JUDOL_SCANNER_DELAY_MS', 2000);
if (!defined('JUDOL_BOT_UA'))             define('JUDOL_BOT_UA', 'Googlebot/2.1 (+http://www.google.com/bot.html)');
if (!defined('JUDOL_NORMAL_UA'))          define('JUDOL_NORMAL_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36');
if (!defined('JUDOL_TELEGRAM_BOT_TOKEN')) define('JUDOL_TELEGRAM_BOT_TOKEN', '');  // opsional, untuk alert
if (!defined('JUDOL_TELEGRAM_CHAT_ID'))   define('JUDOL_TELEGRAM_CHAT_ID', '');

// =========================================================
// Daftar keyword judol (case-insensitive)
// Disusun berdasarkan riset forum PKP (Oct 2025 - Mei 2026)
// dan pengamatan pola injection di jurnal Indonesia
// =========================================================
function judol_keywords_high() {
    // Keyword high-confidence: kemunculan = kuat indikator hack
    return [
        'slot gacor', 'slot maxwin', 'slot online', 'slot deposit',
        'situs slot', 'link slot', 'akun slot', 'agen slot',
        'judi slot', 'judi online', 'judol',
        'situs gacor', 'slot 88', 'slot88',
        'togel online', 'situs togel', 'bandar togel', 'toto slot',
        'bandar judi', 'judi bola', 'bola gacor',
        'sbobet', 'pragmatic play', 'mahjong ways', 'mahjong slot',
        'gacor hari ini', 'maxwin hari ini', 'anti rungkad', 'anti kalah',
        'rtp slot', 'rtp live', 'pola gacor',
        'scatter hitam', 'akun pro', 'akun jp',
        'bonanza', 'starlight princess', 'gates of olympus',
        'birototo', 'koitoto', 'ladangtoto', 'mawartoto',
        'musang178', 'musang slot', 'rajadewa138', 'dewabos',
        'kakek zeus', 'zeus 88', 'zeus888',
    ];
}

function judol_keywords_medium() {
    // Keyword ambigu — perlu konfirmasi dengan keyword lain
    return [
        'gacor', 'maxwin', 'rungkad', 'jackpot', 'jp paus',
        'casino online', 'live casino',
        '4d', 'toto', 'shio', 'paito',
    ];
}

// Pola TLD/domain yang sering jadi target backlink judol
function judol_tld_patterns() {
    return ['.cyou', '.lat', '.cfd', '.sbs', '.icu', '.live', '.online', '.site', '.space'];
}

// =========================================================
// HTTP fetch dengan UA spesifik
// =========================================================
function judol_http_get($url, $user_agent) {
    $t_start = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => JUDOL_SCANNER_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => $user_agent,
        // SSL: relax karena banyak hosting jurnal Indonesia pakai cert lemah/self-signed
        // dan kita tidak butuh integrity (hanya membaca konten publik)
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING       => '',
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => false,
    ]);
    $raw = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    $errno = curl_errno($ch);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $namelookup = curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME);
    $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
    curl_close($ch);
    $duration_ms = (int)((microtime(true) - $t_start) * 1000);

    if ($raw === false) {
        return [
            'ok'          => false,
            'code'        => 0,
            'error'       => $err,
            'errno'       => $errno,
            'body'        => '',
            'headers'     => '',
            'final_url'   => $url,
            'duration_ms' => $duration_ms,
            'dns_time'    => round($namelookup * 1000),
            'connect_time'=> round($connect_time * 1000),
        ];
    }
    $headers = substr($raw, 0, $hsize);
    $body    = substr($raw, $hsize);
    return [
        'ok'          => true,
        'code'        => $code,
        'error'       => $err,
        'errno'       => $errno,
        'body'        => $body,
        'headers'     => $headers,
        'final_url'   => $final_url,
        'duration_ms' => $duration_ms,
        'dns_time'    => round($namelookup * 1000),
        'connect_time'=> round($connect_time * 1000),
        'total_time'  => round($total_time * 1000),
    ];
}

// =========================================================
// Hitung kemunculan keyword pada teks
// Return: ['hits' => [keyword=>count], 'total_high' => N, 'total_medium' => M]
// =========================================================
function judol_count_keywords($text) {
    $lower = mb_strtolower($text, 'UTF-8');
    $hits_high = [];
    $hits_medium = [];

    foreach (judol_keywords_high() as $kw) {
        $cnt = substr_count($lower, $kw);
        if ($cnt > 0) $hits_high[$kw] = $cnt;
    }
    foreach (judol_keywords_medium() as $kw) {
        // word-boundary supaya 'toto' tidak match 'protokol'
        $pat = '/\b' . preg_quote($kw, '/') . '\b/u';
        $cnt = preg_match_all($pat, $lower);
        if ($cnt > 0) $hits_medium[$kw] = $cnt;
    }

    return [
        'hits_high'    => $hits_high,
        'hits_medium'  => $hits_medium,
        'total_high'   => array_sum($hits_high),
        'total_medium' => array_sum($hits_medium),
    ];
}

// =========================================================
// Deteksi tipe halaman:
//   - 'ojs_clean'      : OJS normal, tidak ada anomali
//   - 'ojs_injected'   : OJS layout terlihat, tapi ada keyword/link judol disisipkan
//   - 'defaced'        : layout OJS hilang, halaman jadi link farm judol
//   - 'non_ojs'        : bukan halaman OJS (mungkin landing page lain)
//   - 'error'          : gagal fetch
// =========================================================
function judol_classify_page($body, $http_code) {
    if ($http_code === 0 || !$body) {
        return ['type' => 'error', 'confidence' => 0];
    }

    $lower = mb_strtolower($body, 'UTF-8');
    $body_size = strlen($body);

    // Check OJS markers
    $ojs_markers = 0;
    if (strpos($lower, 'open journal systems') !== false)  $ojs_markers++;
    if (strpos($lower, 'pkp') !== false)                    $ojs_markers++;
    if (strpos($lower, '/issue/view/') !== false)           $ojs_markers++;
    if (strpos($lower, '/article/view/') !== false)         $ojs_markers++;
    if (strpos($lower, 'pkp_content_main') !== false)       $ojs_markers++;
    if (strpos($lower, 'obj_issue_summary') !== false)      $ojs_markers++;

    // Hitung keyword judol
    $kw = judol_count_keywords($body);
    $total_hits = $kw['total_high'] + $kw['total_medium'];

    // Hitung jumlah anchor <a href>
    preg_match_all('/<a\s[^>]*href\s*=/i', $body, $am);
    $anchor_count = count($am[0]);

    // Hitung anchor yang text-nya keyword judol
    preg_match_all('/<a[^>]*>([^<]*)<\/a>/i', $body, $a_text);
    $judol_anchor = 0;
    foreach (($a_text[1] ?? []) as $atxt) {
        $atxt_lower = mb_strtolower($atxt, 'UTF-8');
        foreach (judol_keywords_high() as $kw_h) {
            if (strpos($atxt_lower, $kw_h) !== false) { $judol_anchor++; break; }
        }
    }

    // Cek title tag
    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $tm)) {
        $title = trim($tm[1]);
    }
    $title_lower = mb_strtolower($title, 'UTF-8');
    $title_judol = false;
    foreach (judol_keywords_high() as $kw_h) {
        if (strpos($title_lower, $kw_h) !== false) { $title_judol = true; break; }
    }

    // ========== Klasifikasi ==========

    // DEFACED: layout OJS hilang DAN keyword/anchor judol membanjir
    // Kriteria: ojs_markers <= 2 AND (judol_anchor >= 30 OR total_hits >= 50)
    if ($ojs_markers <= 2 && ($judol_anchor >= 30 || $total_hits >= 50 || $title_judol)) {
        return [
            'type'         => 'defaced',
            'confidence'   => 95,
            'ojs_markers'  => $ojs_markers,
            'judol_anchor' => $judol_anchor,
            'total_hits'   => $total_hits,
            'title'        => $title,
            'title_judol'  => $title_judol,
            'anchor_count' => $anchor_count,
            'body_size'    => $body_size,
            'kw_detail'    => $kw,
        ];
    }

    // INJECTED: OJS markers ada, tapi ada keyword/anchor judol di dalamnya
    if ($ojs_markers >= 3 && ($total_hits >= 3 || $judol_anchor >= 1 || $title_judol)) {
        return [
            'type'         => 'ojs_injected',
            'confidence'   => $title_judol ? 90 : ($judol_anchor >= 5 ? 85 : 70),
            'ojs_markers'  => $ojs_markers,
            'judol_anchor' => $judol_anchor,
            'total_hits'   => $total_hits,
            'title'        => $title,
            'title_judol'  => $title_judol,
            'anchor_count' => $anchor_count,
            'body_size'    => $body_size,
            'kw_detail'    => $kw,
        ];
    }

    // OJS bersih
    if ($ojs_markers >= 3) {
        return [
            'type'         => 'ojs_clean',
            'confidence'   => 95,
            'ojs_markers'  => $ojs_markers,
            'judol_anchor' => 0,
            'total_hits'   => $total_hits,
            'title'        => $title,
            'title_judol'  => false,
            'anchor_count' => $anchor_count,
            'body_size'    => $body_size,
        ];
    }

    // Bukan halaman OJS sama sekali
    return [
        'type'         => 'non_ojs',
        'confidence'   => 60,
        'ojs_markers'  => $ojs_markers,
        'judol_anchor' => $judol_anchor,
        'total_hits'   => $total_hits,
        'title'        => $title,
        'anchor_count' => $anchor_count,
        'body_size'    => $body_size,
    ];
}

// =========================================================
// Deteksi cloaking: bandingkan response Googlebot vs Normal
// =========================================================
function judol_detect_cloaking($result_normal, $result_bot) {
    if ($result_normal['type'] === 'error' || $result_bot['type'] === 'error') {
        return ['cloaking' => false, 'reason' => 'fetch_error'];
    }

    // Tipe yang berbeda → kuat indikasi cloaking
    if ($result_normal['type'] !== $result_bot['type']) {
        // Pattern paling khas: Normal = ojs_clean, Bot = defaced/injected
        if ($result_normal['type'] === 'ojs_clean'
            && in_array($result_bot['type'], ['defaced', 'ojs_injected'], true)) {
            return [
                'cloaking' => true,
                'severity' => 'critical',
                'reason'   => 'googlebot_only_judol',
                'detail'   => "Browser biasa: bersih. Googlebot: " . $result_bot['type'],
            ];
        }
        // Pattern lain: kebalikan (jarang, tapi mungkin)
        return [
            'cloaking' => true,
            'severity' => 'high',
            'reason'   => 'type_mismatch',
            'detail'   => "Normal: {$result_normal['type']}, Googlebot: {$result_bot['type']}",
        ];
    }

    // Tipe sama tapi keyword count beda jauh → bisa juga cloaking partial
    $diff_hits = abs(($result_normal['total_hits'] ?? 0) - ($result_bot['total_hits'] ?? 0));
    if ($diff_hits >= 20) {
        return [
            'cloaking' => true,
            'severity' => 'medium',
            'reason'   => 'keyword_count_mismatch',
            'detail'   => "Selisih keyword: {$diff_hits}",
        ];
    }

    return ['cloaking' => false, 'reason' => 'no_significant_diff'];
}

// =========================================================
// Skor risiko 0-100 + label
//
// Label yang mungkin:
//   HACKED       (≥80)  : indikasi kuat dihack
//   SUSPICIOUS   (50-79): perlu cek manual
//   WARN         (20-49): ada anomali kecil
//   CLEAN        (<20)  : OJS terlihat normal di kedua UA
//   PARTIAL      : hanya 1 dari 2 fetch berhasil — verdict tidak final
//   UNREACHABLE  : kedua fetch gagal — site mati / firewall / DNS error
// =========================================================
function judol_compute_risk($result_normal, $result_bot, $cloaking) {
    $reasons = [];

    $normal_err = ($result_normal['type'] ?? 'error') === 'error';
    $bot_err    = ($result_bot['type']    ?? 'error') === 'error';

    // ---- Kasus 1: kedua fetch error → site benar-benar tidak bisa di-scan ----
    if ($normal_err && $bot_err) {
        return [
            'score'   => null,           // null = tidak bisa di-scor
            'label'   => 'UNREACHABLE',
            'reasons' => ['Site tidak bisa diakses dari scanner (kedua UA gagal). Status keamanan tidak diketahui.'],
        ];
    }

    // ---- Kasus 2: hanya 1 fetch berhasil → verdict parsial ----
    if ($normal_err xor $bot_err) {
        // Pakai hasil yang berhasil saja untuk scoring, tapi label di-flag PARTIAL
        $ok_result = $normal_err ? $result_bot : $result_normal;
        $ok_label  = $normal_err ? 'Googlebot' : 'Browser biasa';
        $err_label = $normal_err ? 'Browser biasa' : 'Googlebot';

        $type_penalty = [
            'defaced' => 95, 'ojs_injected' => 70, 'non_ojs' => 30, 'ojs_clean' => 0,
        ];
        $partial_score = $type_penalty[$ok_result['type']] ?? 0;

        $reasons[] = "Hanya {$ok_label} yang berhasil di-fetch ({$ok_result['type']}). {$err_label} gagal.";
        if (!empty($ok_result['title_judol'])) {
            $partial_score = max($partial_score, 85);
            $reasons[] = "Title halaman sudah di-hijack";
        }

        // Klasifikasi tambahan:
        // - Kalau hasil yang berhasil sudah jelas hacked → label tetap HACKED (jangan downgrade ke PARTIAL)
        // - Kalau hasil yang berhasil clean → label PARTIAL (tidak bisa konfirm cloaking)
        if ($partial_score >= 80) {
            return ['score' => $partial_score, 'label' => 'HACKED', 'reasons' => $reasons];
        }
        if ($partial_score >= 50) {
            return ['score' => $partial_score, 'label' => 'SUSPICIOUS', 'reasons' => $reasons];
        }
        return [
            'score'   => $partial_score,
            'label'   => 'PARTIAL',
            'reasons' => $reasons,
        ];
    }

    // ---- Kasus 3: kedua fetch berhasil → scoring normal ----
    $type_penalty = [
        'defaced'      => 95,
        'ojs_injected' => 70,
        'non_ojs'      => 30,
        'ojs_clean'    => 0,
    ];

    $score = max(
        $type_penalty[$result_normal['type']] ?? 0,
        $type_penalty[$result_bot['type']]    ?? 0
    );

    if (in_array($result_normal['type'], ['defaced','ojs_injected'], true)) {
        $reasons[] = "Browser biasa terdeteksi {$result_normal['type']}";
    }
    if (in_array($result_bot['type'], ['defaced','ojs_injected'], true)) {
        $reasons[] = "Googlebot terdeteksi {$result_bot['type']}";
    }

    if (!empty($cloaking['cloaking'])) {
        $score = max($score, 90);
        $reasons[] = "Cloaking aktif: " . ($cloaking['detail'] ?? $cloaking['reason']);
    }

    // Bonus penalty kalau title sudah hijack
    if (!empty($result_normal['title_judol']) || !empty($result_bot['title_judol'])) {
        $score = max($score, 85);
        $reasons[] = "Title halaman sudah di-hijack";
    }

    if ($score >= 80)      $label = 'HACKED';
    elseif ($score >= 50)  $label = 'SUSPICIOUS';
    elseif ($score >= 20)  $label = 'WARN';
    else                   $label = 'CLEAN';

    return [
        'score'   => $score,
        'label'   => $label,
        'reasons' => $reasons,
    ];
}

// =========================================================
// Public API: scan satu jurnal
// =========================================================
function scan_judol($jurnal_id) {
    $j = fetch_one("SELECT * FROM jurnals WHERE id=?", 'i', [$jurnal_id]);
    if (!$j) return ['ok' => false, 'message' => 'Jurnal tidak ditemukan'];

    $url = $j['url_archive'];

    // Fetch dengan UA Normal
    $r_normal = judol_http_get($url, JUDOL_NORMAL_UA);
    $cls_normal = $r_normal['ok']
        ? judol_classify_page($r_normal['body'], $r_normal['code'])
        : ['type' => 'error', 'confidence' => 0];

    // Jeda kecil antar request
    usleep(800000);

    // Fetch dengan UA Googlebot
    $r_bot = judol_http_get($url, JUDOL_BOT_UA);
    $cls_bot = $r_bot['ok']
        ? judol_classify_page($r_bot['body'], $r_bot['code'])
        : ['type' => 'error', 'confidence' => 0];

    // Cloaking check
    $cloaking = judol_detect_cloaking($cls_normal, $cls_bot);

    // Skor risiko
    $risk = judol_compute_risk($cls_normal, $cls_bot, $cloaking);

    // Kumpulkan sample evidence (truncated)
    $sample = [];
    if (in_array($cls_normal['type'], ['defaced','ojs_injected'], true)) {
        $sample['normal_title'] = mb_substr($cls_normal['title'] ?? '', 0, 200);
        $sample['normal_top_kw'] = array_slice($cls_normal['kw_detail']['hits_high'] ?? [], 0, 5, true);
    }
    if (in_array($cls_bot['type'], ['defaced','ojs_injected'], true)) {
        $sample['bot_title'] = mb_substr($cls_bot['title'] ?? '', 0, 200);
        $sample['bot_top_kw'] = array_slice($cls_bot['kw_detail']['hits_high'] ?? [], 0, 5, true);
    }

    // HTTP debug info (untuk audit kenapa fetch gagal)
    $http_debug = [
        'normal' => [
            'ok'           => $r_normal['ok'] ?? false,
            'http_code'    => $r_normal['code'] ?? 0,
            'curl_errno'   => $r_normal['errno'] ?? 0,
            'curl_error'   => $r_normal['error'] ?? '',
            'duration_ms'  => $r_normal['duration_ms'] ?? 0,
            'dns_time_ms'  => $r_normal['dns_time'] ?? 0,
            'connect_ms'   => $r_normal['connect_time'] ?? 0,
            'final_url'    => $r_normal['final_url'] ?? '',
            'body_size'    => isset($r_normal['body']) ? strlen($r_normal['body']) : 0,
        ],
        'bot' => [
            'ok'           => $r_bot['ok'] ?? false,
            'http_code'    => $r_bot['code'] ?? 0,
            'curl_errno'   => $r_bot['errno'] ?? 0,
            'curl_error'   => $r_bot['error'] ?? '',
            'duration_ms'  => $r_bot['duration_ms'] ?? 0,
            'dns_time_ms'  => $r_bot['dns_time'] ?? 0,
            'connect_ms'   => $r_bot['connect_time'] ?? 0,
            'final_url'    => $r_bot['final_url'] ?? '',
            'body_size'    => isset($r_bot['body']) ? strlen($r_bot['body']) : 0,
        ],
        'scanned_at' => date('Y-m-d H:i:s'),
    ];

    // Simpan ke log
    $detail_json = json_encode([
        'normal'     => $cls_normal,
        'bot'        => $cls_bot,
        'cloaking'   => $cloaking,
        'risk'       => $risk,
        'sample'     => $sample,
        'http_debug' => $http_debug,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // typing: i (jurnal_id), s (scan_status), i (risk_score), s (risk_label),
    //         s (normal_type), s (bot_type), i (cloaking_detected 0/1), s (detail_json)
    $cloak_int = !empty($cloaking['cloaking']) ? 1 : 0;

    // scan_status mencerminkan apa yang terjadi saat fetch:
    //   ok            : kedua UA berhasil fetch (HTTP 200 + body)
    //   partial_fetch : 1 UA berhasil, 1 UA gagal
    //   unreachable   : kedua UA gagal (site mati / DNS / firewall)
    if ($r_normal['ok'] && $r_bot['ok']) {
        $scan_status = 'ok';
    } elseif ($r_normal['ok'] || $r_bot['ok']) {
        $scan_status = 'partial_fetch';
    } else {
        $scan_status = 'unreachable';
    }

    // risk_score boleh NULL (untuk UNREACHABLE) — bind sebagai null pakai typing 's'
    // tapi karena helper db kita memakai bind_param yg strict, kita bypass:
    // - kalau null → simpan sebagai NULL di SQL via raw fragment
    // - kalau int  → simpan sebagai int biasa
    if ($risk['score'] === null) {
        // Insert dengan NULL untuk risk_score
        exec_q(
            "INSERT INTO judol_scan_log
              (jurnal_id, scan_status, risk_score, risk_label,
               normal_type, bot_type, cloaking_detected, detail_json)
             VALUES (?,?,NULL,?,?,?,?,?)",
            'issssis',
            [
                (int)$jurnal_id,
                $scan_status,
                $risk['label'],
                $cls_normal['type'] ?? 'error',
                $cls_bot['type'] ?? 'error',
                $cloak_int,
                $detail_json,
            ]
        );
    } else {
        exec_q(
            "INSERT INTO judol_scan_log
              (jurnal_id, scan_status, risk_score, risk_label,
               normal_type, bot_type, cloaking_detected, detail_json)
             VALUES (?,?,?,?,?,?,?,?)",
            'isisssis',
            [
                (int)$jurnal_id,
                $scan_status,
                (int)$risk['score'],
                $risk['label'],
                $cls_normal['type'] ?? 'error',
                $cls_bot['type'] ?? 'error',
                $cloak_int,
                $detail_json,
            ]
        );
    }

    // Update kolom snapshot di tabel jurnals (cepat untuk dashboard)
    if ($risk['score'] === null) {
        exec_q(
            "UPDATE jurnals
                SET last_judol_scan_at = NOW(),
                    last_judol_score   = NULL,
                    last_judol_label   = ?
              WHERE id = ?",
            'si',
            [$risk['label'], (int)$jurnal_id]
        );
    } else {
        exec_q(
            "UPDATE jurnals
                SET last_judol_scan_at = NOW(),
                    last_judol_score   = ?,
                    last_judol_label   = ?
              WHERE id = ?",
            'isi',
            [(int)$risk['score'], $risk['label'], (int)$jurnal_id]
        );
    }

    // Trigger alert kalau skor tinggi (pakai null-safe check)
    if (($risk['score'] ?? 0) >= 50) {
        judol_send_telegram_alert($j['nama_jurnal'], $url, $risk);
    }

    return [
        'ok'         => true,
        'jurnal_id'  => $jurnal_id,
        'url'        => $url,
        'normal'     => $cls_normal,
        'bot'        => $cls_bot,
        'cloaking'   => $cloaking,
        'risk'       => $risk,
        'sample'     => $sample,
        // Debug fields untuk display/audit
        'debug_normal_errno' => $r_normal['errno'] ?? 0,
        'debug_normal_err'   => $r_normal['error'] ?? '',
        'debug_normal_code'  => $r_normal['code'] ?? 0,
        'debug_bot_errno'    => $r_bot['errno'] ?? 0,
        'debug_bot_err'      => $r_bot['error'] ?? '',
        'debug_bot_code'     => $r_bot['code'] ?? 0,
    ];
}

// =========================================================
// Telegram alert (opsional)
// =========================================================
function judol_send_telegram_alert($jurnal_name, $url, $risk) {
    if (!JUDOL_TELEGRAM_BOT_TOKEN || !JUDOL_TELEGRAM_CHAT_ID) return false;
    $score = $risk['score'] ?? 0;
    if ($score < 50) return false; // hanya alert kalau SUSPICIOUS+

    $emoji = $score >= 80 ? '🚨' : '⚠️';
    $reasons_str = $risk['reasons'] ? "\n\nAlasan:\n• " . implode("\n• ", $risk['reasons']) : '';
    $msg = "{$emoji} *SIMONJU - Anomali Judol Terdeteksi*\n\n"
         . "*Jurnal:* {$jurnal_name}\n"
         . "*URL:* {$url}\n"
         . "*Skor:* {$risk['score']}/100 ({$risk['label']})"
         . $reasons_str;

    $tg_url = "https://api.telegram.org/bot" . JUDOL_TELEGRAM_BOT_TOKEN . "/sendMessage";
    $ch = curl_init($tg_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id'    => JUDOL_TELEGRAM_CHAT_ID,
            'text'       => $msg,
            'parse_mode' => 'Markdown',
        ]),
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

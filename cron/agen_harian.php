<?php
/**
 * ============================================================
 *  🌅  AGEN BRIEFING HARIAN — PPJ LPPM UNSOED
 * ============================================================
 *  Modul:
 *    💰 EKONOMI  : Kurs USD/IDR, Emas Dunia (XAU), Emas Galeri24
 *    🖥️  PPJ      : Cek status jos.unsoed.ac.id (deteksi judol)
 *    ⚽ SPORT    : Hasil & jadwal Piala Dunia 2026
 *
 *  Lokasi : /home/jurz2196/public_html/ppj/cron/agen_harian.php
 *  Jadwal : Setiap hari 07.00 WIB via cron cPanel
 *  Akses  : https://ppj.jurnalsinta.id/cron/agen_harian.php?key=SECRET
 * ============================================================
 */

date_default_timezone_set('Asia/Jakarta');

// ============================================================
//  🔑  KONFIGURASI
// ============================================================

// Secret (API key, token) dimuat dari config.agen.php yang TIDAK di-commit.
// Salin config.agen.example.php -> config.agen.php lalu isi nilai asli.
$config_agen = __DIR__ . '/config.agen.php';
if (!file_exists($config_agen)) {
    http_response_code(500);
    die("Config tidak ditemukan: config.agen.php. Salin dari config.agen.example.php.\n");
}
require $config_agen;

define('GROQ_MODEL',         'llama-3.3-70b-versatile');

define('LOG_FILE', __DIR__ . '/log_agen_harian.txt');

// URL sumber data
define('URL_KURS',     'https://open.er-api.com/v6/latest/USD');
define('URL_GOLD',     'https://www.goldapi.io/api/XAU/USD');
define('URL_GALERI24', 'https://galeri24.co.id/harga-emas');
define('URL_JOS',      'https://jos.unsoed.ac.id/');
define('URL_WORLDCUP', 'https://raw.githubusercontent.com/openfootball/worldcup.json/master/2026/worldcup.json');


// ============================================================
//  🛠️  UTILITAS
// ============================================================

function tulis_log($p) {
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . "] $p\n", FILE_APPEND);
}

function esc($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** HTTP request sederhana via curl. Return ['code'=>int,'body'=>string]|null */
function http_request($url, $method = 'GET', $headers = [], $body = null, $timeout = 25) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AgenHarian/2.0)',
    ]);
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'POST' && $body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) { tulis_log("CURL ERROR ($url): $err"); return null; }
    return ['code' => $code, 'body' => $resp];
}

/** Format Rupiah: 2663000 -> "Rp 2.663.000" */
function rp($n) {
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

/** Nama hari & bulan dalam Bahasa Indonesia */
function tanggal_id($ts = null) {
    $ts = $ts ?? time();
    $hari  = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa',
              'Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni',
              'Juli','Agustus','September','Oktober','November','Desember'];
    return $hari[date('l', $ts)] . ', ' . date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}


// ============================================================
//  💵  MODUL 1: KURS
// ============================================================

function ambil_kurs() {
    tulis_log('Ambil kurs...');
    $resp = http_request(URL_KURS);
    if (!$resp || $resp['code'] !== 200) { tulis_log('Kurs GAGAL'); return null; }

    $d = json_decode($resp['body'], true);
    if (!$d || ($d['result'] ?? '') !== 'success') { tulis_log('Kurs parse gagal'); return null; }

    $r = $d['rates'];
    $usd_idr = $r['IDR'] ?? null;
    if (!$usd_idr) return null;

    tulis_log("Kurs USD/IDR: $usd_idr");
    return [
        'USD_IDR' => $usd_idr,
        'EUR_IDR' => isset($r['EUR']) ? $usd_idr / $r['EUR'] : null,
        'SGD_IDR' => isset($r['SGD']) ? $usd_idr / $r['SGD'] : null,
        'JPY_IDR' => isset($r['JPY']) ? $usd_idr / $r['JPY'] : null,
        'update'  => $d['time_last_update_utc'] ?? '',
    ];
}


// ============================================================
//  🌍  MODUL 2: EMAS DUNIA (XAU via GoldAPI.io)
// ============================================================

function ambil_emas_dunia($usd_idr) {
    if (GOLD_API_KEY === 'goldapi-xxxxx' || !GOLD_API_KEY) {
        tulis_log('GOLD_API_KEY kosong, skip emas dunia'); return null;
    }
    tulis_log('Ambil emas dunia...');
    $resp = http_request(URL_GOLD, 'GET', [
        'x-access-token: ' . GOLD_API_KEY,
        'Content-Type: application/json',
    ]);
    if (!$resp || $resp['code'] !== 200) {
        tulis_log('Emas dunia GAGAL HTTP ' . ($resp['code'] ?? 'null')); return null;
    }
    $d = json_decode($resp['body'], true);
    if (!$d || !isset($d['price'])) { tulis_log('Emas dunia parse gagal'); return null; }

    $oz_usd = $d['price'];
    $oz_idr = $oz_usd * $usd_idr;
    tulis_log("Emas dunia: $oz_usd USD/oz");
    return [
        'oz_usd'   => $oz_usd,
        'oz_idr'   => $oz_idr,
        'gram_usd' => $oz_usd / 31.1035,
        'gram_idr' => $oz_idr / 31.1035,
        'change'   => $d['ch'] ?? null,      // perubahan harga
        'change_pct' => $d['chp'] ?? null,   // perubahan %
    ];
}


// ============================================================
//  🏅  MODUL 3: EMAS GALERI24 (scraping)
// ============================================================

/**
 * Ambil harga jual emas GALERI 24 untuk berat tertentu (1, 5, 10 gr).
 * Halaman galeri24 menampilkan tabel: Berat | Harga Jual | Harga Buyback.
 * Section pertama berjudul "Harga GALERI 24".
 */
function ambil_emas_galeri24() {
    tulis_log('Ambil emas Galeri24...');
    // Header seperti browser agar tidak diblok
    $resp = http_request(URL_GALERI24, 'GET', [
        'Accept: text/html,application/xhtml+xml',
        'Accept-Language: id-ID,id;q=0.9,en;q=0.8',
    ], null, 30);
    if (!$resp || $resp['code'] !== 200) {
        tulis_log('Galeri24 GAGAL HTTP ' . ($resp['code'] ?? 'null')); return null;
    }

    $html = $resp['body'];

    // Simpan respons mentah untuk diagnosa kalau parsing gagal
    @file_put_contents(__DIR__ . '/galeri24_raw.html', $html);

    // 1) Buang <script>/<style> dulu, baru strip semua tag -> teks polos
    $teks = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
    $teks = preg_replace('/<[^>]+>/', ' ', $teks);
    $teks = html_entity_decode($teks, ENT_QUOTES, 'UTF-8');
    $teks = preg_replace('/\s+/', ' ', $teks);

    // 2) Ambil tanggal update ("Diperbarui Kamis, 11 Juni 2026")
    $tgl_update = '';
    if (preg_match('/Diperbarui\s+(\w+,\s*\d{1,2}\s+\w+\s+20\d{2})/u', $teks, $m)) {
        $tgl_update = trim($m[1]);
    }

    // 3) PENTING: anchor ke heading UNIK "Harga GALERI 24" (bukan "GALERI 24"
    //    yang juga muncul di menu navigasi), potong sampai "Harga DINAR".
    $posA = stripos($teks, 'Harga GALERI 24');
    if ($posA === false) {
        tulis_log('Galeri24: heading "Harga GALERI 24" tidak ditemukan (kemungkinan diblok / JS-only). Cek galeri24_raw.html');
        return null;
    }
    $posB = stripos($teks, 'Harga DINAR', $posA + 10);
    $potongan = ($posB !== false) ? substr($teks, $posA, $posB - $posA)
                                  : substr($teks, $posA, 1500);

    // 4) Regex pasangan: <berat> Rp<jual> Rp<buyback>  (mis. "1 Rp2.663.000 Rp2.490.000")
    $hasil = [];
    if (preg_match_all('/(?<![\d.])(\d+(?:\.\d+)?)\s+Rp([\d.]+)\s+Rp([\d.]+)/u', $potongan, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $row) {
            $berat = (string)(float)$row[1];   // "1", "5", "10", "0.5"
            if (!isset($hasil[$berat])) {
                $hasil[$berat] = [
                    'jual'    => (int)str_replace('.', '', $row[2]),
                    'buyback' => (int)str_replace('.', '', $row[3]),
                ];
            }
        }
    }

    if (!$hasil) {
        tulis_log('Galeri24 regex 0 match. Cuplikan: ' . substr($potongan, 0, 200));
        return null;
    }

    tulis_log('Galeri24 OK, ' . count($hasil) . ' baris berat.');
    return ['update' => $tgl_update, 'harga' => $hasil];
}


// ============================================================
//  🖥️  MODUL 4: CEK JOS UNSOED (deteksi judol)
// ============================================================

function cek_jos() {
    tulis_log('Cek jos.unsoed.ac.id...');
    $resp = http_request(URL_JOS, 'GET', [], null, 20);

    // Tidak bisa diakses sama sekali
    if (!$resp) {
        return ['status' => 'down', 'pesan' => 'Tidak bisa diakses (timeout/error koneksi)'];
    }
    if ($resp['code'] >= 500 || $resp['code'] === 0) {
        return ['status' => 'down', 'pesan' => 'Server error (HTTP ' . $resp['code'] . ')'];
    }
    if ($resp['code'] === 403 || $resp['code'] === 404) {
        return ['status' => 'warning', 'pesan' => 'Akses ditolak/tidak ditemukan (HTTP ' . $resp['code'] . ')'];
    }

    // Cek indikasi judol di konten
    $body = strtolower($resp['body']);
    $kata_judol = ['slot gacor','maxwin','judi online','situs slot','slot online',
                   'togel','rtp slot','slot88','gacor','pragmatic play','bandar',
                   'deposit pulsa','scatter hitam'];
    $ketemu = [];
    foreach ($kata_judol as $k) {
        if (strpos($body, $k) !== false) $ketemu[] = $k;
    }

    if ($ketemu) {
        return ['status' => 'hacked',
                'pesan'  => 'Terindikasi judol! Kata kunci: ' . implode(', ', array_slice($ketemu, 0, 4))];
    }

    if ($resp['code'] === 200) {
        return ['status' => 'ok', 'pesan' => 'Normal, dapat diakses'];
    }
    return ['status' => 'warning', 'pesan' => 'HTTP ' . $resp['code']];
}


// ============================================================
//  ⚽  MODUL 5: PIALA DUNIA 2026
// ============================================================

/** Bendera emoji per negara peserta */
function bendera($tim) {
    static $map = [
        'Mexico'=>'🇲🇽','South Africa'=>'🇿🇦','South Korea'=>'🇰🇷','Czech Republic'=>'🇨🇿',
        'Canada'=>'🇨🇦','Bosnia & Herzegovina'=>'🇧🇦','Qatar'=>'🇶🇦','Switzerland'=>'🇨🇭',
        'United States'=>'🇺🇸','USA'=>'🇺🇸','Argentina'=>'🇦🇷','Brazil'=>'🇧🇷','France'=>'🇫🇷',
        'England'=>'🏴󠁧󠁢󠁥󠁮󠁧󠁿','Spain'=>'🇪🇸','Germany'=>'🇩🇪','Portugal'=>'🇵🇹','Netherlands'=>'🇳🇱',
        'Belgium'=>'🇧🇪','Croatia'=>'🇭🇷','Italy'=>'🇮🇹','Japan'=>'🇯🇵','Australia'=>'🇦🇺',
        'Morocco'=>'🇲🇦','Senegal'=>'🇸🇳','Uruguay'=>'🇺🇾','Colombia'=>'🇨🇴','Ecuador'=>'🇪🇨',
        'Denmark'=>'🇩🇰','Poland'=>'🇵🇱','Serbia'=>'🇷🇸','Wales'=>'🏴󠁧󠁢󠁷󠁬󠁳󠁿','Scotland'=>'🏴󠁧󠁢󠁳󠁣󠁴󠁿',
        'Iran'=>'🇮🇷','Saudi Arabia'=>'🇸🇦','Tunisia'=>'🇹🇳','Ghana'=>'🇬🇭','Cameroon'=>'🇨🇲',
        'Nigeria'=>'🇳🇬','Egypt'=>'🇪🇬','Algeria'=>'🇩🇿','Ivory Coast'=>"🇨🇮",'Mali'=>'🇲🇱',
        'Peru'=>'🇵🇪','Chile'=>'🇨🇱','Paraguay'=>'🇵🇾','Venezuela'=>'🇻🇪','Costa Rica'=>'🇨🇷',
        'Panama'=>'🇵🇦','Jamaica'=>'🇯🇲','Honduras'=>'🇭🇳','New Zealand'=>'🇳🇿',
        'Norway'=>'🇳🇴','Sweden'=>'🇸🇪','Austria'=>'🇦🇹','Turkey'=>'🇹🇷','Türkiye'=>'🇹🇷',
        'Ukraine'=>'🇺🇦','Greece'=>'🇬🇷','Hungary'=>'🇭🇺','Slovakia'=>'🇸🇰','Slovenia'=>'🇸🇮',
        'Romania'=>'🇷🇴','Russia'=>'🇷🇺','Uzbekistan'=>'🇺🇿','Jordan'=>'🇯🇴','Iraq'=>'🇮🇶',
        'United Arab Emirates'=>'🇦🇪','Bolivia'=>'🇧🇴','DR Congo'=>'🇨🇩','Ireland'=>'🇮🇪',
    ];
    return $map[$tim] ?? '⚽';
}

/** Konversi "13:00 UTC-6" + tanggal -> jam WIB "HH:MM" */
function ke_wib($tanggal, $waktu) {
    if (!preg_match('/(\d{1,2}):(\d{2})\s*UTC([+-]\d+)/', $waktu, $m)) {
        return null;
    }
    [$full, $jam, $menit, $offset] = $m;
    try {
        $dt = new DateTime("$tanggal {$jam}:{$menit}:00", new DateTimeZone('UTC'));
        // kurangi offset asli untuk dapat UTC murni
        $off = (int)$offset;
        $dt->modify(($off >= 0 ? '-' : '+') . abs($off) . ' hours');
        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $dt;
    } catch (Exception $e) {
        return null;
    }
}

function ambil_worldcup() {
    tulis_log('Ambil data Piala Dunia 2026...');
    $resp = http_request(URL_WORLDCUP, 'GET', [], null, 25);
    if (!$resp || $resp['code'] !== 200) {
        tulis_log('WorldCup GAGAL HTTP ' . ($resp['code'] ?? 'null')); return null;
    }
    $d = json_decode($resp['body'], true);
    if (!$d || !isset($d['matches'])) { tulis_log('WorldCup parse gagal'); return null; }

    $hari_ini = date('Y-m-d');
    $kemarin  = date('Y-m-d', strtotime('-1 day'));

    $hasil_kemarin = [];
    $jadwal_hari_ini = [];

    foreach ($d['matches'] as $m) {
        $tgl = $m['date'] ?? '';
        $t1  = $m['team1'] ?? '';
        $t2  = $m['team2'] ?? '';
        $grup = $m['group'] ?? ($m['round'] ?? '');
        $ada_skor = isset($m['score']['ft']) && is_array($m['score']['ft']);

        if ($tgl === $kemarin && $ada_skor) {
            $hasil_kemarin[] = [
                't1' => $t1, 't2' => $t2,
                's1' => $m['score']['ft'][0], 's2' => $m['score']['ft'][1],
                'grup' => $grup,
            ];
        } elseif ($tgl === $hari_ini) {
            $wib = ke_wib($tgl, $m['time'] ?? '');
            $jadwal_hari_ini[] = [
                't1' => $t1, 't2' => $t2,
                'jam' => $wib ? $wib->format('H:i') : '--:--',
                'tgl_wib' => $wib ? $wib->format('Y-m-d') : $tgl,
                'grup' => $grup,
                'ada_skor' => $ada_skor,
                's1' => $ada_skor ? $m['score']['ft'][0] : null,
                's2' => $ada_skor ? $m['score']['ft'][1] : null,
            ];
        }
    }

    tulis_log('WorldCup OK: ' . count($hasil_kemarin) . ' hasil, ' . count($jadwal_hari_ini) . ' jadwal');
    return ['kemarin' => $hasil_kemarin, 'hari_ini' => $jadwal_hari_ini];
}


// ============================================================
//  🧠  MODUL 6: CATATAN EKONOMI (Groq — hanya komentar singkat)
// ============================================================

function catatan_ekonomi($kurs, $emas_dunia, $g24) {
    if (GROQ_API_KEY === 'gsk_xxxxx' || !GROQ_API_KEY) return null;

    $ringkas = "Kurs USD/IDR: " . round($kurs['USD_IDR']) . "\n";
    if ($emas_dunia) $ringkas .= "Emas dunia: USD " . round($emas_dunia['oz_usd'], 2) . "/oz";
    if ($emas_dunia && $emas_dunia['change_pct'] !== null) $ringkas .= " (perubahan " . $emas_dunia['change_pct'] . "%)";
    $ringkas .= "\n";
    if ($g24 && isset($g24['harga']['1'])) $ringkas .= "Emas Galeri24 1gr: " . rp($g24['harga']['1']['jual']) . "\n";

    $prompt = "Kamu analis ekonomi. Berdasarkan data faktual berikut:\n\n$ringkas\n" .
        "Tulis SATU paragraf catatan singkat (maksimal 2 kalimat) dalam Bahasa Indonesia tentang kondisi rupiah dan emas hari ini. " .
        "HANYA gunakan angka di atas, JANGAN mengarang data. Jangan beri saran beli/jual. Langsung paragrafnya saja tanpa pembuka.";

    $payload = json_encode([
        'model' => GROQ_MODEL, 'max_tokens' => 300, 'temperature' => 0.6,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ]);
    $resp = http_request('https://api.groq.com/openai/v1/chat/completions', 'POST', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . GROQ_API_KEY,
    ], $payload);

    if (!$resp || $resp['code'] !== 200) { tulis_log('Catatan Groq gagal'); return null; }
    $d = json_decode($resp['body'], true);
    return trim($d['choices'][0]['message']['content'] ?? '') ?: null;
}


// ============================================================
//  💬  BUILD PESAN TELEGRAM (parse_mode HTML)
// ============================================================

function bangun_pesan($kurs, $emas_dunia, $g24, $catatan, $jos, $wc) {
    $L = [];
    $L[] = "🌅 <b>BRIEFING HARIAN</b>";
    $L[] = "📅 " . esc(tanggal_id());
    $L[] = "";

    // ---------- EKONOMI ----------
    $L[] = "━━━━━━━━━━━━━━━━━━";
    $L[] = "💰 <b>EKONOMI</b>";
    $L[] = "━━━━━━━━━━━━━━━━━━";

    if ($kurs) {
        $L[] = "💵 <b>Kurs USD/IDR</b>: " . esc(rp(round($kurs['USD_IDR'])));
        $sub = [];
        if ($kurs['EUR_IDR']) $sub[] = "EUR " . rp(round($kurs['EUR_IDR']));
        if ($kurs['SGD_IDR']) $sub[] = "SGD " . rp(round($kurs['SGD_IDR']));
        if ($sub) $L[] = "   <i>" . esc(implode('  •  ', $sub)) . "</i>";
    } else {
        $L[] = "💵 Kurs: <i>data tidak tersedia</i>";
    }
    $L[] = "";

    if ($emas_dunia) {
        $arah = '';
        if ($emas_dunia['change_pct'] !== null) {
            $p = (float)$emas_dunia['change_pct'];
            $arah = ($p >= 0 ? '🔺 +' : '🔻 ') . $p . '%';
        }
        $L[] = "🌍 <b>Emas Dunia (XAU)</b>";
        $L[] = "   $" . number_format($emas_dunia['oz_usd'], 2) . " /oz  " . $arah;
        $L[] = "   ≈ " . esc(rp(round($emas_dunia['gram_idr']))) . " /gram";
    } else {
        $L[] = "🌍 Emas Dunia: <i>data tidak tersedia</i>";
    }
    $L[] = "";

    if ($g24 && !empty($g24['harga'])) {
        $L[] = "🏅 <b>Emas Galeri24</b> <i>(harga jual)</i>";
        foreach (['1' => '1 gr', '5' => '5 gr', '10' => '10 gr'] as $key => $label) {
            if (isset($g24['harga'][$key])) {
                $L[] = "   • " . str_pad($label, 6) . " : <b>" . esc(rp($g24['harga'][$key]['jual'])) . "</b>";
            }
        }
        if ($g24['update']) $L[] = "   <i>Update: " . esc($g24['update']) . "</i>";
    } else {
        $L[] = "🏅 Emas Galeri24: <i>data tidak tersedia</i>";
    }

    if ($catatan) {
        $L[] = "";
        $L[] = "📝 <i>" . esc($catatan) . "</i>";
    }
    $L[] = "";

    // ---------- PPJ ----------
    $L[] = "━━━━━━━━━━━━━━━━━━";
    $L[] = "🖥️ <b>PPJ — Status Jurnal</b>";
    $L[] = "━━━━━━━━━━━━━━━━━━";
    $ikon = ['ok'=>'✅','warning'=>'⚠️','down'=>'🔴','hacked'=>'🚨'];
    $label = ['ok'=>'NORMAL','warning'=>'PERLU CEK','down'=>'DOWN','hacked'=>'BAHAYA'];
    $st = $jos['status'];
    $L[] = ($ikon[$st] ?? '❔') . " <b>jos.unsoed.ac.id</b> — " . ($label[$st] ?? '?');
    $L[] = "   <i>" . esc($jos['pesan']) . "</i>";
    if ($st === 'down' || $st === 'hacked') {
        $L[] = "   👉 <i>Segera cek manual & jalankan scanner judol.</i>";
    }
    $L[] = "";

    // ---------- SPORT ----------
    $L[] = "━━━━━━━━━━━━━━━━━━";
    $L[] = "⚽ <b>PIALA DUNIA 2026</b>";
    $L[] = "━━━━━━━━━━━━━━━━━━";

    if ($wc) {
        // Hasil kemarin
        if ($wc['kemarin']) {
            $L[] = "🏁 <b>Hasil Kemarin</b>";
            foreach ($wc['kemarin'] as $g) {
                $L[] = "   " . bendera($g['t1']) . " " . esc($g['t1']) .
                       " <b>" . $g['s1'] . " - " . $g['s2'] . "</b> " .
                       esc($g['t2']) . " " . bendera($g['t2']);
            }
            $L[] = "";
        }
        // Jadwal/hasil hari ini
        if ($wc['hari_ini']) {
            $L[] = "📆 <b>Hari Ini</b>";
            foreach ($wc['hari_ini'] as $g) {
                if ($g['ada_skor']) {
                    $L[] = "   " . bendera($g['t1']) . " " . esc($g['t1']) .
                           " <b>" . $g['s1'] . " - " . $g['s2'] . "</b> " .
                           esc($g['t2']) . " " . bendera($g['t2']);
                } else {
                    $L[] = "   🕒 " . $g['jam'] . "  " .
                           bendera($g['t1']) . " " . esc($g['t1']) .
                           " vs " . esc($g['t2']) . " " . bendera($g['t2']);
                }
            }
        }
        if (!$wc['kemarin'] && !$wc['hari_ini']) {
            $L[] = "<i>Tidak ada pertandingan kemarin/hari ini.</i>";
        }
    } else {
        $L[] = "<i>Data pertandingan tidak tersedia.</i>";
    }

    $L[] = "";
    $L[] = "━━━━━━━━━━━━━━━━━━";
    $L[] = "<i>🤖 Agen Harian PPJ-LPPM Unsoed</i>";

    return implode("\n", $L);
}


// ============================================================
//  📤  KIRIM TELEGRAM
// ============================================================

function kirim_telegram($pesan) {
    tulis_log('Kirim Telegram...');
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $payload = json_encode([
        'chat_id' => TELEGRAM_CHAT_ID,
        'text'    => $pesan,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
    $resp = http_request($url, 'POST', ['Content-Type: application/json'], $payload);
    if (!$resp || $resp['code'] !== 200) {
        tulis_log('Telegram GAGAL: ' . ($resp['body'] ?? 'null'));
        return false;
    }
    tulis_log('Telegram terkirim.');
    return true;
}


// ============================================================
//  ▶️  EKSEKUSI
// ============================================================

tulis_log('========== MULAI AGEN HARIAN ==========');

// Proteksi akses browser
if (php_sapi_name() !== 'cli') {
    if (($_GET['key'] ?? '') !== CRON_SECRET) {
        http_response_code(403);
        die('⛔ Akses ditolak.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    // EKONOMI
    $kurs = ambil_kurs();
    if (!$kurs) throw new Exception('Gagal ambil kurs (modul wajib).');

    $emas_dunia = ambil_emas_dunia($kurs['USD_IDR']);  // boleh null
    $g24        = ambil_emas_galeri24();               // boleh null
    $catatan    = catatan_ekonomi($kurs, $emas_dunia, $g24); // boleh null

    // PPJ
    $jos = cek_jos();

    // SPORT
    $wc = ambil_worldcup();  // boleh null

    // Susun & kirim
    $pesan = bangun_pesan($kurs, $emas_dunia, $g24, $catatan, $jos, $wc);

    if (!kirim_telegram($pesan)) throw new Exception('Gagal kirim Telegram.');

    tulis_log("========== SELESAI (SUKSES) ==========\n");
    echo "OK - Briefing terkirim ke Telegram.\n";

} catch (Exception $e) {
    $err = $e->getMessage();
    tulis_log("ERROR: $err");
    tulis_log("========== SELESAI (GAGAL) ==========\n");

    // notifikasi error sederhana
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    http_request($url, 'POST', ['Content-Type: application/json'], json_encode([
        'chat_id' => TELEGRAM_CHAT_ID,
        'text'    => "⚠️ Agen Harian error:\n" . $err,
    ]));

    echo "ERROR: $err\n";
}

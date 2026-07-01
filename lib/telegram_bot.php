<?php
// =========================================================
// lib/telegram_bot.php — Bot Telegram info jurnal SIMONJU.
// Murni PHP + query DB (TANPA AI). Bentuk library (fungsi saja).
//
// Config (includes/config.php, gitignored):
//   define('SIMONJU_BOT_TOKEN', '123456:ABC...');   // dari @BotFather
//   define('SIMONJU_BOT_SECRET', 'string-acak');    // validasi webhook
// Fallback token: JUDOL_TELEGRAM_BOT_TOKEN bila SIMONJU_BOT_TOKEN kosong.
// =========================================================
require_once __DIR__ . '/../includes/db.php';

function tgbot_token() {
    if (defined('SIMONJU_BOT_TOKEN') && SIMONJU_BOT_TOKEN !== '') return SIMONJU_BOT_TOKEN;
    if (defined('JUDOL_TELEGRAM_BOT_TOKEN')) return JUDOL_TELEGRAM_BOT_TOKEN;
    return '';
}

/** Panggil Bot API. */
function tg_api($method, array $params) {
    $token = tgbot_token();
    if ($token === '') return null;
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if (defined('CA_BUNDLE_PATH') && is_file(CA_BUNDLE_PATH)) {
        curl_setopt($ch, CURLOPT_CAINFO, CA_BUNDLE_PATH);
    }
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp;
}

function tg_send($chat_id, $text) {
    return tg_api('sendMessage', [
        'chat_id'                  => $chat_id,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ]);
}

function tgbot_esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Header — polos (tanpa blockquote). */
function tgbot_header() {
    return "🤖 <b>SIMONJU BOT</b>\n"
         . "<i>Layanan info jurnal ilmiah</i>\n"
         . "<i>Di lingkungan Universitas Jenderal Soedirman</i>";
}

/** Footer — blockquote. */
function tgbot_footer() {
    return "<blockquote><b>Pusat Pengelolaan Jurnal</b>\n"
         . "LPPM · Universitas Jenderal Soedirman\n"
         . "🌐 <a href=\"https://rju.unsoed.ac.id\">rju.unsoed.ac.id</a></blockquote>";
}

/** Label akreditasi ringkas. */
function tgbot_akreditasi($j) {
    $parts = [];
    if (($j['akreditasi_jenis'] ?? '') === 'sinta' && trim((string)$j['akreditasi_peringkat']) !== '') {
        $parts[] = trim($j['akreditasi_peringkat']);
    }
    if ((int)($j['is_scopus'] ?? 0) === 1) {
        $q = trim((string)($j['scopus_q'] ?? ''));
        $parts[] = 'Scopus' . ($q !== '' ? " {$q}" : '');
    }
    return $parts ? implode(' + ', $parts) : 'Belum terakreditasi';
}

/** Format APC -> 'Rp x' atau '-'. */
function tgbot_apc($v) {
    $v = trim((string)$v);
    if (!preg_match('/^[1-9][0-9]*$/', $v)) return '-';
    return 'Rp ' . number_format((int)$v, 0, ',', '.');
}

/** Detail satu jurnal (metadata + editor + current issue + tautan + kontak). */
function tgbot_detail($j) {
    $ed = fetch_one("SELECT nama, email, no_hp FROM editor WHERE jurnal_id=? LIMIT 1", 'i', [(int)$j['id']]);
    $ketua = $ed && trim((string)$ed['nama']) !== '' ? $ed['nama'] : '—';

    $t = fetch_one(
        "SELECT volume, nomor, tahun, jumlah_artikel FROM terbitan
          WHERE jurnal_id=?
          ORDER BY CAST(tahun AS UNSIGNED) DESC, CAST(volume AS UNSIGNED) DESC, CAST(nomor AS UNSIGNED) DESC
          LIMIT 1",
        'i', [(int)$j['id']]
    );
    if ($t) {
        $issue = 'Vol ' . ($t['volume'] !== '' ? $t['volume'] : '?')
               . ' No ' . ($t['nomor'] !== '' ? $t['nomor'] : '?')
               . ($t['tahun'] !== '' ? " ({$t['tahun']})" : '');
        $artikel = (int)$t['jumlah_artikel'] . ' artikel';
    } else {
        $issue = 'Belum ada terbitan';
        $artikel = '-';
    }

    $issn = trim((string)($j['p_issn'] ?? ''));
    if ($issn === '' || $issn === '-') $issn = trim((string)($j['e_issn'] ?? ''));

    $sinta_ok  = (($j['akreditasi_jenis'] ?? '') === 'sinta' && trim((string)$j['akreditasi_peringkat']) !== '');
    $scopus_ok = ((int)($j['is_scopus'] ?? 0) === 1);
    $div = "──────────────";

    // Banner + identitas
    $out  = tgbot_header() . "\n\n";
    $out .= "📚 <b>Nama Jurnal:</b> " . tgbot_esc($j['nama_jurnal']) . "\n";
    $out .= "🏛️ <b>Unit:</b> " . tgbot_esc($j['unit_kerja'] ?: '—') . "\n";

    // Akreditasi & indeksasi
    $out .= $div . "\n";
    $out .= "🏅 <b>Akreditasi:</b> " . tgbot_esc($sinta_ok ? $j['akreditasi_peringkat'] : 'Belum') . "\n";
    if ($sinta_ok) {
        $sinta = trim((string)($j['link_sinta'] ?? '')) ?: trim((string)($j['akreditasi_url'] ?? ''));
        if ($sinta !== '') $out .= "     ↳ 🔗 <a href=\"" . tgbot_esc($sinta) . "\">Profil SINTA</a>\n";
    }
    $out .= "🌐 <b>Scopus:</b> " . ($scopus_ok ? 'Ya' . (trim((string)($j['scopus_q'] ?? '')) !== '' ? ' (' . tgbot_esc($j['scopus_q']) . ')' : '') : 'Tidak') . "\n";
    if ($scopus_ok) {
        $sc = trim((string)($j['scopus_url'] ?? ''));
        if ($sc !== '') $out .= "     ↳ 🔗 <a href=\"" . tgbot_esc($sc) . "\">Scimago (Scopus)</a>\n";
    }

    // Biaya & ISSN
    $out .= $div . "\n";
    $out .= "💰 <b>APC:</b> " . tgbot_esc(tgbot_apc($j['apc'] ?? '')) . "\n";
    $out .= "🔖 <b>ISSN:</b> " . tgbot_esc($issn !== '' && $issn !== '-' ? $issn : '—') . "\n";

    // Terbitan
    $out .= $div . "\n";
    $out .= "🗓️ <b>Terbitan terkini:</b> " . tgbot_esc($issue) . " · " . tgbot_esc($artikel) . "\n";
    if (!empty($j['url_archive'])) $out .= "     ↳ 🔗 <a href=\"" . tgbot_esc($j['url_archive']) . "\">Arsip jurnal</a>\n";

    // Kontak editor
    $out .= $div . "\n";
    $out .= "👤 <b>Ketua Editor:</b> " . tgbot_esc($ketua) . "\n";
    $hp = trim((string)($ed['no_hp'] ?? ''));
    if ($hp !== '') {
        $wa = preg_replace('/[^0-9]/', '', $hp);
        if (substr($wa, 0, 1) === '0') $wa = '62' . substr($wa, 1);
        if ($wa !== '') $out .= "     ↳ 💬 <a href=\"https://wa.me/{$wa}\">WhatsApp</a>\n";
    }
    $email = trim((string)($ed['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $out .= "     ↳ 📧 <a href=\"mailto:" . tgbot_esc($email) . "\">" . tgbot_esc($email) . "</a>\n";
    }

    // Footer banner
    $out .= "\n" . tgbot_footer();
    return $out;
}

/** Proses satu update Telegram. */
function tgbot_handle(array $update) {
    $msg = $update['message'] ?? $update['edited_message'] ?? null;
    if (!$msg || !isset($msg['chat']['id'])) return;
    $chat = $msg['chat']['id'];
    $text = trim((string)($msg['text'] ?? ''));

    if ($text === '' || $text[0] === '/' && preg_match('~^/(start|help)~i', $text)) {
        tg_send($chat,
            tgbot_header() . "\n\n"
          . "👋 Ketik <b>nama jurnal</b> (atau sebagiannya) untuk melihat:\n"
          . "• Metadata (unit, akreditasi, Scopus, APC, ISSN)\n"
          . "• Ketua editor + kontak (WA/email)\n"
          . "• Terbitan terkini + jumlah artikel\n\n"
          . "Contoh: <code>molekul</code>\n\n"
          . tgbot_footer());
        return;
    }

    // Anggap teks = kata kunci pencarian nama jurnal
    $q = mb_substr($text, 0, 80);
    $rows = fetch_all(
        "SELECT id, nama_jurnal, url_archive, unit_kerja, p_issn, e_issn,
                akreditasi_jenis, akreditasi_peringkat, akreditasi_url,
                is_scopus, scopus_q, scopus_url, link_sinta, apc
           FROM jurnals
          WHERE konfirmasi_status='terkonfirmasi' AND nama_jurnal LIKE ?
          ORDER BY nama_jurnal ASC LIMIT 12",
        's', ['%' . $q . '%']
    );

    if (empty($rows)) {
        tg_send($chat, "❌ Jurnal \"" . tgbot_esc($q) . "\" tidak ditemukan. Coba kata kunci lain.");
        return;
    }
    if (count($rows) === 1) {
        tg_send($chat, tgbot_detail($rows[0]));
        return;
    }
    // Banyak hasil -> daftar nama, minta lebih spesifik
    $list = "🔎 Ditemukan " . count($rows) . " jurnal. Ketik lebih spesifik:\n\n";
    foreach ($rows as $r) $list .= "• " . tgbot_esc($r['nama_jurnal']) . "\n";
    tg_send($chat, $list);
}

<?php
// =========================================================
// telegram/poll.php — MODE TES LOKAL (tanpa webhook publik).
// Jalankan dari CLI:  php telegram/poll.php
// Long-poll getUpdates ke Telegram lalu proses via tgbot_handle.
// Cocok utk localhost (Telegram tak bisa reach localhost, tapi
// localhost bisa outbound ke Telegram). Ctrl+C untuk berhenti.
//
// Catatan: getUpdates bentrok dengan webhook aktif -> script ini
// otomatis deleteWebhook dulu. Di produksi pakai webhook (bot.php),
// JANGAN jalankan poll bersamaan.
// =========================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only.'); }

require_once __DIR__ . '/../lib/telegram_bot.php';

if (tgbot_token() === '') { fwrite(STDERR, "SIMONJU_BOT_TOKEN belum diisi di config.php\n"); exit(1); }

// Lepas webhook agar getUpdates bisa jalan
tg_api('deleteWebhook', ['drop_pending_updates' => 'false']);

echo "[" . date('H:i:s') . "] Poll bot mulai. Chat bot-mu, balasan otomatis. Ctrl+C stop.\n";

$offset = 0;
while (true) {
    $resp = tg_api('getUpdates', ['offset' => $offset, 'timeout' => 30]);
    $data = json_decode((string)$resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        // jeda kecil kalau error/kosong biar tidak spin
        usleep(500000);
        continue;
    }
    foreach ($data['result'] as $u) {
        $offset = (int)$u['update_id'] + 1;
        $txt = $u['message']['text'] ?? '';
        echo "[" . date('H:i:s') . "] << " . $txt . "\n";
        tgbot_handle($u);
    }
}

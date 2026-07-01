<?php
// Webhook Telegram (dipanggil Telegram tiap ada pesan). Thin entry.
require_once __DIR__ . '/../lib/telegram_bot.php';

// Validasi secret token webhook (header dari Telegram)
$secret = defined('SIMONJU_BOT_SECRET') ? SIMONJU_BOT_SECRET : '';
$hdr    = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($secret !== '' && !hash_equals($secret, $hdr)) {
    http_response_code(403);
    exit;
}

$update = json_decode(file_get_contents('php://input'), true);
if (is_array($update)) {
    tgbot_handle($update);
}
http_response_code(200);
echo 'ok';

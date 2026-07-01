<?php
// Daftarkan / cek webhook bot ke Telegram. Admin only.
//   buka: https://ppj.jurnalsinta.id/telegram/setwebhook.php        (set)
//         https://ppj.jurnalsinta.id/telegram/setwebhook.php?info=1 (cek)
//         https://ppj.jurnalsinta.id/telegram/setwebhook.php?del=1  (hapus)
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../lib/telegram_bot.php';

header('Content-Type: text/plain; charset=utf-8');

if (tgbot_token() === '') { echo "SIMONJU_BOT_TOKEN belum diset di config.php\n"; exit; }

if (isset($_GET['info'])) {
    echo "getWebhookInfo:\n" . tg_api('getWebhookInfo', []) . "\n";
    exit;
}
if (isset($_GET['del'])) {
    echo "deleteWebhook:\n" . tg_api('deleteWebhook', []) . "\n";
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$hook   = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/bot.php';
$secret = defined('SIMONJU_BOT_SECRET') ? SIMONJU_BOT_SECRET : '';

$params = ['url' => $hook, 'drop_pending_updates' => 'true'];
if ($secret !== '') $params['secret_token'] = $secret;

echo "Set webhook ke: {$hook}\n\n";
echo tg_api('setWebhook', $params) . "\n";

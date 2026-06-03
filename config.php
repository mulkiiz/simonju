<?php
// =========================================================
// PPJ Unsoed - Configuration
// EDIT bagian di bawah sesuai kredensial cPanel Anda
// =========================================================

// --- Database (cek di cPanel > MySQL Databases) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'jurz2196_ppj');        // <-- GANTI nama database Anda
define('DB_USER', 'root');        // <-- GANTI user database Anda
define('DB_PASS', '');   // <-- GANTI password database

// --- Application ---
define('APP_NAME', 'Simonju');
define('APP_URL',  'https://ppj.jurnalsinta.id');
define('APP_TZ',   'Asia/Jakarta');

// --- Security ---
// Buat string acak panjang minimal 32 karakter
// Cara generate: di terminal cPanel jalankan: php -r "echo bin2hex(random_bytes(32));"
define('APP_SECRET', '3269e3895342797af8711d36ecfb3e00b30b2edd9061b28d76f982206ac58b17');

// Token untuk cron (akan dipanggil via URL: cron_crawl.php?token=XXX)
define('CRON_TOKEN', 'd5e480db302b0fb3697dc543470e6e6893fb38286c8c4464');

// --- Crawler behavior ---
define('CRAWLER_TIMEOUT',     30);   // detik per request
define('CRAWLER_USER_AGENT',  'PPJ-Unsoed-Bot/1.0 (+https://ppj.jurnalsinta.id)');
define('CRAWLER_DELAY_MS',    1500); // jeda antar jurnal saat cron (1.5 detik)

# --- Scanner Judol ---
define('JUDOL_SCANNER_TIMEOUT',  25);     // detik per request
define('JUDOL_SCANNER_DELAY_MS', 2000);   // jeda antar jurnal saat cron
define('JUDOL_NORMAL_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36');
define('JUDOL_BOT_UA',    'Googlebot/2.1 (+http://www.google.com/bot.html)');

# --- Telegram Alert (OPSIONAL) ---
# Kalau Anda ingin otomatis dikirim alert Telegram saat skor >= 50,
# isi 2 nilai berikut. Kalau kosong, alert dimatikan.
# Bot bisa pakai @mizpbg_bot yang sudah dipakai untuk Brilian, atau buat baru.
define('JUDOL_TELEGRAM_BOT_TOKEN', '8435013619:AAEnBkfOQwr8TXdxoLWXh0qnms1GfSQ7Hmk');   // contoh: '7890:AAH...'
define('JUDOL_TELEGRAM_CHAT_ID',   'mulkiiz');   // chat_id penerima alert (bisa user ID atau group ID)

// --- Login throttle ---
define('LOGIN_MAX_ATTEMPTS',  5);
define('LOGIN_LOCK_MINUTES',  15);

// --- Session ---
define('SESSION_LIFETIME',    7200); // 2 jam

date_default_timezone_set(APP_TZ);

// Error reporting (matikan display di production)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');

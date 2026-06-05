# SIMONJU Scanner Judol — Quick Install

## File yang ada di paket ini

```
ppj/
├── scanner_judol.php           # Modul inti scanner (klasifikasi, cloaking detection, scoring)
├── cron_scan_judol.php         # Cron job khusus scanner judol
├── scan_judol_run.php          # Trigger manual scan satu jurnal (dari dashboard)
├── scan_judol_run_all.php      # Trigger manual scan SEMUA jurnal (real-time output)
├── scan_judol_log.php          # Halaman log/dashboard hasil scan
├── scan_judol_detail.php       # Halaman detail per hasil scan
├── _header.php                 # Updated: tambah menu "🛡️ Scanner Judol"
├── install_scanner_judol.sql   # Schema tambahan database (idempotent)
├── config_addition.txt         # Tambahan untuk config.php (manual paste)
├── PLAYBOOK_RECOVERY_OJS.md    # Playbook teknis recovery (referensi)
└── README_SCANNER.md           # File ini
```

## Cara install (10 menit)

### 1. Upload file

Via cPanel File Manager, upload ke folder PPJ Anda (`ppj.jurnalsinta.id` root):
- `scanner_judol.php`
- `cron_scan_judol.php`
- `scan_judol_run.php`
- `scan_judol_run_all.php`
- `scan_judol_log.php`
- `scan_judol_detail.php`
- `_header.php` (replace yang lama)

### 2. Tambahkan constant di config.php

Buka `config.php`, tambahkan baris dari `config_addition.txt` setelah blok "Crawler behavior".

Minimal yang wajib (tanpa Telegram, kosongkan saja):

```php
define('JUDOL_SCANNER_TIMEOUT',  25);
define('JUDOL_SCANNER_DELAY_MS', 2000);
define('JUDOL_NORMAL_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36');
define('JUDOL_BOT_UA',    'Googlebot/2.1 (+http://www.google.com/bot.html)');
define('JUDOL_TELEGRAM_BOT_TOKEN', '');
define('JUDOL_TELEGRAM_CHAT_ID',   '');
```

### 3. Jalankan migrasi database

phpMyAdmin → pilih database `jurz2196_ppj` → tab **Import** → upload `install_scanner_judol.sql` → Go.

Akan menambahkan:
- Tabel baru `judol_scan_log`
- Kolom baru di `jurnals`: `last_judol_scan_at`, `last_judol_score`, `last_judol_label`

Idempotent — aman dijalankan ulang.

### 4. Setup cron

cPanel → **Cron Jobs** → Add new cron job:

**Untuk monitoring rutin (4x sehari)**:
```
0 2,8,14,20 * * * curl -s "https://ppj.jurnalsinta.id/cron_scan_judol.php?token=CRON_TOKEN_ANDA" > /dev/null 2>&1
```

**Untuk situasi outbreak aktif (setiap jam)**:
```
0 * * * * curl -s "https://ppj.jurnalsinta.id/cron_scan_judol.php?token=CRON_TOKEN_ANDA" > /dev/null 2>&1
```

(Pakai CRON_TOKEN yang sama dengan yang sudah ada untuk `cron_crawl.php`)

### 5. Test manual

1. Login ke SIMONJU
2. Klik menu **🛡️ Scanner Judol** (baru, di top nav)
3. Klik tombol **Scan Semua Sekarang**
4. Lihat output real-time di browser
5. Setelah selesai, lihat hasil di halaman log dengan filter HACKED/SUSPICIOUS/WARN/CLEAN

## Setup Telegram Alert (opsional tapi sangat berguna)

Anda sudah punya bot `@mizpbg_bot` untuk Brilian. Bisa dipakai ulang, atau bikin baru.

Untuk dapat `chat_id` Anda:
1. Send `/start` ke `@userinfobot` di Telegram → akan kasih chat ID
2. Atau kalau mau alert ke group: tambahkan bot ke group, kirim pesan apa saja, lalu buka:
   `https://api.telegram.org/bot{BOT_TOKEN}/getUpdates`

Isi 2 constant ini di `config.php`:
```php
define('JUDOL_TELEGRAM_BOT_TOKEN', '7890:AAH...');
define('JUDOL_TELEGRAM_CHAT_ID',   '123456789');  // atau '-100123...' untuk group
```

Test kirim alert: scan satu jurnal yang Anda tahu kena (misal Performance Unsoed). Kalau skor >= 50, alert akan masuk Telegram.

## Cara kerja scanner (singkat)

Untuk setiap jurnal, scanner:

1. **Fetch dengan UA Mozilla biasa** → klasifikasi halaman
2. **Fetch dengan UA Googlebot** → klasifikasi halaman
3. **Bandingkan kedua hasil** → deteksi cloaking
4. **Hitung skor risiko 0-100** berdasarkan:
   - Tipe klasifikasi (defaced/injected/clean)
   - Jumlah keyword judol terdeteksi
   - Jumlah anchor link bertema judol
   - Title hijack atau tidak
   - Cloaking aktif atau tidak
5. **Label**: CLEAN (<20), WARN (20-49), SUSPICIOUS (50-79), HACKED (≥80)
6. **Simpan ke database** + **kirim alert Telegram** kalau skor ≥ 50

## Kalau ada false positive

Skenario yang bisa false positive: jurnal tentang ekonomi yang membahas "judi online dalam ekonomi digital", jurnal hukum yang membahas regulasi togel, dll.

Kalau muncul, edit `scanner_judol.php` di fungsi `judol_keywords_high()` — hapus keyword yang menyebabkan false positive untuk konteks Anda. Atau adjust threshold di `judol_classify_page()`:
- Naikkan `total_hits >= 3` (untuk injected) jadi `>= 5` atau `>= 10`
- Naikkan `judol_anchor >= 30` (untuk defaced) jadi `>= 50`

## Catatan keamanan

- Scanner ini **read-only** — hanya HTTP GET. Tidak bisa apa-apa selain "membaca" halaman publik jurnal target.
- Tidak ada login, tidak ada upload, tidak ada modifikasi target.
- Aman secara hukum — sama seperti Googlebot melakukan crawling.
- Jeda 2 detik antar request (configurable via `JUDOL_SCANNER_DELAY_MS`) supaya tidak overload server target.

## Dokumentasi lengkap recovery teknis

Lihat `PLAYBOOK_RECOVERY_OJS.md`. Ini referensi step-by-step recovery untuk Anda saat handle klien jasa recovery.

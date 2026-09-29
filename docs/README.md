# PPJ Unsoed — Portal Pengelolaan Jurnal

Aplikasi PHP+MySQL sederhana, tanpa framework, untuk mengelola informasi jurnal
dan crawler periodik halaman OJS `issue/archive`.

## Stack

- PHP 7.3 (cPanel `jurnalsinta.id`)
- MySQL via mysqli (prepared statements)
- Tanpa Composer, tanpa framework, tanpa JS framework

## File Inti

```
config.php          - Kredensial DB & secret keys (WAJIB diedit)
db.php              - Helper koneksi & query
auth.php            - Session, login, CSRF, throttle
crawler.php         - Engine crawler OJS
index.php           - Login page
logout.php
dashboard.php       - List semua jurnal
jurnal_form.php     - Tambah/edit jurnal
jurnal_view.php     - Detail jurnal + riwayat terbitan
jurnal_delete.php
crawl_run.php       - Trigger crawl manual (POST)
cron_crawl.php      - Endpoint cron job (token-protected)
crawl_log.php       - History semua aktivitas crawler
account.php         - Ganti password
_header.php / _footer.php - layout partial
assets/style.css
.htaccess           - HTTPS redirect, file protection
install.sql         - Schema database
```

## Instalasi (cPanel)

### 1. Siapkan subdomain & database

- cPanel → **Subdomains** → buat `ppj.jurnalsinta.id` (root: `public_html/ppj`)
- cPanel → **MySQL Databases** → buat database, user, dan assign user dengan **ALL PRIVILEGES**
- Catat: nama DB, user DB, password DB

### 2. Set PHP version

- cPanel → **MultiPHP Manager** → set domain `ppj.jurnalsinta.id` ke **PHP 7.3**

### 3. Upload file

Upload semua file dalam folder ini ke `public_html/ppj/` via File Manager
(termasuk file `.htaccess` dan folder `assets/`). Pastikan struktur sama persis.

### 4. Import database

- phpMyAdmin → pilih database → tab **Import** → upload `install.sql` → Go
- Default user: `admin` / `admin123` (WAJIB ganti setelah login)

### 5. Edit `config.php`

```php
define('DB_NAME', 'jurz2196_ppj');
define('DB_USER', 'jurz2196_ppj');
define('DB_PASS', 'password_db_anda');
define('APP_SECRET', 'string_acak_min_32_char');
define('CRON_TOKEN', 'token_acak_panjang');
```

Cara generate string acak (di Terminal cPanel atau SSH):
```
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
```

Atau buka: https://www.random.org/strings/

### 6. Test akses

- Buka https://ppj.jurnalsinta.id → harus muncul halaman login
- Login: `admin` / `admin123`
- Klik **Akun** → ganti password segera
- Tambah jurnal pertama, klik **Crawl Sekarang** untuk test

### 7. Setup Cron Job

cPanel → **Cron Jobs** → Add New Cron Job

**Jadwal disarankan:** setiap hari pukul 02:00 dini hari
- Minute: `0`
- Hour: `2`
- Day/Month/Weekday: `*`

**Command:**
```
curl -s "https://ppj.jurnalsinta.id/cron_crawl.php?token=NILAI_CRON_TOKEN_ANDA" > /home/USERNAME/ppj_cron.log 2>&1
```

Ganti `NILAI_CRON_TOKEN_ANDA` dengan nilai `CRON_TOKEN` di `config.php`,
dan `USERNAME` dengan username cPanel Anda.

## Catatan Crawler

- Crawler dirancang untuk OJS 2.x dan 3.x (mayoritas jurnal Unsoed pakai OJS).
- Selector utama: `div.obj_issue_summary` (OJS 3) dan link `/issue/view/` (OJS 2).
- Untuk setiap issue, crawler juga membuka halaman detail untuk menghitung
  jumlah artikel (selector `div.obj_article_summary` atau link `/article/view/`).
- Insert pakai `ON DUPLICATE KEY UPDATE` dengan UNIQUE key (jurnal_id, volume, nomor, tahun)
  → aman dijalankan berulang kali, tidak akan ada duplikat.
- Jika sebuah jurnal pakai struktur HTML non-OJS, crawler hanya akan mendapat
  hasil parsial atau kosong; bisa dilihat di **Log Crawler**.

## History Akreditasi SINTA

- Buka **ED Akreditasi → History Akreditasi** (`admin/rubrik.php?view=history`).
  Saat belum ada data, tab memulai sinkronisasi pertama otomatis. Setelahnya,
  tombol **Sinkronkan dari SINTA** memperbarui hasil yang tersimpan.
- Sumber daftar adalah `https://sinta.kemdiktisaintek.go.id/journals/index/7`
  (afiliasi Unsoed). Engine membaca semua halaman, memeriksa jumlah jurnal unik
  terhadap total SINTA, kemudian membuka setiap profil jurnal. Tidak bergantung
  pada daftar jurnal yang sudah terdaftar di SIMONJU.
  Form urutan Citations SINTA digunakan untuk mengurangi urutan yang berubah;
  jika masih ada ID duplikat lintas halaman, daftar diperiksa hingga tiga putaran
  dan digabung berdasarkan ID sebelum dinyatakan lengkap.
- Tabel menampilkan setiap tahun dan peringkat pada **History Accreditation**,
  ISSN, penerbit, peringkat saat ini, tautan sumber, dan waktu pengambilan.
  Tahun yang kosong tidak diisi otomatis dan tahun artikel tidak ikut dihitung.
  Hanya jurnal yang memiliki history yang tampil. Filter **Habis akreditasi**
  menawarkan tahun berjalan hingga empat tahun berikutnya, berdasarkan tahun
  history terakhir yang tercantum di profil SINTA. **Export Excel** mengunduh satu
  workbook dengan lima sheet, satu untuk tiap tahun yang tersedia pada filter.
- Profil ganda Jurnal Akuntansi, Manajemen dan Ekonomi (SINTA 3809 / 9842)
  diwakili profil 9842, sesuai pilihan PPJ; profil 3809 tidak disimpan saat sinkronisasi.
- Satu permintaan hanya mengambil satu halaman/profil. Biarkan tab terbuka selama
  proses; **Jeda**, **Lanjutkan sinkronisasi**, dan **Ulangi dari awal** tersedia.
  Menutup tab menghentikan permintaan selanjutnya; pekerjaan tersimpan dapat
  dilanjutkan. Sinkronisasi memakai lock database agar dua admin tidak menulis
  bersamaan, jeda `CRAWLER_DELAY_MS`, timeout `CRAWLER_TIMEOUT`, dan User-Agent
  browser dari `crawler_ua()` yang sudah digunakan crawler aplikasi.
- Tabel `sinta_history_state` dibuat otomatis saat sinkronisasi pertama (perlu
  izin CREATE). PHP memerlukan cURL, DOM, mysqli, dan dukungan CA HTTPS yang valid.
  Verifikasi sertifikat tetap aktif; CA custom aplikasi dicoba jika CA sistem gagal.
- Hasil terakhir dipertahankan selama proses. Jika profil gagal diperbarui, data
  lama untuk profil tersebut tetap tampil dengan penanda; profil yang belum pernah
  berhasil diambil ditandai secara eksplisit. Daftar error dapat dibuka di tab;
  **Coba ulang profil gagal** hanya mengambil ulang profil yang bermasalah.
  Profil lengkap yang tidak menyediakan bagian history ditandai **Tidak tersedia
  di SINTA**, termasuk ketika profil masih memiliki peringkat saat ini.
  Jika paginasi/struktur berubah atau akses diblokir, proses tidak dianggap lengkap.
  Perbaiki penyebab lalu lanjutkan atau ulangi dari awal jika daftar berubah.
- Endpoint `admin/sinta_history_sync.php` hanya menerima POST dari admin/operator
  yang login dengan CSRF valid. Tidak mengubah data master atau akreditasi jurnal
  yang diisi manual. Pengujian parser: `php tests/sinta_history_test.php`.

## Keamanan

- ✅ Password bcrypt (`password_hash` / `password_verify`)
- ✅ Prepared statements untuk semua query (anti SQL injection)
- ✅ CSRF token di setiap form POST
- ✅ Session: regenerate ID setelah login, httponly + samesite=strict, timeout 2 jam
- ✅ Login throttle: 5 percobaan gagal → akun lock 15 menit
- ✅ Output di-escape dengan `htmlspecialchars()` (anti XSS)
- ✅ `.htaccess` block akses langsung ke config/db/auth/crawler
- ✅ Force HTTPS
- ✅ Cron token-protected
- ✅ Security headers (X-Frame-Options, X-Content-Type, dll)

## Troubleshooting

**Halaman putih / 500 error**
→ cek `error.log` di folder `ppj/` (auto-generate)

**"Database connection error"**
→ cek `DB_*` di `config.php` cocok dengan cPanel

**Crawler selalu "found=0"**
→ buka URL archive di browser, pastikan benar dan halaman bisa diakses publik
→ cek apakah HTML-nya pakai struktur OJS standar (lihat Inspector → cari `obj_issue_summary`)

**Cron tidak jalan**
→ cek log cPanel Cron, pastikan URL & token benar
→ test manual: `curl "https://ppj.jurnalsinta.id/cron_crawl.php?token=XXX"`

**Lupa password admin**
→ generate hash baru dengan `php -r "echo password_hash('passbaru', PASSWORD_BCRYPT);"`
→ phpMyAdmin → tabel `users` → update kolom `password_hash`

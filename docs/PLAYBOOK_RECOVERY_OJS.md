# PLAYBOOK: Recovery Jurnal OJS Kena Hack Judol

**Versi:** 1.0 — 4 Mei 2026
**Berdasarkan:** Riset thread PKP Forum (Oct 2025 - Apr 2026), CVE database, kasus Unsoed JOS, dan SOP forensik web umum.

---

## RANGKUMAN PATTERN SERANGAN (yang sedang aktif menyerang Indonesia)

Berdasarkan thread PKP Forum, khususnya kasus Tarcisio Pereira (USP, Brasil — Oktober 2025) dan kasus serupa di banyak universitas Indonesia, pola serangan yang dominan saat ini adalah:

### Modus Operandi

1. **Pintu masuk** — salah satu dari:
   - **OJS versi out-of-date** (paling umum). CVE yang sering dieksploitasi:
     - **CVE-2024-56525** (XXE → privilege escalation jadi super admin, via User XML Plugin) — affect <3.3.0.21 dan <3.4.0.8
     - Bundle CVE-2024-25434/25436/25438 + zero-day (XSS chain → admin escalation, dilaporkan oleh openjournaltheme.com)
     - CVE lama soal upload file (.phtml di submission), CSRF, SSRF
   - **Password admin/manager lemah atau kebocoran** dari data breach platform lain
   - **Plugin OJS pihak ketiga vulnerable**
   - **`files_dir` yang berada di dalam web root** (kesalahan konfigurasi yang umum)
   - **Hosting bersama (shared cPanel)** — vhost lain di server yang sama kena, lalu lateral movement

2. **Aksi pasca-akses**:
   - **Modifikasi file core OJS** — paling sering: `lib/pkp/includes/bootstrap.php` (terkonfirmasi di kasus USP). Logika cloaking disisipkan di awal eksekusi flow:
     ```php
     if (preg_match('/Googlebot|bingbot|Yandex/i', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
         // serve halaman judol
     }
     ```
   - **Drop plugin generic palsu** di `plugins/generic/` (misal: shell `Alfa Team SSI`)
   - **Drop file `.shtml`** di webroot (sering luput dari cleanup karena bukan `.php`)
   - **Modifikasi `.htaccess`** untuk rewrite kondisional
   - **Injection di database** — `journal_settings`, `submission_settings`, `issue_settings`
     terutama field `additionalHomeContent`, `description`, `homepageImage`
   - **Buat user backdoor** dengan role admin/manager (dengan nama-nama seperti `hojs`, `seoadmin`, dll)

3. **Tujuan akhir**:
   - **SEO poisoning** via cloaking Googlebot. Halaman terlihat normal di browser pengelola, tapi Google index halaman judol → ranking judol naik di hasil pencarian
   - **Defacement penuh** untuk jurnal yang kurang prioritas → halaman jadi link farm 1500+ link judol
   - **Backlink farming** untuk meningkatkan otoritas domain judol di mata search engine

---

## FASE 0 — TRIASE (15-30 menit)

**Jangan langsung hapus apa pun.** Triase dulu untuk confirm tipe dan severity.

### Cek 1: Cloaking detection

```bash
# Browser biasa
curl -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124.0" \
     -L -o /tmp/normal.html https://jurnal-target.example.com/

# Googlebot
curl -A "Googlebot/2.1 (+http://www.google.com/bot.html)" \
     -L -o /tmp/bot.html https://jurnal-target.example.com/

# Bandingkan
diff <(grep -ic "slot\|judi\|togel\|gacor" /tmp/normal.html) \
     <(grep -ic "slot\|judi\|togel\|gacor" /tmp/bot.html)
```

Kalau jumlah keyword di `bot.html` jauh lebih besar → **cloaking aktif** → high priority.

### Cek 2: Google site search

```
site:jurnal-target.example.com slot
site:jurnal-target.example.com gacor
site:jurnal-target.example.com togel
```

Kalau ada hasil, halaman judol sudah ke-index Google. Catat URL spesifik untuk cleanup.

### Cek 3: Snapshot forensik

**Sebelum sentuh server**, snapshot kondisi sekarang. Penting untuk forensik dan jaga-jaga kalau cleanup malah merusak sesuatu.

```bash
ssh user@hosting
cd ~
mkdir forensic-$(date +%F)
cd forensic-$(date +%F)
tar -czf webroot-snapshot.tar.gz ~/public_html/
mysqldump -u USER -p DBNAME > db-snapshot.sql
# Simpan info user OJS untuk audit
mysql -u USER -p DBNAME -e "SELECT user_id, username, email, date_registered, date_last_login FROM users ORDER BY date_registered DESC LIMIT 50;" > users-recent.txt
```

Snapshot ini **JANGAN dihapus minimal 30 hari**.

### Cek 4: Versi OJS

```bash
cd ~/public_html
cat dbscripts/xml/version.xml | grep -A1 release
# atau lihat di file: lib/pkp/registry/components.xml
```

Versi <3.3.0.21 atau <3.4.0.8 → wajib upgrade (CVE-2024-56525).
Versi <3.3.0.18 → wajib upgrade (CVE chain XSS escalation).
Per Mei 2026: latest 3.3.0-22, 3.4.0-10, 3.5.0-3.

### Cek 5: User OJS suspicious

```sql
-- User baru yang dibuat dalam 60 hari terakhir
SELECT user_id, username, email, date_registered, date_last_login
FROM users
WHERE date_registered > DATE_SUB(NOW(), INTERVAL 60 DAY)
ORDER BY date_registered DESC;

-- User dengan role admin/manager
SELECT u.user_id, u.username, u.email, ug.role_id, j.path AS journal
FROM users u
JOIN user_user_groups uug ON u.user_id = uug.user_id
JOIN user_groups ug       ON uug.user_group_id = ug.user_group_id
LEFT JOIN journals j      ON ug.context_id = j.journal_id
WHERE ug.role_id IN (1, 16)  -- 1=Site Admin, 16=Journal Manager
ORDER BY u.user_id DESC;
```

Catat user yang **tidak Anda kenal** atau **dibuat persis sebelum hack terdeteksi**.

---

## FASE 1 — CONTAINMENT (1 jam)

Tujuan: stop pendarahan tanpa kehilangan bukti.

### Langkah 1.1 — Maintenance mode

Pilihan A (lebih aman): rename `index.php` → `index.php.bak`, taruh `index.html` sederhana:

```html
<!doctype html>
<html><head><title>Maintenance</title></head>
<body><h1>Pemeliharaan Sistem</h1>
<p>Jurnal sedang dalam pemeliharaan keamanan. Silakan kembali dalam 24-48 jam.</p>
</body></html>
```

Pilihan B: IP whitelist di `.htaccess` root sehingga hanya admin yang bisa akses:
```apache
Order deny,allow
Deny from all
Allow from 203.0.113.42  # IP admin
```

### Langkah 1.2 — Catat akreditasi sebelum hilang

Screenshot:
- Profile Sinta jurnal (sinta.kemdiktisaintek.go.id)
- Profile Scopus (scopus.com/sourceid/...)
- DOAJ entry (doaj.org/toc/...)
- Crossref deposit history
- Google Search Console saat ini (kalau ada akses)

Kalau hack parah dan harus rollback ke backup lama, screenshot ini jadi referensi terakhir terbitan resmi.

### Langkah 1.3 — Reset semua kredensial

- Password cPanel
- Password database (di cPanel > MySQL Databases > User), update di `config.inc.php`
- Password semua user OJS dengan role admin/manager: di phpMyAdmin reset hash dengan password baru, set `must_change_password` = 1
- Token API kalau ada (Crossref, ORCID, dll)
- Disable/hapus user yang tidak dikenal (yang ditemukan di Cek 5 Fase 0)

### Langkah 1.4 — Rotasi APP_KEY

Kalau ada `salt` atau encryption key di `config.inc.php`, rotate.

---

## FASE 2 — ERADICATION (4-12 jam)

Strategi yang **direkomendasikan oleh Alec Smecher (lead PKP)**: jangan trust isi web root, rebuild dari fresh tarball.

### Langkah 2.1 — Quarantine seluruh web root

```bash
ssh user@hosting
cd ~
mv public_html public_html-quarantine-$(date +%F)
mkdir public_html
chmod 755 public_html
```

### Langkah 2.2 — Download fresh OJS

```bash
cd ~
# Pakai versi LATEST sesuai branch yang Anda pakai
# Jika sebelumnya 3.3.0-x, upgrade ke 3.3.0-22 (LTS)
# Jika sebelumnya 3.4.0-x, upgrade ke 3.4.0-10
wget https://pkp.sfu.ca/ojs/download/ojs-3.3.0-22.tar.gz
sha256sum ojs-3.3.0-22.tar.gz   # bandingkan dengan checksum di pkp.sfu.ca
tar -xzf ojs-3.3.0-22.tar.gz
mv ojs-3.3.0-22/* ojs-3.3.0-22/.* public_html/ 2>/dev/null
rmdir ojs-3.3.0-22
```

### Langkah 2.3 — Selektif restore yang dipercaya

Hanya pulihkan komponen ini dari quarantine:

```bash
cd public_html

# 1. Config (TAPI cek dulu manual, ada kemungkinan diubah)
# Diff dengan template default sebelum restore:
diff config.TEMPLATE.inc.php ~/public_html-quarantine-*/config.inc.php
# Kalau bersih, restore. Kalau ada modifikasi suspicious, tulis ulang manual.
cp ~/public_html-quarantine-*/config.inc.php config.inc.php

# 2. Files dir (HARUS di luar webroot - lihat config.inc.php)
# Folder `files` ini berisi submission asli — TIDAK BOLEH HILANG
# Lokasinya biasanya: ~/files/ (di luar public_html)
# Cek di config.inc.php: directive `files_dir`
# Kalau files_dir masih di dalam public_html, PINDAHKAN dulu ke luar.

# 3. Public assets (logo, gambar issue cover)
# HATI-HATI: folder ini sering jadi tempat shell PHP dropped.
# Restore selektif setelah audit:
cd ~/public_html-quarantine-*/public/
find . -type f \( -name '*.php' -o -name '*.phtml' -o -name '*.shtml' -o -name '*.html' \) -ls
# Hapus semua yang muncul di list di atas (TIDAK boleh ada file executable di public/)
find . -type f \( -name '*.php' -o -name '*.phtml' -o -name '*.shtml' \) -delete
# Lalu copy yang aman:
cp -r * ~/public_html/public/
```

### Langkah 2.4 — Audit database

```sql
-- Cari injection di journal_settings
SELECT journal_id, setting_name, LEFT(setting_value, 200) AS preview
FROM journal_settings
WHERE LOWER(CONVERT(setting_value USING utf8))
      REGEXP '(slot|gacor|maxwin|togel|judi|musang|sbobet|mahjong)';

-- Cari di issue_settings
SELECT issue_id, setting_name, LEFT(setting_value, 200) AS preview
FROM issue_settings
WHERE LOWER(CONVERT(setting_value USING utf8))
      REGEXP '(slot|gacor|maxwin|togel|judi)';

-- Cari di submission_settings
SELECT submission_id, setting_name, LEFT(setting_value, 200) AS preview
FROM submission_settings
WHERE LOWER(CONVERT(setting_value USING utf8))
      REGEXP '(slot|gacor|maxwin|togel|judi)';

-- Cari di static_pages (kalau plugin ini dipakai)
SELECT * FROM static_pages
WHERE LOWER(CONVERT(content USING utf8))
      REGEXP '(slot|gacor|maxwin|togel)';

-- User yang ditandai aktif tapi mencurigakan
SELECT user_id, username, email, date_registered
FROM users
WHERE LOWER(email) REGEXP '(slot|gacor|togel|spam)'
   OR LOWER(username) REGEXP '(admin|root|seo)[0-9]+';
```

Tindakan: untuk setiap row yang muncul, decide manual: delete/sanitize. **Backup table dulu sebelum modify** (`CREATE TABLE journal_settings_bak AS SELECT * FROM journal_settings`).

### Langkah 2.5 — Run upgrade DB OJS

```bash
cd ~/public_html
php tools/upgrade.php upgrade
```

Ini menjalankan migrasi schema OJS ke versi baru. Pastikan tidak ada error sebelum lanjut.

### Langkah 2.6 — Verifikasi

```bash
# Cek versi OJS yang aktif
curl -s https://jurnal-target.example.com/ | grep -i "Open Journal Systems"

# Re-run cloaking test (Fase 0 Cek 1) — harus tidak ada lagi keyword judol di kedua response
```

---

## FASE 3 — HARDENING (2-4 jam)

Setelah bersih, lakukan ini supaya tidak kena lagi.

### Langkah 3.1 — Disable PHP execution di folder upload

Buat `.htaccess` di setiap folder berikut:

`public/.htaccess`, `public/journals/.htaccess`, `public/site/.htaccess`, `cache/.htaccess`:

```apache
<FilesMatch "\.(php|phtml|php3|php4|php5|php7|phps|pht|pl|py|cgi|asp|aspx|jsp|shtml)$">
  Require all denied
</FilesMatch>
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .shtml
RemoveType    .php .phtml .php3 .php4 .php5 .php7 .shtml
php_flag engine off
```

### Langkah 3.2 — Pindahkan files_dir ke luar webroot

Edit `config.inc.php`:

```ini
[files]
files_dir = /home/USER/ojs-files
```

Pastikan `/home/USER/ojs-files` ada dan owner sama dengan PHP user.

### Langkah 3.3 — Block akses ke vendor lib

Buat `lib/.htaccess`:

```apache
Require all denied
```

Ini memblokir akses langsung ke file di `lib/pkp/lib/vendor/` (yang menurut PKP forum bisa berisi `sponsors.php` dengan link gambling otomatis dari Composer).

### Langkah 3.4 — Pasang plugin keamanan

**File Integrity Scanner** (gratis, dari ashvisualtheme):
https://github.com/ashvisualtheme/file-integrity-scanner

- Scan harian otomatis
- Bandingkan hash file dengan repository PKP resmi
- Alert email kalau ada file modified/added/deleted

**Security Headers Plugin** (gratis):
https://github.com/ashvisualtheme/security-headers-plugin

- CSP, HSTS, X-Frame-Options, dll

**Control Public Upload** (resmi PKP):
- Blokir upload file `.php`, `.phtml`, dll dari fitur galley/cover image

### Langkah 3.5 — 2FA untuk admin

OJS 3.3+ support TOTP 2FA. Aktifkan untuk semua user admin/manager. Settings > Website > Setup > Two-Factor Authentication.

### Langkah 3.6 — Cloudflare WAF

Cloudflare free tier sudah cukup. Aktifkan:
- **Bot Fight Mode** ON
- **Security Level** = High
- **Custom Firewall Rule**:
  - Block requests where URI Path contains `slot|gacor|togel|judi|maxwin`
  - Block requests where URI Path matches `\.(php|phtml|shtml|asp|aspx|jsp)$` AND Path starts with `/public/` atau `/cache/` atau `/files/`

### Langkah 3.7 — File integrity baseline

```bash
cd ~/public_html
find . -type f \( -name "*.php" -o -name "*.tpl" -o -name "*.htaccess" \) -exec sha256sum {} \; | sort > ~/baseline-$(date +%F).sha256
```

Setup cron harian yang bandingkan baseline ini dan kirim alert ke Telegram/email kalau ada selisih:

```bash
#!/bin/bash
# /home/user/scripts/check-integrity.sh
cd ~/public_html
find . -type f \( -name "*.php" -o -name "*.tpl" -o -name "*.htaccess" \) -exec sha256sum {} \; | sort > /tmp/current.sha256
diff ~/baseline-XXXX-XX-XX.sha256 /tmp/current.sha256 > /tmp/diff.txt
if [ -s /tmp/diff.txt ]; then
  curl -s -X POST "https://api.telegram.org/bot$TG_TOKEN/sendMessage" \
       -d chat_id="$TG_CHAT_ID" \
       -d text="🚨 File integrity changed on $(hostname):
$(cat /tmp/diff.txt | head -50)"
fi
```

---

## FASE 4 — RECOVERY REPUTASI

### Langkah 4.1 — Google Search Console

1. Buka https://search.google.com/search-console
2. Add property → verify ownership
3. Submit re-review request:
   - Security Issues → Request Review
   - Manual Actions → Request Review
4. Resubmit sitemap (via cPanel atau plugin OJS)

Tanpa langkah ini, traffic jurnal tetap drop berbulan-bulan karena warning "this site may be hacked" di hasil pencarian.

### Langkah 4.2 — Fetch as Googlebot test

Di Search Console, jalankan **URL Inspection** untuk URL utama:
- Halaman archive
- Halaman about
- Halaman beberapa article

Pastikan "Live Test" menampilkan konten asli, bukan judol.

### Langkah 4.3 — Sinta dan akreditasi

Untuk Sinta: tidak ada aksi khusus, tapi simpan screenshot bukti recovery untuk arsip akreditasi.

Untuk Scopus: kalau metadata DOI Crossref sempat ke-injection, kontak Crossref support untuk koreksi.

### Langkah 4.4 — Komunikasi ke author/reader

Pengumuman singkat di homepage jurnal:

> Pemeliharaan Keamanan Sistem
>
> Pada [tanggal], kami melakukan pemeliharaan keamanan sistem journal management. Tidak ada data submission, naskah, atau review yang hilang. Operasional jurnal kembali normal sejak [tanggal+2]. Kami mohon maaf atas ketidaknyamanan.

Jangan detail tentang hack judol — pengumuman publik tentang itu hanya menarik perhatian buruk.

---

## FASE 5 — MONITORING BERKELANJUTAN

Ini bagian retainer service.

### Layer 1: SIMONJU Scanner Judol (yang sudah dibuat)

- Cron 4x sehari (atau 1x/jam saat outbreak aktif)
- Dual UA detection (browser + Googlebot)
- Alert Telegram kalau skor >= 50

### Layer 2: File Integrity (cron harian)

- Baseline sha256 + diff check
- Alert kalau ada file PHP/htaccess berubah

### Layer 3: Uptime + cert monitoring

- UptimeRobot gratis (5 monitor)
- SSL expiry alert

### Layer 4: Monthly Health Report

Setiap bulan kirim ke pengelola:
- Status keamanan: berapa scan, berapa anomali
- OJS version status (up-to-date atau perlu upgrade)
- File integrity report
- SSL valid sampai kapan
- Saran action items

---

## REFERENSI

### Forum & dokumentasi resmi PKP

1. **MEMO: Keeping your OJS Installation Secure** (Oct 2025)
   https://forum.pkp.sfu.ca/t/memo-keeping-your-ojs-installation-secure-with-upgrades-and-more/97250

2. **Possible widespread cloaking attack affecting OJS sites** (Oct-Nov 2025)
   https://forum.pkp.sfu.ca/t/possible-widespread-cloaking-attack-affecting-ojs-sites-googlebot-only-gambling-spam/97329
   → Kasus USP Brasil, modifikasi `lib/pkp/includes/bootstrap.php`

3. **Remove malware from OJS** (Dec 2025)
   https://forum.pkp.sfu.ca/t/remove-malware-from-ojs/97581
   → Saran resmi Alec Smecher: quarantine + rebuild dari tarball

4. **OJS 3 HACKED — script in index** (2022)
   https://forum.pkp.sfu.ca/t/ojs-3-hacked-someone-had-pasted-the-script-in-the-ojs-index/75680

5. **Security issue: hacking via submission** (2017)
   https://forum.pkp.sfu.ca/t/security-issue-hacking-of-ojs-3-0-1-via-submission/30241

### CVE referensi

- **CVE-2024-56525**: XXE → super admin via User XML Plugin (<3.3.0.21, <3.4.0.8)
  https://github.com/advisories/GHSA-2vfq-pq87-ph87

- **CVE-2024-25434, 25436, 25438** + zero-day: XSS chain → admin escalation (<3.3.0.18)
  https://openjournaltheme.com/urgent-critical-vulnerabilities-in-3-3-0-18-upgrade-your-ojs-now/

- **CVE-2024-24511**: Stored XSS via Input Title

- **CVE-2024-7902**: Open Redirect

### Tools

- **OJS Download**: https://pkp.sfu.ca/software/ojs/download/
- **File Integrity Scanner Plugin**: https://github.com/ashvisualtheme/file-integrity-scanner
- **Security Headers Plugin**: https://github.com/ashvisualtheme/security-headers-plugin
- **Upgrade Guide**: https://docs.pkp.sfu.ca/dev/upgrade-guide/en/

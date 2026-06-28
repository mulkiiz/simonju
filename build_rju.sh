#!/usr/bin/env bash
# Regenerasi folder staging rju/ untuk upload manual ke rju.unsoed (WinSCP).
# Jalankan dari root repo:  bash build_rju.sh
# ppj = auto (cpanel git webhook); rju = manual via folder ini.
set -e
cd "$(dirname "$0")"

# File yang dibutuhkan deployment rju (client). TIDAK termasuk:
#   - includes/config.php (per-server)
#   - api/terbitan.php, cron/cron_crawl.php (khusus ppj/source)
#   - cron/sync.php, cron/feed_*.php (dihapus AV — sudah tidak dipakai)
FILES="
lib/crawler.php
lib/feeder.php
cron/run.php
includes/header_admin.php
includes/header_jurnal.php
includes/stat_analytics.php
includes/cacert.pem
admin/statistik.php
admin/export_dashboard.php
admin/export_katalog.php
admin/cron_health.php
admin/dashboard.php
admin/jurnal_view.php
admin/jurnal_form.php
admin/jurnal_delete.php
jurnal/index.php
konfirmasi/form.php
konfirmasi/jurnal_baru.php
"
SQLS="sql_scopus_quartile.sql sql_normalize_issn.sql sql_normalize_apc.sql sql_sync_log.sql"

# README dipertahankan; sisanya dibangun ulang.
find rju -type f ! -name 'README.txt' -delete 2>/dev/null || true
for f in $FILES; do mkdir -p "rju/$(dirname "$f")"; cp "$f" "rju/$f"; done
mkdir -p rju/_sql
for s in $SQLS; do [ -f "$s" ] && cp "$s" "rju/_sql/$s"; done

echo "Folder rju/ siap. Upload isinya ke web root rju (lihat rju/README.txt)."
find rju -type f | sort

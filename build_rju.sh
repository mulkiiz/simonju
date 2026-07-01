#!/usr/bin/env bash
# Stage file rju-relevant untuk upload manual ke rju.unsoed (WinSCP).
# Default: ambil hanya file yang BERUBAH di commit terakhir (HEAD) —
# jadi folder rju/ cuma berisi file yang baru saja dibahas, tinggal
# ditimpa di server.
#
# Pemakaian:
#   bash build_rju.sh            # file berubah di HEAD (default)
#   bash build_rju.sh HEAD~3..HEAD   # rentang commit
#   bash build_rju.sh --full     # SEMUA file rju-relevant (paket penuh)
#
# ppj = auto (cpanel git webhook); rju = manual via folder ini.
set -e
cd "$(dirname "$0")"

# --- File/pola yang TIDAK relevan untuk rju ---
not_relevant() {
  case "$1" in
    includes/config.php) return 0 ;;          # per-server
    api/*) return 0 ;;                          # khusus ppj (source)
    cron/cron_crawl.php) return 0 ;;            # khusus ppj
    cron/sync.php|cron/feed_pull.php|cron/feed_import.php) return 0 ;; # mati (AV)
    webhook_deploy.php) return 0 ;;            # ppj
    build_rju.sh|.cpanel.yml|.gitignore) return 0 ;;
    .github/*|docs/*|rju/*) return 0 ;;
    *) return 1 ;;
  esac
}

# --- Daftar semua file rju-relevant (untuk mode --full) ---
FULL_FILES="
lib/crawler.php
lib/feeder.php
lib/doi.php
cron/run.php
includes/auth.php
includes/header_admin.php
includes/header_jurnal.php
includes/stat_analytics.php
includes/cacert.pem
index.php
admin/statistik.php
admin/export_dashboard.php
admin/export_katalog.php
admin/cron_health.php
admin/dashboard.php
admin/jurnal_view.php
admin/jurnal_form.php
admin/jurnal_delete.php
admin/doi_requests.php
admin/doi_review.php
admin/doi_download.php
admin/account.php
jurnal/index.php
jurnal/doi.php
jurnal/akun.php
konfirmasi/form.php
konfirmasi/jurnal_baru.php
"

# Tentukan daftar file sumber
if [ "$1" = "--full" ]; then
  CHANGED="$FULL_FILES"
  MODE="PENUH (semua file rju-relevant)"
else
  RANGE="${1:-HEAD}"
  if [ "$RANGE" = "HEAD" ]; then
    CHANGED=$(git diff-tree --no-commit-id --name-only -r HEAD)
  else
    CHANGED=$(git diff --name-only "$RANGE")
  fi
  MODE="commit ${RANGE} (file yang berubah)"
fi

# Bersihkan rju/ (sisakan README), lalu salin yang relevan
find rju -type f ! -name 'README.txt' -delete 2>/dev/null || true
find rju -type d -empty -delete 2>/dev/null || true

n_code=0; n_sql=0
for f in $CHANGED; do
  [ -f "$f" ] || continue
  if not_relevant "$f"; then continue; fi
  case "$f" in
    sql_*.sql)
      mkdir -p rju/_sql; cp "$f" "rju/_sql/$(basename "$f")"; n_sql=$((n_sql+1)) ;;
    *.php|*.pem|assets/*)
      mkdir -p "rju/$(dirname "$f")"; cp "$f" "rju/$f"; n_code=$((n_code+1)) ;;
  esac
done

echo "Paket rju/ disiapkan dari: $MODE"
echo "  file kode: $n_code | migrasi SQL: $n_sql"
echo "Upload isi rju/ ke web root rju via WinSCP (timpa). Lihat rju/README.txt."
find rju -type f ! -name 'README.txt' | sort

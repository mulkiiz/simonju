-- =========================================================
-- Tabel jejak sinkron (dipakai deployment client rju.unsoed).
-- Diisi oleh lib/feeder.php tiap kali cron/run.php dijalankan.
-- Dibaca oleh admin/cron_health.php (bagian "Sync klien").
--
-- Jalankan sekali di server (rju & ppj boleh dua-duanya, ppj cuma
-- tidak terisi):
--   mysql -u USER -p NAMADB < sql_sync_log.sql
-- =========================================================

CREATE TABLE IF NOT EXISTS `sync_log` (
  `id`              INT(11) NOT NULL AUTO_INCREMENT,
  `run_at`          DATETIME DEFAULT CURRENT_TIMESTAMP,
  `status`          VARCHAR(20) DEFAULT NULL,
  `jurnal_baru`     INT(11) DEFAULT 0,
  `jurnal_update`   INT(11) DEFAULT 0,
  `terbitan_upsert` INT(11) DEFAULT 0,
  `message`         VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_run_at` (`run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

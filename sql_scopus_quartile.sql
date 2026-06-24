-- =========================================================
-- Pisahkan dimensi Scopus dari Sinta.
-- Sebelumnya jurnal Scopus disimpan sebagai akreditasi_jenis='sinta'
-- + is_scopus=1, tanpa kolom kuartil sendiri -> statistik peringkat
-- Scopus selalu 0. Tambah kolom scopus_q (Q1-Q4) & scopus_url terpisah.
--
-- Jalankan sekali di server:
--   mysql -u USER -p NAMADB < sql_scopus_quartile.sql
-- Idempotent-ish: aman diabaikan bila kolom sudah ada (akan error
-- "Duplicate column" -> berarti sudah ter-migrasi).
-- =========================================================

ALTER TABLE `jurnals`
  ADD COLUMN `scopus_q`   VARCHAR(4)   NULL AFTER `is_scopus`,
  ADD COLUMN `scopus_url` VARCHAR(500) NULL AFTER `scopus_q`;

-- Catatan: jurnal Scopus yang sudah ada (is_scopus=1) akan punya
-- scopus_q NULL. Set kuartilnya lewat form Edit Jurnal (centang
-- "Terindeks Scopus" -> pilih Q1-Q4), atau langsung mis.:
--   UPDATE jurnals SET scopus_q='Q1' WHERE id=2;
--   UPDATE jurnals SET scopus_q='Q2' WHERE id=4;

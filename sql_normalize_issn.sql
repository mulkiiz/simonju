-- =========================================================
-- Normalisasi P-ISSN / E-ISSN.
-- Nilai yang BUKAN format ISSN valid (xxxx-xxxx, digit cek terakhir
-- boleh X) -> diseragamkan jadi simbol strip '-'. Dengan begitu tag
-- "Belum ISSN" di dashboard/statistik menghitung dengan benar.
--
-- Jalankan sekali di server:
--   mysql -u USER -p NAMADB < sql_normalize_issn.sql
-- =========================================================

UPDATE jurnals SET p_issn='-'
 WHERE COALESCE(p_issn,'') NOT REGEXP '^[0-9]{4}-[0-9]{3}[0-9Xx]$';

UPDATE jurnals SET e_issn='-'
 WHERE COALESCE(e_issn,'') NOT REGEXP '^[0-9]{4}-[0-9]{3}[0-9Xx]$';

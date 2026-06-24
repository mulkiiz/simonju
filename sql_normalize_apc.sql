-- =========================================================
-- Normalisasi kolom APC.
-- Tidak ada konsep "Gratis": nilai 0 / kosong / NULL / 'Gratis' /
-- teks non-angka dianggap TIDAK ADA APC -> diseragamkan jadi '-'.
-- APC valid = bilangan bulat positif (mis. 500000) -> dibiarkan.
--
-- Jalankan sekali di server:
--   mysql -u USER -p NAMADB < sql_normalize_apc.sql
-- =========================================================

UPDATE jurnals
   SET apc = '-'
 WHERE COALESCE(apc,'') NOT REGEXP '^[1-9][0-9]*$';

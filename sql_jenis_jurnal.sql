-- Tambahkan jenis jurnal: Penelitian (default) atau Pengabdian.
-- Jalankan satu kali pada database Simonju sebelum memakai form/statistik baru.
ALTER TABLE jurnals
  ADD COLUMN jenis_jurnal ENUM('penelitian', 'pengabdian') NOT NULL DEFAULT 'penelitian'
  AFTER nama_jurnal;

-- Pastikan data lama memakai nilai default Penelitian.
UPDATE jurnals
   SET jenis_jurnal = 'penelitian'
 WHERE jenis_jurnal IS NULL OR jenis_jurnal = '';

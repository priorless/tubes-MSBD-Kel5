-- ============================================================
-- 19_log_append_only.sql — tbl_log_aktivitas bersifat append-only (jejak audit tidak bisa diubah/dihapus).
-- Dijalankan setelah 18_finalisasi_database.sql (oleh migration 2026_11_23_*_log_append_only).
--
-- Catatan pemeliharaan: tidak ada event pembersihan log yang memakai DELETE. Bila suatu saat log lama
-- perlu dibuang, gunakan ALTER TABLE tbl_log_aktivitas TRUNCATE/DROP PARTITION p<tahun> (operasi DDL
-- partisi tidak memicu trigger, dan partisi per tahun dari file 18 tetap berlaku).
-- ============================================================

-- [TAHAP 1]
-- Trigger BEFORE UPDATE dan BEFORE DELETE menolak perubahan log dengan SIGNAL 45000. INSERT tetap normal.
DELIMITER //

DROP TRIGGER IF EXISTS trg_log_aktivitas_tolak_update //
CREATE TRIGGER trg_log_aktivitas_tolak_update
BEFORE UPDATE ON tbl_log_aktivitas
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Log aktivitas tidak boleh diubah karena merupakan jejak audit';
END //

DROP TRIGGER IF EXISTS trg_log_aktivitas_tolak_delete //
CREATE TRIGGER trg_log_aktivitas_tolak_delete
BEFORE DELETE ON tbl_log_aktivitas
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Log aktivitas tidak boleh dihapus karena merupakan jejak audit';
END //

DELIMITER ;

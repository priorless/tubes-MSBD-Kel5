-- ============================================================
-- /sql/komponen/16_payroll_pph21_pencairan_rollback.sql
-- Mengembalikan objek yang diganti 16_payroll_pph21_pencairan.sql:
--   sp_update_status_bayar (file 05), trg_mutasi_payroll_cair & trg_mutasi_invoice_lunas (file 11, menulis Mutasi Kas),
--   dan menghapus fungsi PPh 21. sp_generate_payroll_bulanan & v_slip_gaji_detail dipulihkan dengan
--   menjalankan ulang 15_komponen_gaji_bpjs_klien.sql [TAHAP 1] (dilakukan oleh migrasi).
-- Dijalankan SEBELUM enum status_bayar dikembalikan ke 'Sudah Dibayar'.
-- ============================================================

-- [TAHAP 1]
DELIMITER //

DROP PROCEDURE IF EXISTS sp_update_status_bayar //
CREATE PROCEDURE sp_update_status_bayar(
  IN p_jenis VARCHAR(10), IN p_id INT, IN p_tanggal DATE)
BEGIN
  IF p_jenis = 'INVOICE' THEN
    UPDATE tbl_invoice SET status = 'Lunas', tgl_pembayaran = p_tanggal WHERE id = p_id;
  ELSEIF p_jenis = 'PAYROLL' THEN
    UPDATE tbl_payroll SET status_bayar = 'Sudah Dibayar', tgl_bayar = p_tanggal WHERE id = p_id;
  ELSE
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jenis harus INVOICE atau PAYROLL';
  END IF;
END //

DROP TRIGGER IF EXISTS trg_mutasi_payroll_cair //
CREATE TRIGGER trg_mutasi_payroll_cair
AFTER UPDATE ON tbl_payroll
FOR EACH ROW
BEGIN
  IF NOT (NEW.status_bayar <=> OLD.status_bayar) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_payroll', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status bayar payroll ', LPAD(NEW.periode_bulan, 2, '0'), '/', NEW.periode_tahun, ' karyawan #', NEW.id_karyawan,
                        ': ', OLD.status_bayar, ' -> ', NEW.status_bayar), 255));
  END IF;

  IF OLD.status_bayar = 'Belum Dibayar' AND NEW.status_bayar = 'Sudah Dibayar' THEN
    INSERT INTO tbl_mutasi_keuangan (tgl_mutasi, jenis, referensi, keterangan, nominal)
    VALUES (IFNULL(NEW.tgl_bayar, CURDATE()), 'Pengeluaran', CONCAT('PAYROLL-', NEW.id),
            CONCAT('Pencairan gaji karyawan id ', NEW.id_karyawan), NEW.total_gaji);
  END IF;
END //

DROP TRIGGER IF EXISTS trg_mutasi_invoice_lunas //
CREATE TRIGGER trg_mutasi_invoice_lunas
AFTER UPDATE ON tbl_invoice
FOR EACH ROW
BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_invoice', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status invoice ', NEW.nomor_invoice, ': ', OLD.status, ' -> ', NEW.status), 255));
  END IF;

  IF OLD.status = 'Belum Lunas' AND NEW.status = 'Lunas' THEN
    INSERT INTO tbl_mutasi_keuangan (tgl_mutasi, jenis, referensi, keterangan, nominal)
    VALUES (IFNULL(NEW.tgl_pembayaran, CURDATE()), 'Pemasukan', NEW.nomor_invoice,
            CONCAT('Pelunasan invoice ', NEW.nomor_invoice), NEW.total_tagihan);
  END IF;
END //

DROP FUNCTION IF EXISTS fn_tarif_ter_a //

DROP FUNCTION IF EXISTS fn_pph21_pasal17 //

DROP FUNCTION IF EXISTS fn_ptkp_tahunan //

DELIMITER ;

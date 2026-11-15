-- ============================================================
-- /sql/komponen/09_perbaikan_database_rollback.sql
-- Definisi SEBELUM perbaikan 09 (diambil dari db_sji yang berjalan, 2026-11-15).
-- Hanya dipakai oleh down() migrasi 2026_11_15_111405_perbaikan_database.
-- JANGAN dijalankan pada instalasi baru — file ini mengembalikan bug yang diperbaiki file 09.
-- chk_rating tidak dihapus karena sudah ada sejak schema_sji.sql.
-- ============================================================
DELIMITER //

DROP PROCEDURE IF EXISTS sp_selesai_penempatan //
DROP TRIGGER IF EXISTS trg_update_rating_ubah //
DROP TRIGGER IF EXISTS trg_update_rating_hapus //

DROP PROCEDURE IF EXISTS sp_generate_payroll_bulanan //
CREATE PROCEDURE sp_generate_payroll_bulanan(IN p_bulan INT, IN p_tahun INT)
BEGIN
  DECLARE v_done INT DEFAULT 0;
  DECLARE v_id INT;
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_gaji DECIMAL(12,2);
  DECLARE v_hari_kerja INT;
  DECLARE v_jumlah_absen INT;
  DECLARE v_jam_lembur INT;
  DECLARE v_lembur DECIMAL(12,2);
  DECLARE v_potongan DECIMAL(12,2);
  DECLARE v_total DECIMAL(12,2);
  DECLARE v_ada INT;
  DECLARE cur CURSOR FOR
    SELECT id, kategori FROM tbl_karyawan
    WHERE (kategori = 'Internal' AND status = 'Aktif')
       OR (kategori = 'Lapangan' AND status = 'Ditempatkan');
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  START TRANSACTION;
  OPEN cur;
  loop_karyawan: LOOP
    FETCH cur INTO v_id, v_kategori;
    IF v_done THEN LEAVE loop_karyawan; END IF;

    SELECT COUNT(*) INTO v_ada FROM tbl_payroll
    WHERE id_karyawan = v_id AND periode_bulan = p_bulan AND periode_tahun = p_tahun;
    IF v_ada = 0 THEN
      IF v_kategori = 'Lapangan' THEN
        SELECT c.gaji_pokok, c.hari_kerja_per_bulan
        INTO v_gaji, v_hari_kerja
        FROM tbl_kontrak c JOIN tbl_penempatan p ON c.id_penempatan = p.id
        WHERE p.id_karyawan = v_id AND p.status = 'Aktif' LIMIT 1;
      ELSE
        SELECT gaji_pokok, 25
        INTO v_gaji, v_hari_kerja
        FROM tbl_karyawan WHERE id = v_id;
      END IF;

      SELECT COUNT(*) INTO v_jumlah_absen FROM tbl_absensi
      WHERE id_karyawan = v_id AND MONTH(tgl) = p_bulan AND YEAR(tgl) = p_tahun
        AND status_kehadiran IN ('Alpha','Izin');
      SELECT COALESCE(SUM(jam_lembur), 0) INTO v_jam_lembur FROM tbl_absensi
      WHERE id_karyawan = v_id AND MONTH(tgl) = p_bulan AND YEAR(tgl) = p_tahun;

      SET v_lembur = fn_hitung_lembur(v_jam_lembur);
      SET v_potongan = fn_hitung_potongan_absen(v_gaji, v_hari_kerja, v_jumlah_absen);
      SET v_total = v_gaji + v_lembur - v_potongan;

      INSERT INTO tbl_payroll
        (id_karyawan, periode_bulan, periode_tahun, gaji_pokok,
         total_lembur, potongan_absen, total_gaji)
      VALUES
        (v_id, p_bulan, p_tahun, v_gaji, v_lembur, v_potongan, v_total);
    END IF;
  END LOOP;
  CLOSE cur;
  COMMIT;
END //

DROP PROCEDURE IF EXISTS sp_assign_penempatan //
CREATE PROCEDURE sp_assign_penempatan(
  IN p_id_karyawan INT, IN p_id_klien INT, IN p_tgl_mulai DATE,
  IN p_tgl_selesai DATE, IN p_lokasi VARCHAR(255), IN p_jam_datang TIME,
  IN p_jam_pulang TIME, IN p_dibuat_oleh INT)
BEGIN
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_status VARCHAR(20);
  DECLARE v_tarif DECIMAL(12,2);
  DECLARE v_pen_id INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  SELECT kategori, status INTO v_kategori, v_status
  FROM tbl_karyawan WHERE id = p_id_karyawan;

  IF v_kategori = 'Internal' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Staf internal tidak dapat ditempatkan ke klien';
  END IF;
  IF v_status <> 'Tersedia' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Karyawan tidak berstatus Tersedia';
  END IF;

  SELECT jl.tarif_dasar INTO v_tarif
  FROM tbl_karyawan k JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
  WHERE k.id = p_id_karyawan;

  START TRANSACTION;
    INSERT INTO tbl_penempatan
      (id_karyawan, id_klien, lokasi_kerja, jam_datang, jam_pulang, tgl_mulai, tgl_selesai, status, dibuat_oleh)
    VALUES
      (p_id_karyawan, p_id_klien, p_lokasi, p_jam_datang, p_jam_pulang, p_tgl_mulai, p_tgl_selesai, 'Aktif', p_dibuat_oleh);
    SET v_pen_id = LAST_INSERT_ID();
    INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok, tunjangan)
    VALUES (v_pen_id, v_tarif, 0);
    UPDATE tbl_karyawan SET status = 'Ditempatkan' WHERE id = p_id_karyawan;
  COMMIT;
END //

DROP PROCEDURE IF EXISTS sp_rotasi_karyawan //
CREATE PROCEDURE sp_rotasi_karyawan(
  IN p_id_penempatan_lama INT, IN p_id_klien_baru INT,
  IN p_tgl_mulai_baru DATE, IN p_lokasi_baru VARCHAR(255),
  IN p_jam_datang_baru TIME, IN p_jam_pulang_baru TIME, IN p_dibuat_oleh INT)
BEGIN
  DECLARE v_karyawan INT;
  DECLARE v_gaji DECIMAL(12,2);
  DECLARE v_tunjangan DECIMAL(12,2);
  DECLARE v_jam_datang TIME;
  DECLARE v_jam_pulang TIME;
  DECLARE v_pen_id INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  SELECT id_karyawan, jam_datang, jam_pulang
  INTO v_karyawan, v_jam_datang, v_jam_pulang
  FROM tbl_penempatan WHERE id = p_id_penempatan_lama;
  SELECT gaji_pokok, tunjangan INTO v_gaji, v_tunjangan
  FROM tbl_kontrak WHERE id_penempatan = p_id_penempatan_lama;

  START TRANSACTION;
    UPDATE tbl_penempatan SET status = 'Rotasi', tgl_selesai = CURDATE()
    WHERE id = p_id_penempatan_lama;
    UPDATE tbl_kontrak SET status = 'Selesai'
    WHERE id_penempatan = p_id_penempatan_lama;
    INSERT INTO tbl_penempatan
      (id_karyawan, id_klien, lokasi_kerja, jam_datang, jam_pulang, tgl_mulai, tgl_selesai, status, dibuat_oleh)
    VALUES
      (v_karyawan, p_id_klien_baru, p_lokasi_baru,
       IFNULL(p_jam_datang_baru, v_jam_datang), IFNULL(p_jam_pulang_baru, v_jam_pulang),
       p_tgl_mulai_baru, NULL, 'Aktif', p_dibuat_oleh);
    SET v_pen_id = LAST_INSERT_ID();
    INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok, tunjangan)
    VALUES (v_pen_id, v_gaji, v_tunjangan);
  COMMIT;
END //

DROP TRIGGER IF EXISTS trg_update_rating //
CREATE TRIGGER trg_update_rating
AFTER INSERT ON tbl_evaluasi_kinerja
FOR EACH ROW
BEGIN
  UPDATE tbl_karyawan
  SET rating = (SELECT ROUND(AVG(rating), 1)
                FROM tbl_evaluasi_kinerja
                WHERE id_karyawan = NEW.id_karyawan)
  WHERE id = NEW.id_karyawan;
END //

DROP FUNCTION IF EXISTS fn_profit_klien //
CREATE FUNCTION fn_profit_klien(p_id_klien INT, p_bulan INT, p_tahun INT)
RETURNS DECIMAL(12,2)
READS SQL DATA
BEGIN
  DECLARE v_masuk DECIMAL(12,2);
  DECLARE v_keluar DECIMAL(12,2);
  DECLARE v_awal DATE;
  DECLARE v_akhir DATE;
  SET v_awal = CONCAT(p_tahun, '-', LPAD(p_bulan, 2, '0'), '-01');
  SET v_akhir = LAST_DAY(v_awal);

  SELECT COALESCE(SUM(total_tagihan), 0) INTO v_masuk FROM tbl_invoice
  WHERE id_klien = p_id_klien AND periode_bulan = p_bulan AND periode_tahun = p_tahun;

  SELECT COALESCE(SUM(py.total_gaji), 0) INTO v_keluar
  FROM tbl_payroll py
  JOIN tbl_penempatan pn ON pn.id_karyawan = py.id_karyawan
  WHERE pn.id_klien = p_id_klien
    AND py.periode_bulan = p_bulan AND py.periode_tahun = p_tahun
    AND pn.tgl_mulai <= v_akhir
    AND (pn.tgl_selesai IS NULL OR pn.tgl_selesai >= v_awal);

  RETURN v_masuk - v_keluar;
END //

DROP TRIGGER IF EXISTS trg_mutasi_invoice_lunas //
CREATE TRIGGER trg_mutasi_invoice_lunas
AFTER UPDATE ON tbl_invoice
FOR EACH ROW
BEGIN
  IF OLD.status = 'Belum Lunas' AND NEW.status = 'Lunas' THEN
    INSERT INTO tbl_mutasi_keuangan (tgl_mutasi, jenis, referensi, keterangan, nominal)
    VALUES (NEW.tgl_pembayaran, 'Pemasukan', NEW.nomor_invoice,
            CONCAT('Pelunasan invoice ', NEW.nomor_invoice), NEW.total_tagihan);
  END IF;
END //

DROP TRIGGER IF EXISTS trg_mutasi_payroll_cair //
CREATE TRIGGER trg_mutasi_payroll_cair
AFTER UPDATE ON tbl_payroll
FOR EACH ROW
BEGIN
  IF OLD.status_bayar = 'Belum Dibayar' AND NEW.status_bayar = 'Sudah Dibayar' THEN
    INSERT INTO tbl_mutasi_keuangan (tgl_mutasi, jenis, referensi, keterangan, nominal)
    VALUES (NEW.tgl_bayar, 'Pengeluaran', CONCAT('PAYROLL-', NEW.id),
            CONCAT('Pencairan gaji karyawan id ', NEW.id_karyawan), NEW.total_gaji);
  END IF;
END //

DELIMITER ;

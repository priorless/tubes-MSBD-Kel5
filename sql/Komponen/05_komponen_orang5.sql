-- ============================================================
-- /sql/komponen/05_komponen_orang5.sql  (Lintas modul: payroll, invoice, kas, CEO)
-- JALANKAN SETELAH FILE 03 & 04!
-- ============================================================
DELIMITER //

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

CREATE PROCEDURE sp_generate_invoice_klien(
  IN p_id_klien INT, IN p_bulan INT, IN p_tahun INT)
BEGIN
  DECLARE v_jumlah INT;
  DECLARE v_total DECIMAL(12,2);
  DECLARE v_awal DATE;
  DECLARE v_akhir DATE;
  DECLARE v_ada INT;

  SET v_awal = CONCAT(p_tahun, '-', LPAD(p_bulan, 2, '0'), '-01');
  SET v_akhir = LAST_DAY(v_awal);

  SELECT COUNT(*) INTO v_ada FROM tbl_invoice
  WHERE id_klien = p_id_klien AND periode_bulan = p_bulan AND periode_tahun = p_tahun;
  IF v_ada > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice periode ini sudah ada';
  END IF;

  SELECT COUNT(*), COALESCE(SUM(jl.tarif_tagihan_klien), 0)
  INTO v_jumlah, v_total
  FROM tbl_penempatan p
  JOIN tbl_karyawan k ON p.id_karyawan = k.id
  JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
  WHERE p.id_klien = p_id_klien AND p.status = 'Aktif'
    AND p.tgl_mulai <= v_akhir
    AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= v_awal);

  IF v_jumlah = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Tidak ada tenaga aktif di klien ini periode ini';
  END IF;

  INSERT INTO tbl_invoice
    (nomor_invoice, id_klien, periode_bulan, periode_tahun, jumlah_tenaga, total_tagihan)
  VALUES
    (CONCAT('INV-', p_tahun, '-', LPAD(p_bulan, 2, '0'), '-', LPAD(p_id_klien, 3, '0')),
     p_id_klien, p_bulan, p_tahun, v_jumlah, v_total);
END //

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

-- Buku kas menulis dirinya sendiri (2 trigger, satu untuk invoice satu untuk payroll)
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

CREATE FUNCTION fn_total_piutang()
RETURNS DECIMAL(12,2)
READS SQL DATA
BEGIN
  RETURN (SELECT COALESCE(SUM(total_tagihan), 0) FROM tbl_invoice WHERE status = 'Belum Lunas');
END //

CREATE VIEW v_arus_kas_bulanan AS
SELECT YEAR(tgl_mutasi) AS tahun, MONTH(tgl_mutasi) AS bulan,
       SUM(CASE WHEN jenis = 'Pemasukan' THEN nominal ELSE 0 END) AS total_masuk,
       SUM(CASE WHEN jenis = 'Pengeluaran' THEN nominal ELSE 0 END) AS total_keluar,
       SUM(CASE WHEN jenis = 'Pemasukan' THEN nominal ELSE -nominal END) AS selisih
FROM tbl_mutasi_keuangan
GROUP BY YEAR(tgl_mutasi), MONTH(tgl_mutasi) //

CREATE VIEW v_dashboard_eksekutif AS
SELECT
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori='Lapangan' AND status='Ditempatkan') AS lapangan_bertugas,
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori='Lapangan' AND status='Tersedia') AS lapangan_siap,
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori='Internal' AND status='Aktif') AS internal_aktif,
  fn_total_piutang() AS total_piutang,
  (SELECT COALESCE(SUM(CASE WHEN jenis='Pemasukan' THEN nominal ELSE -nominal END), 0)
   FROM tbl_mutasi_keuangan
   WHERE MONTH(tgl_mutasi) = MONTH(CURDATE()) AND YEAR(tgl_mutasi) = YEAR(CURDATE()))
   AS profit_bulan_berjalan //

DELIMITER ;
-- Cara tes akhir (alur penuh): isi dummy data -> CALL sp_generate_payroll_bulanan(10,2026);
-- CALL sp_generate_invoice_klien(1,10,2026); -> CALL sp_update_status_bayar('PAYROLL',1,'2026-10-25');
-- lalu SELECT * FROM v_arus_kas_bulanan; -> buku kas harus sudah terisi sendiri.
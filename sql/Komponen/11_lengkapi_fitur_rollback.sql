-- ============================================================
-- /sql/komponen/11_lengkapi_fitur_rollback.sql
-- Definisi SEBELUM tiap tahap file 11 (diambil dari db_sji yang berjalan, 2026-11-16).
-- Hanya dipakai oleh down() migrasi 2026_11_16_*; struktur tabel dikembalikan oleh migrasi.
-- JANGAN dijalankan pada instalasi baru.
-- ============================================================

-- [TAHAP 1]
DELIMITER //

CREATE OR REPLACE VIEW v_pool_karyawan_hrd AS
SELECT k.id, k.nip, k.nama, jl.nama_layanan, k.status, k.rating, k.tgl_masuk
FROM tbl_karyawan k
JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
WHERE k.kategori = 'Lapangan' AND k.status = 'Tersedia' //

DROP TRIGGER IF EXISTS trg_update_rating //
CREATE TRIGGER trg_update_rating
AFTER INSERT ON tbl_evaluasi_kinerja
FOR EACH ROW
BEGIN
  UPDATE tbl_karyawan
  SET rating = IFNULL((SELECT ROUND(AVG(rating), 1)
                       FROM tbl_evaluasi_kinerja
                       WHERE id_karyawan = NEW.id_karyawan), 0.0)
  WHERE id = NEW.id_karyawan;
END //

DROP TRIGGER IF EXISTS trg_update_rating_ubah //
CREATE TRIGGER trg_update_rating_ubah
AFTER UPDATE ON tbl_evaluasi_kinerja
FOR EACH ROW
BEGIN
  UPDATE tbl_karyawan
  SET rating = IFNULL((SELECT ROUND(AVG(rating), 1)
                       FROM tbl_evaluasi_kinerja
                       WHERE id_karyawan = NEW.id_karyawan), 0.0)
  WHERE id = NEW.id_karyawan;
  IF OLD.id_karyawan <> NEW.id_karyawan THEN
    UPDATE tbl_karyawan
    SET rating = IFNULL((SELECT ROUND(AVG(rating), 1)
                         FROM tbl_evaluasi_kinerja
                         WHERE id_karyawan = OLD.id_karyawan), 0.0)
    WHERE id = OLD.id_karyawan;
  END IF;
END //

DROP TRIGGER IF EXISTS trg_update_rating_hapus //
CREATE TRIGGER trg_update_rating_hapus
AFTER DELETE ON tbl_evaluasi_kinerja
FOR EACH ROW
BEGIN
  UPDATE tbl_karyawan
  SET rating = IFNULL((SELECT ROUND(AVG(rating), 1)
                       FROM tbl_evaluasi_kinerja
                       WHERE id_karyawan = OLD.id_karyawan), 0.0)
  WHERE id = OLD.id_karyawan;
END //

DELIMITER ;

-- [TAHAP 2]
DELIMITER //

-- Versi file 09 (kontrak masih menyimpan tunjangan).
DROP PROCEDURE IF EXISTS sp_assign_penempatan //
CREATE PROCEDURE sp_assign_penempatan(
  IN p_id_karyawan INT, IN p_id_klien INT, IN p_tgl_mulai DATE,
  IN p_tgl_selesai DATE, IN p_lokasi VARCHAR(255), IN p_jam_datang TIME,
  IN p_jam_pulang TIME, IN p_dibuat_oleh INT)
BEGIN
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_status VARCHAR(20);
  DECLARE v_status_klien VARCHAR(10);
  DECLARE v_tarif DECIMAL(12,2);
  DECLARE v_pen_id INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  SET v_kategori = (SELECT kategori FROM tbl_karyawan WHERE id = p_id_karyawan);
  SET v_status = (SELECT status FROM tbl_karyawan WHERE id = p_id_karyawan);
  SET v_status_klien = (SELECT status FROM tbl_klien WHERE id = p_id_klien);

  IF v_kategori IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Karyawan tidak ditemukan';
  END IF;
  IF v_kategori = 'Internal' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Staf internal tidak dapat ditempatkan ke klien';
  END IF;
  IF v_status <> 'Tersedia' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Karyawan tidak berstatus Tersedia';
  END IF;
  IF v_status_klien IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Klien tidak ditemukan';
  END IF;
  IF v_status_klien <> 'Aktif' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Klien berstatus Nonaktif tidak dapat menerima penempatan';
  END IF;
  IF p_tgl_selesai IS NOT NULL AND p_tgl_selesai < p_tgl_mulai THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal selesai tidak boleh sebelum tanggal mulai';
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
  DECLARE v_status_lama VARCHAR(10);
  DECLARE v_status_klien VARCHAR(10);
  DECLARE v_karyawan INT;
  DECLARE v_gaji DECIMAL(12,2);
  DECLARE v_tunjangan DECIMAL(12,2);
  DECLARE v_jam_datang TIME;
  DECLARE v_jam_pulang TIME;
  DECLARE v_pen_id INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  SET v_status_lama = (SELECT status FROM tbl_penempatan WHERE id = p_id_penempatan_lama);
  SET v_status_klien = (SELECT status FROM tbl_klien WHERE id = p_id_klien_baru);

  IF v_status_lama IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan lama tidak ditemukan';
  END IF;
  IF v_status_lama <> 'Aktif' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hanya penempatan berstatus Aktif yang dapat dirotasi';
  END IF;
  IF v_status_klien IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Klien tujuan tidak ditemukan';
  END IF;
  IF v_status_klien <> 'Aktif' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Klien tujuan berstatus Nonaktif';
  END IF;

  SELECT id_karyawan, jam_datang, jam_pulang
  INTO v_karyawan, v_jam_datang, v_jam_pulang
  FROM tbl_penempatan WHERE id = p_id_penempatan_lama;

  SET v_gaji = (SELECT gaji_pokok FROM tbl_kontrak WHERE id_penempatan = p_id_penempatan_lama ORDER BY id DESC LIMIT 1);
  SET v_tunjangan = (SELECT tunjangan FROM tbl_kontrak WHERE id_penempatan = p_id_penempatan_lama ORDER BY id DESC LIMIT 1);
  IF v_gaji IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kontrak penempatan lama tidak ditemukan';
  END IF;

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
    VALUES (v_pen_id, v_gaji, IFNULL(v_tunjangan, 0));
  COMMIT;
END //

DELIMITER ;

-- [TAHAP 3]
DELIMITER //

DROP PROCEDURE IF EXISTS sp_input_ketidakhadiran //

DELIMITER ;

-- [TAHAP 4]
DELIMITER //

DROP TRIGGER IF EXISTS trg_karyawan_validasi_status //
DROP TRIGGER IF EXISTS trg_karyawan_sinkron_akun //

DELIMITER ;

-- [TAHAP 5]
DELIMITER //

-- Payroll & profit versi file 09 (tanpa prorata, filter MONTH()/YEAR()).
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
  -- Hanya FETCH yang boleh memicu handler ini; pencarian gaji memakai handler sendiri di bawah.
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  START TRANSACTION;
  OPEN cur;
  loop_karyawan: LOOP
    FETCH cur INTO v_id, v_kategori;
    IF v_done THEN LEAVE loop_karyawan; END IF;

    SET v_gaji = NULL;
    SET v_hari_kerja = NULL;

    SELECT COUNT(*) INTO v_ada FROM tbl_payroll
    WHERE id_karyawan = v_id AND periode_bulan = p_bulan AND periode_tahun = p_tahun;

    IF v_ada = 0 THEN
      BEGIN
        -- Tidak ada kontrak/gaji: biarkan v_gaji NULL tanpa menyentuh v_done.
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_gaji = NULL;

        IF v_kategori = 'Lapangan' THEN
          SELECT c.gaji_pokok, c.hari_kerja_per_bulan
          INTO v_gaji, v_hari_kerja
          FROM tbl_kontrak c JOIN tbl_penempatan p ON c.id_penempatan = p.id
          WHERE p.id_karyawan = v_id AND p.status = 'Aktif'
          ORDER BY p.tgl_mulai DESC, p.id DESC
          LIMIT 1;
        ELSE
          SELECT gaji_pokok, 25
          INTO v_gaji, v_hari_kerja
          FROM tbl_karyawan WHERE id = v_id;
        END IF;
      END;

      -- Karyawan tanpa gaji (mis. Ditempatkan tetapi belum punya kontrak) dilewati.
      IF v_gaji IS NOT NULL AND v_hari_kerja IS NOT NULL THEN
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
    END IF;
  END LOOP;
  CLOSE cur;
  COMMIT;
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
  WHERE py.periode_bulan = p_bulan AND py.periode_tahun = p_tahun
    AND EXISTS (
      SELECT 1 FROM tbl_penempatan pn
      WHERE pn.id_karyawan = py.id_karyawan
        AND pn.id_klien = p_id_klien
        AND pn.tgl_mulai <= v_akhir
        AND (pn.tgl_selesai IS NULL OR pn.tgl_selesai >= v_awal)
    );

  RETURN v_masuk - v_keluar;
END //

-- Invoice sebelum Tahap 5 (hanya penempatan Aktif, tarif penuh per penempatan).
DROP PROCEDURE IF EXISTS sp_generate_invoice_klien //
CREATE PROCEDURE sp_generate_invoice_klien(IN p_id_klien INT, IN p_bulan INT, IN p_tahun INT)
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

CREATE OR REPLACE VIEW v_slip_gaji_detail AS
SELECT p.id, k.nip, k.nama, k.kategori, p.periode_bulan, p.periode_tahun,
       p.gaji_pokok, p.total_lembur, p.potongan_absen, p.total_gaji,
       p.status_bayar, p.tgl_bayar
FROM tbl_payroll p
JOIN tbl_karyawan k ON p.id_karyawan = k.id //

CREATE OR REPLACE VIEW v_dashboard_eksekutif AS
SELECT
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Lapangan' AND status = 'Ditempatkan') AS lapangan_bertugas,
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Lapangan' AND status = 'Tersedia') AS lapangan_siap,
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Internal' AND status = 'Aktif') AS internal_aktif,
  fn_total_piutang() AS total_piutang,
  (SELECT COALESCE(SUM(CASE WHEN jenis = 'Pemasukan' THEN nominal ELSE -nominal END), 0)
   FROM tbl_mutasi_keuangan
   WHERE MONTH(tgl_mutasi) = MONTH(CURDATE()) AND YEAR(tgl_mutasi) = YEAR(CURDATE()))
   AS profit_bulan_berjalan //

DROP FUNCTION IF EXISTS fn_total_hari_klien //
DROP FUNCTION IF EXISTS fn_hari_penempatan //

DELIMITER ;

-- [TAHAP 6]
DELIMITER //

DROP PROCEDURE IF EXISTS sp_putuskan_lembur //

-- Trigger lembur sebelum Tahap 6 (tanpa status_lembur).
DROP TRIGGER IF EXISTS trg_auto_hitung_lembur //
CREATE TRIGGER trg_auto_hitung_lembur
BEFORE UPDATE ON tbl_absensi
FOR EACH ROW
BEGIN
  DECLARE v_durasi INT;
  DECLARE v_pulang TIME;
  DECLARE v_sampai_pulang INT;
  DECLARE v_pesan VARCHAR(255);

  IF NEW.jam_keluar IS NOT NULL AND OLD.jam_keluar IS NULL AND NEW.jam_masuk IS NOT NULL THEN
    SET v_durasi = FLOOR(MOD(TIME_TO_SEC(NEW.jam_keluar) - TIME_TO_SEC(NEW.jam_masuk) + 86400, 86400) / 60);

    IF NEW.foto_keluar IS NOT NULL AND v_durasi < 30 THEN
      SET v_pesan = CONCAT('Check-out ditolak: minimal 30 menit setelah check-in (baru ', v_durasi, ' menit).');
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_pesan;
    END IF;

    SET v_pulang = fn_jadwal_absensi(NEW.id_penempatan, 'pulang');
    IF v_pulang IS NULL THEN
      SET NEW.jam_lembur = 0;
    ELSE
      SET v_sampai_pulang = FLOOR(MOD(TIME_TO_SEC(v_pulang) - TIME_TO_SEC(NEW.jam_masuk) + 86400, 86400) / 60);
      SET NEW.jam_lembur = GREATEST(0, FLOOR((v_durasi - v_sampai_pulang) / 60));
    END IF;
  END IF;
END //

-- Payroll versi Tahap 5 (semua lembur dihitung).
DROP PROCEDURE IF EXISTS sp_generate_payroll_bulanan //
CREATE PROCEDURE sp_generate_payroll_bulanan(IN p_bulan INT, IN p_tahun INT)
BEGIN
  DECLARE v_done INT DEFAULT 0;
  DECLARE v_id INT;
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_awal DATE;
  DECLARE v_akhir DATE;
  DECLARE v_hari_bulan INT;
  DECLARE v_gaji_kontrak DECIMAL(12,2);
  DECLARE v_hari_kerja INT;
  DECLARE v_hari_penempatan INT;
  DECLARE v_tarif DECIMAL(16,6);
  DECLARE v_hari_wajib INT;
  DECLARE v_hari_masuk INT;
  DECLARE v_hari_absen INT;
  DECLARE v_gaji DECIMAL(12,2);
  DECLARE v_potongan DECIMAL(12,2);
  DECLARE v_jam_lembur INT;
  DECLARE v_lembur DECIMAL(12,2);
  DECLARE v_ada INT;
  DECLARE cur CURSOR FOR
    SELECT k.id, k.kategori FROM tbl_karyawan k
    WHERE (k.kategori = 'Internal' AND k.status IN ('Aktif', 'Cuti'))
       OR (k.kategori = 'Lapangan' AND EXISTS (
             SELECT 1 FROM tbl_penempatan p
             WHERE p.id_karyawan = k.id
               AND p.tgl_mulai <= v_akhir
               AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= v_awal)))
    ORDER BY k.id;
  -- Hanya FETCH yang memicu handler ini; pencarian kontrak memakai handler sendiri di bawah.
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  IF p_bulan IS NULL OR p_bulan NOT BETWEEN 1 AND 12 OR p_tahun IS NULL OR p_tahun NOT BETWEEN 2000 AND 2100 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Periode payroll tidak valid';
  END IF;

  SET v_awal = CONCAT(p_tahun, '-', LPAD(p_bulan, 2, '0'), '-01');
  SET v_akhir = LAST_DAY(v_awal);
  SET v_hari_bulan = DAY(v_akhir);

  START TRANSACTION;
  OPEN cur;
  loop_karyawan: LOOP
    FETCH cur INTO v_id, v_kategori;
    IF v_done THEN LEAVE loop_karyawan; END IF;

    SET v_gaji = NULL;
    SET v_potongan = 0;
    SET v_hari_wajib = NULL;
    SET v_hari_masuk = NULL;
    SET v_hari_absen = NULL;

    SELECT COUNT(*) INTO v_ada FROM tbl_payroll
    WHERE id_karyawan = v_id AND periode_bulan = p_bulan AND periode_tahun = p_tahun;

    IF v_ada = 0 THEN
      IF v_kategori = 'Lapangan' THEN
        SET v_gaji_kontrak = NULL;
        SET v_hari_kerja = NULL;
        BEGIN
          DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_gaji_kontrak = NULL;
          SELECT c.gaji_pokok, c.hari_kerja_per_bulan
          INTO v_gaji_kontrak, v_hari_kerja
          FROM tbl_kontrak c JOIN tbl_penempatan p ON c.id_penempatan = p.id
          WHERE p.id_karyawan = v_id
            AND p.tgl_mulai <= v_akhir
            AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= v_awal)
          ORDER BY p.tgl_mulai DESC, p.id DESC, c.id DESC
          LIMIT 1;
        END;

        -- Penempatan tanpa kontrak: dilewati (tidak menghentikan karyawan lain).
        IF v_gaji_kontrak IS NOT NULL AND v_hari_kerja > 0 THEN
          SET v_hari_penempatan = fn_hari_penempatan(v_id, NULL, v_awal, v_akhir);
          SET v_tarif = v_gaji_kontrak / v_hari_kerja;
          SET v_hari_wajib = ROUND(v_hari_kerja * v_hari_penempatan / v_hari_bulan);
          SET v_hari_masuk = (SELECT COUNT(*) FROM tbl_absensi
                              WHERE id_karyawan = v_id AND tgl BETWEEN v_awal AND v_akhir
                                AND status_kehadiran IN ('Hadir', 'Terlambat', 'Sakit'));
          SET v_hari_absen = GREATEST(0, v_hari_wajib - v_hari_masuk);
          SET v_gaji = ROUND(v_tarif * v_hari_wajib, 2);
          SET v_potongan = ROUND(v_tarif * v_hari_absen, 2);
        END IF;
      ELSE
        SET v_gaji = (SELECT gaji_pokok FROM tbl_karyawan WHERE id = v_id);
        IF v_gaji IS NOT NULL THEN
          SET v_hari_wajib = 25;
          SET v_hari_absen = (SELECT COUNT(*) FROM tbl_absensi
                              WHERE id_karyawan = v_id AND tgl BETWEEN v_awal AND v_akhir
                                AND status_kehadiran IN ('Izin', 'Alpha'));
          SET v_potongan = fn_hitung_potongan_absen(v_gaji, 25, v_hari_absen);
        END IF;
      END IF;

      IF v_gaji IS NOT NULL THEN
        SET v_jam_lembur = (SELECT COALESCE(SUM(jam_lembur), 0) FROM tbl_absensi
                            WHERE id_karyawan = v_id AND tgl BETWEEN v_awal AND v_akhir);
        SET v_lembur = fn_hitung_lembur(v_jam_lembur);

        INSERT INTO tbl_payroll
          (id_karyawan, periode_bulan, periode_tahun, gaji_pokok, total_lembur, potongan_absen,
           hari_wajib, hari_masuk, hari_absen, total_gaji)
        VALUES
          (v_id, p_bulan, p_tahun, v_gaji, v_lembur, v_potongan,
           v_hari_wajib, v_hari_masuk, v_hari_absen, v_gaji + v_lembur - v_potongan);
      END IF;
    END IF;
  END LOOP;
  CLOSE cur;
  COMMIT;
END //

-- v_absensi_harian tanpa status_lembur.
CREATE OR REPLACE VIEW v_absensi_harian AS
SELECT a.id AS id_absensi, a.id_karyawan, k.nip, k.nama, jl.nama_layanan, a.tgl,
       MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun, a.jam_masuk, a.jam_keluar,
       a.status_kehadiran, a.menit_terlambat, fn_hitung_jam_kerja(a.jam_masuk, a.jam_keluar) AS jam_kerja,
       a.jam_lembur, a.id_penempatan, kl.nama_perusahaan AS nama_klien, p.lokasi_kerja,
       p.jam_datang AS jadwal_datang, p.jam_pulang AS jadwal_pulang, a.foto_masuk, a.foto_keluar,
       (a.jam_masuk IS NOT NULL AND a.jam_keluar IS NULL
        AND TIMESTAMP(a.tgl, a.jam_masuk) < (NOW() - INTERVAL 16 HOUR)) AS lupa_check_out,
       a.keterangan
FROM tbl_absensi a
JOIN tbl_karyawan k ON k.id = a.id_karyawan
LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
LEFT JOIN tbl_penempatan p ON p.id = a.id_penempatan
LEFT JOIN tbl_klien kl ON kl.id = p.id_klien //

DELIMITER ;

-- [TAHAP 7]
DELIMITER //

-- Status sertifikat yang sudah diubah event tidak dikembalikan (data).
DROP EVENT IF EXISTS ev_update_status_sertifikat //

DELIMITER ;

-- [TAHAP 8]
DELIMITER //

DROP TRIGGER IF EXISTS trg_karyawan_log_hapus //
DROP TRIGGER IF EXISTS trg_penempatan_log_tambah //
DROP TRIGGER IF EXISTS trg_penempatan_log_ubah //
DROP TRIGGER IF EXISTS trg_users_log_tambah //
DROP TRIGGER IF EXISTS trg_users_log_ubah //
DROP TRIGGER IF EXISTS trg_users_log_hapus //
DROP TRIGGER IF EXISTS trg_klien_log_tambah //
DROP TRIGGER IF EXISTS trg_klien_log_ubah //

-- Versi sebelum Tahap 8 (tanpa log).
DROP TRIGGER IF EXISTS trg_auto_akun_karyawan //
CREATE TRIGGER trg_auto_akun_karyawan
AFTER INSERT ON tbl_karyawan
FOR EACH ROW
BEGIN
  DECLARE v_role INT;
  IF NEW.kategori = 'Lapangan' THEN
    SELECT id INTO v_role FROM tbl_roles WHERE nama_role = 'Karyawan Lapangan';
    INSERT INTO tbl_users (id_karyawan, username, password, id_role)
    VALUES (NEW.id, NEW.nip, SHA2(NEW.nip, 256), v_role);
  END IF;
END //

DROP TRIGGER IF EXISTS trg_karyawan_sinkron_akun //
CREATE TRIGGER trg_karyawan_sinkron_akun
AFTER UPDATE ON tbl_karyawan
FOR EACH ROW
BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    IF NEW.status = 'Nonaktif' THEN
      UPDATE tbl_users SET status = 'Nonaktif' WHERE id_karyawan = NEW.id;
    ELSEIF OLD.status = 'Nonaktif' AND NEW.status IN ('Tersedia', 'Aktif') THEN
      UPDATE tbl_users SET status = 'Aktif' WHERE id_karyawan = NEW.id;
    END IF;
  END IF;
END //

DROP TRIGGER IF EXISTS trg_mutasi_payroll_cair //
CREATE TRIGGER trg_mutasi_payroll_cair
AFTER UPDATE ON tbl_payroll
FOR EACH ROW
BEGIN
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
  IF OLD.status = 'Belum Lunas' AND NEW.status = 'Lunas' THEN
    INSERT INTO tbl_mutasi_keuangan (tgl_mutasi, jenis, referensi, keterangan, nominal)
    VALUES (IFNULL(NEW.tgl_pembayaran, CURDATE()), 'Pemasukan', NEW.nomor_invoice,
            CONCAT('Pelunasan invoice ', NEW.nomor_invoice), NEW.total_tagihan);
  END IF;
END //

DELIMITER ;

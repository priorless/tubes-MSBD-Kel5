-- ============================================================
-- /sql/komponen/09_perbaikan_database.sql
-- Perbaikan bug lintas modul (payroll, penempatan, rating, profit klien, buku kas).
--
-- JALANKAN SETELAH FILE 01-08. Di aplikasi cukup: php artisan migrate
-- (migrasi 2026_11_15_111405_perbaikan_database menjalankan BAGIAN B lalu BAGIAN A secara aman).
-- Isi BAGIAN B adalah definisi LENGKAP dan TERBARU tiap routine, sehingga instalasi dari nol
-- (file 01-09 berurutan) menghasilkan routine yang sama dengan database yang memakai migrasi.
-- Tidak ada data yang diubah atau dihapus.
--
-- Ringkasan perbaikan:
--   a. sp_generate_payroll_bulanan : SELECT ... INTO tanpa hasil tidak lagi menghentikan cursor
--                                    (v_done hanya diubah FETCH); karyawan tanpa gaji dilewati.
--   b. sp_selesai_penempatan (BARU): mengakhiri penempatan Aktif dalam satu transaksi.
--   c. sp_assign_penempatan        : tolak karyawan/klien tidak ada, klien Nonaktif, tgl_selesai < tgl_mulai.
--   d. sp_rotasi_karyawan          : tolak penempatan lama tidak ada/bukan Aktif, klien baru tidak ada/Nonaktif.
--   e. trg_update_rating(_ubah/_hapus): rating dihitung ulang juga saat evaluasi diubah/dihapus.
--   f. fn_profit_klien             : EXISTS menggantikan JOIN agar gaji tidak terhitung ganda.
--   g. trg_mutasi_*                : tanggal mutasi IFNULL(..., CURDATE()).
--   h. chk_rating                  : dipastikan ada (BAGIAN A).
--
-- BELUM DIUBAH (menunggu keputusan): hari kerja tanpa baris absensi sama sekali belum dihitung Alpha.
-- ============================================================

-- ------------------------------------------------------------
-- BAGIAN A — Struktur tabel (hanya bila belum ada)
-- chk_rating sudah ada di schema_sji.sql, tetapi hilang bila database di-import dari export phpMyAdmin.
-- Migrasi memeriksa information_schema.CHECK_CONSTRAINTS dulu sebelum menambahkannya.
-- ------------------------------------------------------------
-- ALTER TABLE tbl_evaluasi_kinerja ADD CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5);

-- ------------------------------------------------------------
-- BAGIAN B — Procedure, function, dan trigger (aman dijalankan ulang)
-- ------------------------------------------------------------
DELIMITER //

-- (a) Payroll bulanan -------------------------------------------------------
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

-- (b) Mengakhiri penempatan -------------------------------------------------
DROP PROCEDURE IF EXISTS sp_selesai_penempatan //
CREATE PROCEDURE sp_selesai_penempatan(IN p_id_penempatan INT, IN p_tgl_selesai DATE)
BEGIN
  DECLARE v_status VARCHAR(10);
  DECLARE v_karyawan INT;
  DECLARE v_tgl_mulai DATE;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  IF p_tgl_selesai IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal selesai penempatan wajib diisi';
  END IF;

  START TRANSACTION;
    BEGIN
      DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_status = NULL;
      SELECT status, id_karyawan, tgl_mulai INTO v_status, v_karyawan, v_tgl_mulai
      FROM tbl_penempatan WHERE id = p_id_penempatan
      FOR UPDATE;
    END;

    IF v_status IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan tidak ditemukan';
    END IF;
    IF v_status <> 'Aktif' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan sudah tidak berstatus Aktif';
    END IF;
    IF p_tgl_selesai < v_tgl_mulai THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal selesai tidak boleh sebelum tanggal mulai penempatan';
    END IF;

    UPDATE tbl_penempatan SET status = 'Selesai', tgl_selesai = p_tgl_selesai
    WHERE id = p_id_penempatan;
    UPDATE tbl_kontrak SET status = 'Selesai'
    WHERE id_penempatan = p_id_penempatan AND status = 'Aktif';
    -- Kembali Tersedia hanya bila tidak ada penempatan Aktif lain untuk karyawan ini.
    UPDATE tbl_karyawan SET status = 'Tersedia'
    WHERE id = v_karyawan
      AND NOT EXISTS (SELECT 1 FROM tbl_penempatan
                      WHERE id_karyawan = v_karyawan AND status = 'Aktif');
  COMMIT;
END //

-- (c) Penempatan baru (8 parameter) ----------------------------------------
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

-- (d) Rotasi (7 parameter) ---------------------------------------------------
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

-- (e) Rating karyawan = rata-rata evaluasi (0.0 bila tidak ada evaluasi) ----
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
  -- Evaluasi dipindah ke karyawan lain: hitung ulang juga karyawan lama.
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

-- (f) Profit klien: gaji tiap karyawan dihitung sekali walau punya >1 penempatan di klien itu
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

-- (g) Buku kas: tanggal mutasi tidak boleh NULL ----------------------------
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

DELIMITER ;

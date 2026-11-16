-- ============================================================
-- /sql/komponen/11_lengkapi_fitur.sql
-- Melengkapi fitur & membersihkan skema db_sji (dikerjakan bertahap, satu migrasi per tahap).
--
-- INSTALASI DARI NOL: jalankan schema_sji.sql, lalu file 01-09, lalu FILE INI (BAGIAN A lalu
-- BAGIAN B dari atas ke bawah), lalu file 10 (hak akses) PALING TERAKHIR, karena file 10 memberi
-- GRANT pada objek yang baru dibuat file ini.
-- Di aplikasi cukup: php artisan migrate (migrasi 2026_11_16_* menjalankan struktur + blok
-- [TAHAP n] masing-masing, lalu memasang ulang hak akses file 10).
--
-- Tahap:
--   1. Hapus fitur rating (tbl_evaluasi_kinerja, tbl_karyawan.rating, trigger rating).
--   2. Bersihkan kolom/tabel tak terpakai (foto_checkin, tunjangan, tabel users bawaan Laravel).
--   3. sp_input_ketidakhadiran: HRD mencatat Izin/Sakit/Alpha.
--   4. Aturan status karyawan + sinkron status akun login (trigger tbl_karyawan).
--   5. Payroll & invoice prorata berdasarkan hari penempatan; filter periode memakai rentang tanggal.
--   6. Persetujuan lembur (status_lembur, sp_putuskan_lembur); payroll hanya lembur Disetujui.
--   7. EVENT ev_update_status_sertifikat (harian 00:05) menandai sertifikat Kadaluarsa.
--   8. Log aktivitas (tbl_log_aktivitas + trigger log; id_user dari @app_user_id).
-- ============================================================

-- ------------------------------------------------------------
-- BAGIAN A — Struktur tabel (instalasi baru: dijalankan SEKALI, sebelum BAGIAN B)
-- Migrasi tidak memakai bagian ini; migrasi memeriksa information_schema lalu
-- melakukan perubahan yang sama lewat Schema Builder.
-- ------------------------------------------------------------

-- [A1] Fitur rating dihapus. Trigger rating ikut terhapus bersama tabelnya.
DROP TABLE IF EXISTS tbl_evaluasi_kinerja;
ALTER TABLE tbl_karyawan DROP COLUMN rating;

-- [A2] Kolom/tabel tak terpakai. Absensi memakai foto_masuk/foto_keluar; gaji tanpa tunjangan
--      (sejak migrasi 2026_11_15_071700); login memakai tbl_users, bukan tabel users bawaan Laravel.
ALTER TABLE tbl_absensi DROP COLUMN foto_checkin;
ALTER TABLE tbl_karyawan DROP COLUMN tunjangan;
ALTER TABLE tbl_kontrak DROP COLUMN tunjangan;
ALTER TABLE tbl_payroll DROP COLUMN tunjangan;
DROP TABLE IF EXISTS users;

-- [A5] Rincian hari pada payroll (NULL untuk payroll lama yang dibuat sebelum Tahap 5).
ALTER TABLE tbl_payroll
  ADD COLUMN hari_wajib SMALLINT UNSIGNED NULL AFTER potongan_absen,
  ADD COLUMN hari_masuk SMALLINT UNSIGNED NULL AFTER hari_wajib,
  ADD COLUMN hari_absen SMALLINT UNSIGNED NULL AFTER hari_masuk;

-- [A6] Status persetujuan lembur; data lama dengan jam_lembur > 0 menunggu keputusan HRD.
ALTER TABLE tbl_absensi
  ADD COLUMN status_lembur ENUM('Tidak Ada','Menunggu','Disetujui','Ditolak') NOT NULL DEFAULT 'Tidak Ada' AFTER jam_lembur;
UPDATE tbl_absensi SET status_lembur = 'Menunggu' WHERE jam_lembur > 0;

-- [A8] Log aktivitas (audit). Tidak ada role yang diberi UPDATE/DELETE pada tabel ini (file 10).
CREATE TABLE IF NOT EXISTS tbl_log_aktivitas (
  id INT NOT NULL AUTO_INCREMENT,
  waktu TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  id_user INT NULL,
  nama_tabel VARCHAR(50) NOT NULL,
  aksi ENUM('INSERT','UPDATE','DELETE') NOT NULL,
  id_data INT NULL,
  keterangan VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_log_tabel_waktu (nama_tabel, waktu),
  KEY idx_log_user (id_user)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- BAGIAN B — View, routine, dan trigger per tahap (aman dijalankan ulang)
-- ------------------------------------------------------------

-- [TAHAP 1]
DELIMITER //

DROP TRIGGER IF EXISTS trg_update_rating //
DROP TRIGGER IF EXISTS trg_update_rating_ubah //
DROP TRIGGER IF EXISTS trg_update_rating_hapus //

-- Pool karyawan Lapangan Tersedia, tanpa kolom rating.
CREATE OR REPLACE VIEW v_pool_karyawan_hrd AS
SELECT k.id, k.nip, k.nama, jl.nama_layanan, k.status, k.tgl_masuk
FROM tbl_karyawan k
JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
WHERE k.kategori = 'Lapangan' AND k.status = 'Tersedia' //

DELIMITER ;

-- [TAHAP 2]
DELIMITER //

-- Penempatan baru (8 parameter). Kontrak tidak lagi menyimpan tunjangan.
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
    INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok)
    VALUES (v_pen_id, v_tarif);
    UPDATE tbl_karyawan SET status = 'Ditempatkan' WHERE id = p_id_karyawan;
  COMMIT;
END //

-- Rotasi (7 parameter). Kontrak baru menyalin gaji pokok kontrak lama (tanpa tunjangan).
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
    INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok)
    VALUES (v_pen_id, v_gaji);
  COMMIT;
END //

DELIMITER ;

-- [TAHAP 3]
DELIMITER //

-- HRD mencatat ketidakhadiran (Izin/Sakit/Alpha) tanpa check-in.
-- id_penempatan = penempatan (status apa pun) yang mencakup tanggal tersebut; NULL untuk Internal.
DROP PROCEDURE IF EXISTS sp_input_ketidakhadiran //
CREATE PROCEDURE sp_input_ketidakhadiran(
  IN p_id_karyawan INT, IN p_tgl DATE, IN p_status VARCHAR(10), IN p_keterangan VARCHAR(255))
BEGIN
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_status_karyawan VARCHAR(20);
  DECLARE v_pen_id INT;

  IF p_status IS NULL OR p_status NOT IN ('Izin', 'Sakit', 'Alpha') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status ketidakhadiran hanya boleh Izin, Sakit, atau Alpha';
  END IF;
  IF p_tgl IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal ketidakhadiran wajib diisi';
  END IF;
  IF p_tgl > CURDATE() THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal ketidakhadiran tidak boleh di masa depan';
  END IF;

  SET v_kategori = (SELECT kategori FROM tbl_karyawan WHERE id = p_id_karyawan);
  SET v_status_karyawan = (SELECT status FROM tbl_karyawan WHERE id = p_id_karyawan);

  IF v_kategori IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Karyawan tidak ditemukan';
  END IF;
  IF v_status_karyawan = 'Nonaktif' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Karyawan berstatus Nonaktif tidak dapat dicatat ketidakhadirannya';
  END IF;
  IF EXISTS (SELECT 1 FROM tbl_absensi WHERE id_karyawan = p_id_karyawan AND tgl = p_tgl) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Karyawan sudah memiliki catatan absensi pada tanggal tersebut';
  END IF;

  SET v_pen_id = (
    SELECT id FROM tbl_penempatan
    WHERE id_karyawan = p_id_karyawan
      AND tgl_mulai <= p_tgl AND (tgl_selesai IS NULL OR tgl_selesai >= p_tgl)
    ORDER BY tgl_mulai DESC, id DESC
    LIMIT 1);

  IF v_kategori = 'Lapangan' AND v_pen_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Karyawan Lapangan tidak memiliki penempatan pada tanggal tersebut';
  END IF;

  INSERT INTO tbl_absensi (id_karyawan, id_penempatan, tgl, status_kehadiran, keterangan)
  VALUES (p_id_karyawan, v_pen_id, p_tgl, p_status, NULLIF(TRIM(p_keterangan), ''));
END //

DELIMITER ;

-- [TAHAP 4]
DELIMITER //

-- Aturan perubahan status karyawan (berlaku untuk aplikasi maupun query langsung).
--   Internal : Aktif / Cuti / Nonaktif.
--   Lapangan : Tersedia / Cuti / Nonaktif; Ditempatkan hanya bila ada penempatan Aktif
--              (diatur sp_assign_penempatan). Selama ada penempatan Aktif, status tidak boleh diubah
--              (sp_selesai_penempatan menutup penempatan dulu, baru mengembalikan status Tersedia).
DROP TRIGGER IF EXISTS trg_karyawan_validasi_status //
CREATE TRIGGER trg_karyawan_validasi_status
BEFORE UPDATE ON tbl_karyawan
FOR EACH ROW
BEGIN
  DECLARE v_penempatan_aktif INT;

  IF NOT (NEW.status <=> OLD.status) THEN
    IF NEW.kategori = 'Internal' THEN
      IF NEW.status NOT IN ('Aktif', 'Cuti', 'Nonaktif') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status karyawan Internal hanya boleh Aktif, Cuti, atau Nonaktif';
      END IF;
    ELSE
      SET v_penempatan_aktif = (SELECT COUNT(*) FROM tbl_penempatan
                                WHERE id_karyawan = NEW.id AND status = 'Aktif');
      IF NEW.status = 'Ditempatkan' THEN
        IF v_penempatan_aktif = 0 THEN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status Ditempatkan hanya diatur otomatis saat penempatan dibuat oleh Operasional';
        END IF;
      ELSEIF NEW.status NOT IN ('Tersedia', 'Cuti', 'Nonaktif') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status karyawan Lapangan hanya boleh Tersedia, Cuti, atau Nonaktif';
      ELSEIF v_penempatan_aktif > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status tidak dapat diubah karena karyawan masih memiliki penempatan Aktif. Akhiri penempatan terlebih dahulu';
      END IF;
    END IF;
  END IF;
END //

-- Status akun login mengikuti status karyawan:
--   menjadi Nonaktif -> akun Nonaktif; kembali dari Nonaktif ke Tersedia/Aktif -> akun Aktif.
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

DELIMITER ;

-- [TAHAP 5]
DELIMITER //

-- Jumlah hari kalender dalam [p_awal, p_akhir] yang tercakup penempatan karyawan (status apa pun).
-- Penempatan yang bersambung/tumpang-tindih tidak dihitung ganda. p_id_klien NULL = semua klien.
DROP FUNCTION IF EXISTS fn_hari_penempatan //
CREATE FUNCTION fn_hari_penempatan(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE)
RETURNS INT
READS SQL DATA
BEGIN
  RETURN (
    WITH RECURSIVE hari AS (
      SELECT p_awal AS tgl
      UNION ALL
      SELECT DATE_ADD(tgl, INTERVAL 1 DAY) FROM hari WHERE tgl < p_akhir
    )
    SELECT COUNT(*) FROM hari h
    WHERE EXISTS (
      SELECT 1 FROM tbl_penempatan p
      WHERE p.id_karyawan = p_id_karyawan
        AND (p_id_klien IS NULL OR p.id_klien = p_id_klien)
        AND p.tgl_mulai <= h.tgl
        AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= h.tgl)
    )
  );
END //

-- Jumlah hari penempatan karyawan per klien, dijumlahkan untuk semua klien dalam [p_awal, p_akhir].
-- Dipakai sebagai pembagi alokasi gaji di fn_profit_klien: hari rotasi yang tercatat di dua klien
-- ikut dihitung di keduanya, sehingga porsi seluruh klien selalu berjumlah tepat 100% gaji.
DROP FUNCTION IF EXISTS fn_total_hari_klien //
CREATE FUNCTION fn_total_hari_klien(p_id_karyawan INT, p_awal DATE, p_akhir DATE)
RETURNS INT
READS SQL DATA
BEGIN
  RETURN (
    SELECT COALESCE(SUM(fn_hari_penempatan(p_id_karyawan, x.id_klien, p_awal, p_akhir)), 0)
    FROM (SELECT DISTINCT id_klien FROM tbl_penempatan
          WHERE id_karyawan = p_id_karyawan
            AND tgl_mulai <= p_akhir
            AND (tgl_selesai IS NULL OR tgl_selesai >= p_awal)) x
  );
END //

-- Payroll bulanan prorata.
--   Lapangan : tarif_harian = gaji kontrak terbaru di bulan itu / hari_kerja_per_bulan
--              hari_wajib   = ROUND(hari_kerja_per_bulan * hari_penempatan / jumlah_hari_bulan)
--              hari_masuk   = absensi Hadir + Terlambat + Sakit di bulan itu
--              hari_absen   = GREATEST(0, hari_wajib - hari_masuk)  (hari tanpa absensi ikut terhitung)
--              gaji_pokok   = tarif_harian * hari_wajib; potongan = tarif_harian * hari_absen
--   Internal : gaji_pokok penuh, hari_wajib 25, potongan dari Izin/Alpha yang tercatat,
--              hari_masuk NULL (staf internal tidak check-in).
--   Total    = gaji_pokok + lembur - potongan.
-- Kursor: Internal Aktif/Cuti, dan Lapangan (status apa pun) yang punya penempatan overlap bulan itu.
-- Payroll yang sudah ada untuk periode tersebut dilewati.
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

-- Invoice klien prorata: SEMUA penempatan di klien itu yang overlap bulan (status apa pun),
-- tagihan per penempatan = tarif_tagihan_klien * hari penempatan dalam bulan / jumlah hari bulan.
DROP PROCEDURE IF EXISTS sp_generate_invoice_klien //
CREATE PROCEDURE sp_generate_invoice_klien(IN p_id_klien INT, IN p_bulan INT, IN p_tahun INT)
BEGIN
  DECLARE v_jumlah INT;
  DECLARE v_total DECIMAL(12,2);
  DECLARE v_awal DATE;
  DECLARE v_akhir DATE;
  DECLARE v_hari_bulan INT;

  IF p_bulan IS NULL OR p_bulan NOT BETWEEN 1 AND 12 OR p_tahun IS NULL OR p_tahun NOT BETWEEN 2000 AND 2100 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Periode invoice tidak valid';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM tbl_klien WHERE id = p_id_klien) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Klien tidak ditemukan';
  END IF;
  IF EXISTS (SELECT 1 FROM tbl_invoice
             WHERE id_klien = p_id_klien AND periode_bulan = p_bulan AND periode_tahun = p_tahun) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice periode ini sudah ada';
  END IF;

  SET v_awal = CONCAT(p_tahun, '-', LPAD(p_bulan, 2, '0'), '-01');
  SET v_akhir = LAST_DAY(v_awal);
  SET v_hari_bulan = DAY(v_akhir);

  SELECT COUNT(DISTINCT p.id_karyawan),
         COALESCE(SUM(ROUND(jl.tarif_tagihan_klien
           * (DATEDIFF(LEAST(IFNULL(p.tgl_selesai, v_akhir), v_akhir), GREATEST(p.tgl_mulai, v_awal)) + 1)
           / v_hari_bulan, 2)), 0)
  INTO v_jumlah, v_total
  FROM tbl_penempatan p
  JOIN tbl_karyawan k ON p.id_karyawan = k.id
  JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
  WHERE p.id_klien = p_id_klien
    AND p.tgl_mulai <= v_akhir
    AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= v_awal);

  IF v_jumlah = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Tidak ada tenaga yang ditempatkan di klien ini pada periode tersebut';
  END IF;

  INSERT INTO tbl_invoice
    (nomor_invoice, id_klien, periode_bulan, periode_tahun, jumlah_tenaga, total_tagihan)
  VALUES
    (CONCAT('INV-', p_tahun, '-', LPAD(p_bulan, 2, '0'), '-', LPAD(p_id_klien, 3, '0')),
     p_id_klien, p_bulan, p_tahun, v_jumlah, v_total);
END //

-- Profit klien: gaji karyawan dibagi proporsional ke tiap klien berdasarkan hari penempatan
-- di klien itu dibanding jumlah hari penempatannya di semua klien (fn_total_hari_klien).
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

  SELECT COALESCE(SUM(ROUND(
           py.total_gaji
           * fn_hari_penempatan(py.id_karyawan, p_id_klien, v_awal, v_akhir)
           / NULLIF(fn_total_hari_klien(py.id_karyawan, v_awal, v_akhir), 0), 2)), 0)
  INTO v_keluar
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

-- Slip gaji dengan rincian hari (hari_wajib, hari_masuk, hari_absen).
CREATE OR REPLACE VIEW v_slip_gaji_detail AS
SELECT p.id, k.nip, k.nama, k.kategori, p.periode_bulan, p.periode_tahun,
       p.gaji_pokok, p.total_lembur, p.potongan_absen, p.total_gaji,
       p.status_bayar, p.tgl_bayar, p.hari_wajib, p.hari_masuk, p.hari_absen
FROM tbl_payroll p
JOIN tbl_karyawan k ON p.id_karyawan = k.id //

-- Dashboard eksekutif: profit bulan berjalan memakai rentang tanggal (bukan MONTH()/YEAR()).
CREATE OR REPLACE VIEW v_dashboard_eksekutif AS
SELECT
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Lapangan' AND status = 'Ditempatkan') AS lapangan_bertugas,
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Lapangan' AND status = 'Tersedia') AS lapangan_siap,
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Internal' AND status = 'Aktif') AS internal_aktif,
  fn_total_piutang() AS total_piutang,
  (SELECT COALESCE(SUM(CASE WHEN jenis = 'Pemasukan' THEN nominal ELSE -nominal END), 0)
   FROM tbl_mutasi_keuangan
   WHERE tgl_mutasi BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE()))
   AS profit_bulan_berjalan //

DELIMITER ;

-- [TAHAP 6]
DELIMITER //

-- Status lembur diisi saat check-out: 'Menunggu' bila ada indikasi lembur, selain itu 'Tidak Ada'.
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

    -- Lembur baru menunggu persetujuan HRD (sp_putuskan_lembur).
    SET NEW.status_lembur = IF(NEW.jam_lembur > 0, 'Menunggu', 'Tidak Ada');
  END IF;
END //

-- HRD menyetujui/menolak lembur yang berstatus Menunggu.
DROP PROCEDURE IF EXISTS sp_putuskan_lembur //
CREATE PROCEDURE sp_putuskan_lembur(IN p_id_absensi INT, IN p_keputusan VARCHAR(10))
BEGIN
  DECLARE v_status VARCHAR(10);

  IF p_keputusan IS NULL OR p_keputusan NOT IN ('Disetujui', 'Ditolak') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Keputusan lembur hanya boleh Disetujui atau Ditolak';
  END IF;

  SET v_status = (SELECT status_lembur FROM tbl_absensi WHERE id = p_id_absensi);

  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data absensi tidak ditemukan';
  END IF;
  IF v_status <> 'Menunggu' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hanya lembur yang berstatus Menunggu yang dapat diputuskan';
  END IF;

  UPDATE tbl_absensi SET status_lembur = p_keputusan WHERE id = p_id_absensi;
END //

-- Payroll hanya menghitung lembur yang Disetujui.
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
                            WHERE id_karyawan = v_id AND tgl BETWEEN v_awal AND v_akhir
                              AND status_lembur = 'Disetujui');
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

-- Absensi harian + status persetujuan lembur.
CREATE OR REPLACE VIEW v_absensi_harian AS
SELECT a.id AS id_absensi, a.id_karyawan, k.nip, k.nama, jl.nama_layanan, a.tgl,
       MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun, a.jam_masuk, a.jam_keluar,
       a.status_kehadiran, a.menit_terlambat, fn_hitung_jam_kerja(a.jam_masuk, a.jam_keluar) AS jam_kerja,
       a.jam_lembur, a.status_lembur, a.id_penempatan, kl.nama_perusahaan AS nama_klien, p.lokasi_kerja,
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

-- Status sertifikat otomatis: setiap hari pukul 00:05 dokumen yang lewat tanggal kedaluwarsa
-- ditandai 'Kadaluarsa'. Membutuhkan event_scheduler = ON. Tampilan tetap menghitung status dari
-- tanggal (fn_sisa_hari_dokumen), sehingga tetap benar walau event tidak berjalan.
DROP EVENT IF EXISTS ev_update_status_sertifikat //
CREATE EVENT ev_update_status_sertifikat
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '00:05:00')
ON COMPLETION PRESERVE
ENABLE
COMMENT 'Tandai sertifikat Kadaluarsa bila tgl_kadaluarsa < hari ini'
DO
  UPDATE tbl_sertifikat
  SET status = 'Kadaluarsa'
  WHERE status <> 'Kadaluarsa' AND tgl_kadaluarsa < CURDATE() //

DELIMITER ;

-- [TAHAP 8]
DELIMITER //

-- Log aktivitas. id_user diambil dari variabel sesi @app_user_id yang diisi middleware Laravel
-- (SetAppUserIdDatabase) setiap request setelah login; NULL bila perubahan dari luar aplikasi.
-- Trigger log digabung ke trigger yang sudah ada bila event-nya sama.

-- tbl_karyawan INSERT: log + akun login otomatis karyawan Lapangan (trigger file 01).
DROP TRIGGER IF EXISTS trg_auto_akun_karyawan //
CREATE TRIGGER trg_auto_akun_karyawan
AFTER INSERT ON tbl_karyawan
FOR EACH ROW
BEGIN
  DECLARE v_role INT;

  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_karyawan', 'INSERT', NEW.id,
          LEFT(CONCAT('Karyawan baru ', NEW.nip, ' - ', NEW.nama, ' (', NEW.kategori, ', ', NEW.status, ')'), 255));

  IF NEW.kategori = 'Lapangan' THEN
    SELECT id INTO v_role FROM tbl_roles WHERE nama_role = 'Karyawan Lapangan';
    INSERT INTO tbl_users (id_karyawan, username, password, id_role)
    VALUES (NEW.id, NEW.nip, SHA2(NEW.nip, 256), v_role);
  END IF;
END //

-- tbl_karyawan UPDATE: sinkron status akun (Tahap 4) + log perubahan status.
DROP TRIGGER IF EXISTS trg_karyawan_sinkron_akun //
CREATE TRIGGER trg_karyawan_sinkron_akun
AFTER UPDATE ON tbl_karyawan
FOR EACH ROW
BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_karyawan', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status karyawan ', NEW.nip, ' - ', NEW.nama, ': ', OLD.status, ' -> ', NEW.status), 255));

    IF NEW.status = 'Nonaktif' THEN
      UPDATE tbl_users SET status = 'Nonaktif' WHERE id_karyawan = NEW.id;
    ELSEIF OLD.status = 'Nonaktif' AND NEW.status IN ('Tersedia', 'Aktif') THEN
      UPDATE tbl_users SET status = 'Aktif' WHERE id_karyawan = NEW.id;
    END IF;
  END IF;
END //

DROP TRIGGER IF EXISTS trg_karyawan_log_hapus //
CREATE TRIGGER trg_karyawan_log_hapus
AFTER DELETE ON tbl_karyawan
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_karyawan', 'DELETE', OLD.id,
          LEFT(CONCAT('Karyawan dihapus ', OLD.nip, ' - ', OLD.nama, ' (', OLD.kategori, ')'), 255));
END //

-- tbl_penempatan
DROP TRIGGER IF EXISTS trg_penempatan_log_tambah //
CREATE TRIGGER trg_penempatan_log_tambah
AFTER INSERT ON tbl_penempatan
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_penempatan', 'INSERT', NEW.id,
          LEFT(CONCAT('Penempatan baru: ',
                      IFNULL((SELECT CONCAT(nip, ' - ', nama) FROM tbl_karyawan WHERE id = NEW.id_karyawan), CONCAT('karyawan #', NEW.id_karyawan)),
                      ' ke ', IFNULL((SELECT nama_perusahaan FROM tbl_klien WHERE id = NEW.id_klien), CONCAT('klien #', NEW.id_klien)),
                      ' mulai ', NEW.tgl_mulai), 255));
END //

DROP TRIGGER IF EXISTS trg_penempatan_log_ubah //
CREATE TRIGGER trg_penempatan_log_ubah
AFTER UPDATE ON tbl_penempatan
FOR EACH ROW
BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_penempatan', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status penempatan #', NEW.id, ' (',
                        IFNULL((SELECT nama FROM tbl_karyawan WHERE id = NEW.id_karyawan), CONCAT('karyawan #', NEW.id_karyawan)),
                        '): ', OLD.status, ' -> ', NEW.status,
                        IF(NEW.tgl_selesai IS NULL, '', CONCAT(', selesai ', NEW.tgl_selesai))), 255));
  END IF;
END //

-- tbl_payroll UPDATE: buku kas saat gaji cair (file 05/09) + log perubahan status bayar.
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

-- tbl_invoice UPDATE: buku kas saat lunas (file 05/09) + log perubahan status.
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

-- tbl_users
DROP TRIGGER IF EXISTS trg_users_log_tambah //
CREATE TRIGGER trg_users_log_tambah
AFTER INSERT ON tbl_users
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_users', 'INSERT', NEW.id,
          LEFT(CONCAT('Akun baru ', NEW.username, ' (role ',
                      IFNULL((SELECT nama_role FROM tbl_roles WHERE id = NEW.id_role), NEW.id_role), ', ', NEW.status, ')'), 255));
END //

DROP TRIGGER IF EXISTS trg_users_log_ubah //
CREATE TRIGGER trg_users_log_ubah
AFTER UPDATE ON tbl_users
FOR EACH ROW
BEGIN
  IF NOT (NEW.id_role <=> OLD.id_role) OR NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_users', 'UPDATE', NEW.id,
            LEFT(CONCAT('Akun ', NEW.username, ': ', CONCAT_WS('; ',
                 IF(NEW.id_role <=> OLD.id_role, NULL,
                    CONCAT('role ', IFNULL((SELECT nama_role FROM tbl_roles WHERE id = OLD.id_role), OLD.id_role),
                           ' -> ', IFNULL((SELECT nama_role FROM tbl_roles WHERE id = NEW.id_role), NEW.id_role))),
                 IF(NEW.status <=> OLD.status, NULL, CONCAT('status ', OLD.status, ' -> ', NEW.status)))), 255));
  END IF;
END //

DROP TRIGGER IF EXISTS trg_users_log_hapus //
CREATE TRIGGER trg_users_log_hapus
AFTER DELETE ON tbl_users
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_users', 'DELETE', OLD.id,
          LEFT(CONCAT('Akun dihapus ', OLD.username), 255));
END //

-- tbl_klien
DROP TRIGGER IF EXISTS trg_klien_log_tambah //
CREATE TRIGGER trg_klien_log_tambah
AFTER INSERT ON tbl_klien
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_klien', 'INSERT', NEW.id,
          LEFT(CONCAT('Klien baru ', NEW.kode_klien, ' - ', NEW.nama_perusahaan, ' (', NEW.status, ')'), 255));
END //

DROP TRIGGER IF EXISTS trg_klien_log_ubah //
CREATE TRIGGER trg_klien_log_ubah
AFTER UPDATE ON tbl_klien
FOR EACH ROW
BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_klien', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status klien ', NEW.kode_klien, ' - ', NEW.nama_perusahaan, ': ', OLD.status, ' -> ', NEW.status), 255));
  END IF;
END //

DELIMITER ;

-- ============================================================
-- 22_backup_shift_integritas_rollback.sql — Membatalkan 22_backup_shift_integritas.sql.
-- Definisi yang dipulihkan sama dengan sql/final sebelum perubahan backup shift.
-- Blok dijalankan migration dalam urutan terbalik (6 -> 1) sebelum kolom tabel dikembalikan.
-- ============================================================

-- [TAHAP 7]
-- Dijalankan pertama saat rollback: CHECK laporan aktivitas lama (gagal bila sudah ada laporan tanpa periode/tahapan).
DELIMITER //

ALTER TABLE tbl_laporan_aktivitas
  DROP CONSTRAINT chk_laporan_jenis,
  ADD CONSTRAINT chk_laporan_jenis CHECK (
    (jenis = 'Berkala' AND periode_ke IS NOT NULL AND periode_mulai IS NOT NULL AND periode_selesai IS NOT NULL)
    OR (jenis = 'Perjalanan' AND periode_ke IS NULL AND tahapan IS NOT NULL)
  ) //

DELIMITER ;

-- [TAHAP 6]
DELIMITER //

ALTER TABLE tbl_koreksi_check_out DROP CONSTRAINT chk_koreksi_check_out_diproses //

DELIMITER ;

-- [TAHAP 5]
DELIMITER //

DROP TRIGGER IF EXISTS trg_penempatan_batalkan_backup //

DELIMITER ;

-- [TAHAP 4]
DELIMITER //

DROP FUNCTION IF EXISTS fn_hari_kerja_roster_klien //
CREATE FUNCTION fn_hari_kerja_roster_klien(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE) RETURNS int
    READS SQL DATA
BEGIN
  -- Tanggal berstatus Kerja di roster, lintas penempatan; satu tanggal dihitung sekali.
  RETURN (
    SELECT COUNT(DISTINCT j.tanggal_kerja)
    FROM tbl_jadwal_kerja j
    JOIN tbl_penempatan p ON p.id = j.id_penempatan
    WHERE p.id_karyawan = p_id_karyawan
      AND (p_id_klien IS NULL OR p.id_klien = p_id_klien)
      AND j.status = 'Kerja'
      AND j.tanggal_kerja BETWEEN p_awal AND p_akhir
      AND j.tanggal_kerja >= p.tgl_mulai
      AND (p.tgl_selesai IS NULL OR j.tanggal_kerja <= p.tgl_selesai)
  );
END //

DROP FUNCTION IF EXISTS fn_hari_tanpa_jadwal_klien //
CREATE FUNCTION fn_hari_tanpa_jadwal_klien(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE) RETURNS int
    READS SQL DATA
BEGIN
  -- Hari dalam masa penempatan yang belum punya baris jadwal (Kerja maupun Libur). Baris jadwal di luar masa
  -- penempatannya (sisa sebelum penempatan diperpendek oleh rotasi) tidak dihitung, sama dengan fn_hari_kerja_roster.
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
    AND NOT EXISTS (
      SELECT 1 FROM tbl_jadwal_kerja j
      JOIN tbl_penempatan p ON p.id = j.id_penempatan
      WHERE p.id_karyawan = p_id_karyawan
        AND (p_id_klien IS NULL OR p.id_klien = p_id_klien)
        AND j.tanggal_kerja = h.tgl
        AND j.tanggal_kerja >= p.tgl_mulai
        AND (p.tgl_selesai IS NULL OR j.tanggal_kerja <= p.tgl_selesai)
    )
  );
END //

DROP FUNCTION IF EXISTS fn_status_roster //
CREATE FUNCTION fn_status_roster(p_id_karyawan INT, p_tgl DATE) RETURNS varchar(5) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
    READS SQL DATA
BEGIN
  -- 'Kerja' bila ada jadwal Kerja di penempatan mana pun pada tanggal itu, 'Libur' bila hanya Libur, NULL bila belum dijadwalkan.
  RETURN (
    SELECT CASE WHEN SUM(j.status = 'Kerja') > 0 THEN 'Kerja' WHEN COUNT(*) > 0 THEN 'Libur' END
    FROM tbl_jadwal_kerja j
    JOIN tbl_penempatan p ON p.id = j.id_penempatan
    WHERE p.id_karyawan = p_id_karyawan AND j.tanggal_kerja = p_tgl
  );
END //

DELIMITER ;

-- [TAHAP 3]
DELIMITER //

DROP TRIGGER IF EXISTS trg_cek_penempatan_absensi_update //
DROP TRIGGER IF EXISTS trg_cek_penempatan_absensi_insert //
DROP PROCEDURE IF EXISTS sp_cek_penempatan_absensi //

DROP TRIGGER IF EXISTS trg_cegah_absen_ganda //
CREATE TRIGGER trg_cegah_absen_ganda BEFORE INSERT ON tbl_absensi FOR EACH ROW PRECEDES trg_validasi_absen_masuk BEGIN
  DECLARE v_ada INT;
  SELECT COUNT(*) INTO v_ada FROM tbl_absensi
  WHERE id_karyawan = NEW.id_karyawan AND tgl = NEW.tgl;
  IF v_ada > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Karyawan sudah absen pada tanggal ini';
  END IF;
END //

DELIMITER ;

-- [TAHAP 2]
DELIMITER //

DROP TRIGGER IF EXISTS trg_cek_jadwal_kerja_insert //
DROP TRIGGER IF EXISTS trg_cek_jadwal_kerja_update //

DROP PROCEDURE IF EXISTS sp_cek_jadwal_kerja //
CREATE PROCEDURE sp_cek_jadwal_kerja(IN p_id INT, IN p_id_penempatan INT, IN p_tanggal DATE, IN p_status VARCHAR(5),
                                     IN p_jam_masuk TIME, IN p_jam_pulang TIME)
BEGIN
  DECLARE v_id_karyawan INT;
  DECLARE v_mulai DATE;
  DECLARE v_selesai DATE;
  DECLARE v_awal DATETIME;
  DECLARE v_akhir DATETIME;
  DECLARE v_bentrok DATE;
  DECLARE v_pesan VARCHAR(255);

  SELECT id_karyawan, tgl_mulai, tgl_selesai INTO v_id_karyawan, v_mulai, v_selesai
  FROM tbl_penempatan WHERE id = p_id_penempatan;

  IF v_id_karyawan IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan tidak ditemukan.';
  END IF;
  IF p_tanggal < v_mulai OR (v_selesai IS NOT NULL AND p_tanggal > v_selesai) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal jadwal harus berada dalam masa penempatan.';
  END IF;

  IF p_status = 'Kerja' THEN
    IF p_jam_masuk IS NULL OR p_jam_pulang IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pilih jam tersimpan atau isi jam masuk dan jam pulang terlebih dahulu.';
    END IF;

    SET v_awal = TIMESTAMP(p_tanggal, p_jam_masuk);
    SET v_akhir = TIMESTAMP(IF(p_jam_pulang <= p_jam_masuk, p_tanggal + INTERVAL 1 DAY, p_tanggal), p_jam_pulang);

    SELECT j.tanggal_kerja INTO v_bentrok
    FROM tbl_jadwal_kerja j
    JOIN tbl_penempatan p ON p.id = j.id_penempatan
    WHERE p.id_karyawan = v_id_karyawan
      AND j.id_penempatan <> p_id_penempatan
      AND (p_id IS NULL OR j.id <> p_id)
      AND j.status = 'Kerja'
      AND j.jam_masuk IS NOT NULL AND j.jam_pulang IS NOT NULL
      AND j.tanggal_kerja BETWEEN p_tanggal - INTERVAL 1 DAY AND p_tanggal + INTERVAL 1 DAY
      AND TIMESTAMP(j.tanggal_kerja, j.jam_masuk) < v_akhir
      AND TIMESTAMP(IF(j.jam_pulang <= j.jam_masuk, j.tanggal_kerja + INTERVAL 1 DAY, j.tanggal_kerja), j.jam_pulang) > v_awal
    ORDER BY j.tanggal_kerja
    LIMIT 1;

    IF v_bentrok IS NOT NULL THEN
      SET v_pesan = CONCAT('Jam kerja bertabrakan dengan jadwal Kerja karyawan ini di penempatan lain pada ',
                           DATE_FORMAT(v_bentrok, '%d-%m-%Y'), '.');
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_pesan;
    END IF;
  END IF;
END //

CREATE TRIGGER trg_cek_jadwal_kerja_insert BEFORE INSERT ON tbl_jadwal_kerja FOR EACH ROW BEGIN
  CALL sp_cek_jadwal_kerja(NULL, NEW.id_penempatan, NEW.tanggal_kerja, NEW.status, NEW.jam_masuk, NEW.jam_pulang);
END //

CREATE TRIGGER trg_cek_jadwal_kerja_update BEFORE UPDATE ON tbl_jadwal_kerja FOR EACH ROW BEGIN
  CALL sp_cek_jadwal_kerja(OLD.id, NEW.id_penempatan, NEW.tanggal_kerja, NEW.status, NEW.jam_masuk, NEW.jam_pulang);
END //

ALTER TABLE tbl_jadwal_kerja DROP CONSTRAINT chk_jadwal_pengganti_kerja //

DELIMITER ;

-- [TAHAP 1]
DELIMITER //

DROP TRIGGER IF EXISTS trg_pertukaran_shift_insert //
DROP TRIGGER IF EXISTS trg_pertukaran_shift_update //
DROP PROCEDURE IF EXISTS sp_cek_pertukaran_shift //

ALTER TABLE tbl_pertukaran_shift DROP CONSTRAINT chk_pertukaran_rekan, DROP CONSTRAINT chk_pertukaran_diproses //

DROP VIEW IF EXISTS v_pertukaran_shift_snapshot //

DELIMITER ;

-- [TAHAP 8]
-- Dijalankan migration SETELAH kolom rekan dikembalikan dan status lama dipulihkan.
DELIMITER //

ALTER TABLE tbl_pertukaran_shift
  ADD CONSTRAINT chk_pertukaran_diproses CHECK (
    (status = 'Menunggu' AND diproses_oleh IS NULL AND diproses_pada IS NULL)
    OR (status <> 'Menunggu' AND diproses_oleh IS NOT NULL AND diproses_pada IS NOT NULL)
  ) //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_pertukaran_shift_snapshot AS
SELECT ps.id, ps.tanggal_kerja, ps.tanggal_kerja_rekan, ps.status, ps.status_rekan,
       ps.id_karyawan_pengaju, kp.nama AS nama_pengaju, ps.id_karyawan_rekan, kr.nama AS nama_rekan,
       JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_pengaju, '$.status')) AS status_jadwal_pengaju,
       JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_pengaju, '$.jam_masuk')) AS jam_masuk_pengaju,
       JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_pengaju, '$.jam_pulang')) AS jam_pulang_pengaju,
       NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_pengaju, '$.nama_shift_snapshot')), 'null') AS shift_pengaju,
       JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_rekan, '$.status')) AS status_jadwal_rekan,
       JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_rekan, '$.jam_masuk')) AS jam_masuk_rekan,
       JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_rekan, '$.jam_pulang')) AS jam_pulang_rekan,
       NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_rekan, '$.nama_shift_snapshot')), 'null') AS shift_rekan,
       ps.created_at
FROM tbl_pertukaran_shift ps
JOIN tbl_karyawan kp ON kp.id = ps.id_karyawan_pengaju
JOIN tbl_karyawan kr ON kr.id = ps.id_karyawan_rekan //

DELIMITER ;

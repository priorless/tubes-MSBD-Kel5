-- [TAHAP 1]
-- Skema dengan tbl_kontrak.tunjangan
DELIMITER //

DROP PROCEDURE IF EXISTS sp_rotasi_karyawan //
CREATE PROCEDURE sp_rotasi_karyawan(
  IN p_id_penempatan_lama INT, IN p_id_klien_baru INT,
  IN p_tgl_mulai_baru DATE, IN p_tgl_selesai_baru DATE,
  IN p_lokasi_baru VARCHAR(255),
  IN p_jam_datang_baru TIME, IN p_jam_pulang_baru TIME, IN p_dibuat_oleh INT)
BEGIN
  DECLARE v_tidak_ada INT DEFAULT 0;
  DECLARE v_status_lama VARCHAR(20);
  DECLARE v_status_klien VARCHAR(20);
  DECLARE v_tgl_mulai_lama DATE;
  DECLARE v_karyawan INT;
  DECLARE v_gaji DECIMAL(12,2);
  DECLARE v_tunjangan DECIMAL(12,2);
  DECLARE v_jam_datang TIME;
  DECLARE v_jam_pulang TIME;
  DECLARE v_pen_id INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_tidak_ada = 1;

  IF p_tgl_mulai_baru IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal mulai penempatan baru wajib diisi';
  END IF;
  IF p_tgl_selesai_baru IS NOT NULL AND p_tgl_selesai_baru < p_tgl_mulai_baru THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal selesai kontrak baru tidak boleh sebelum tanggal mulai';
  END IF;
  IF p_lokasi_baru IS NULL OR TRIM(p_lokasi_baru) = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lokasi kerja baru wajib diisi';
  END IF;

  START TRANSACTION;
    SELECT status, id_karyawan, tgl_mulai, jam_datang, jam_pulang
    INTO v_status_lama, v_karyawan, v_tgl_mulai_lama, v_jam_datang, v_jam_pulang
    FROM tbl_penempatan
    WHERE id = p_id_penempatan_lama
    FOR UPDATE;

    IF v_tidak_ada = 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan lama tidak ditemukan';
    END IF;
    IF v_status_lama <> 'Aktif' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hanya penempatan berstatus Aktif yang dapat dirotasi';
    END IF;
    IF p_tgl_mulai_baru <= v_tgl_mulai_lama THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal mulai baru harus setelah tanggal mulai penempatan lama';
    END IF;

    SET v_tidak_ada = 0;
    SELECT status
    INTO v_status_klien
    FROM tbl_klien
    WHERE id = p_id_klien_baru
    FOR UPDATE;
    IF v_tidak_ada = 1 OR v_status_klien IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Klien tujuan tidak ditemukan';
    END IF;
    IF v_status_klien <> 'Aktif' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Klien tujuan berstatus Nonaktif';
    END IF;

    SET v_tidak_ada = 0;
    SELECT gaji_pokok, tunjangan
    INTO v_gaji, v_tunjangan
    FROM tbl_kontrak
    WHERE id_penempatan = p_id_penempatan_lama
    ORDER BY id DESC
    LIMIT 1
    FOR UPDATE;

    IF v_tidak_ada = 1 OR v_gaji IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kontrak penempatan lama tidak ditemukan';
    END IF;

    UPDATE tbl_penempatan
    SET status = 'Rotasi', tgl_selesai = DATE_SUB(p_tgl_mulai_baru, INTERVAL 1 DAY)
    WHERE id = p_id_penempatan_lama AND status = 'Aktif';
    UPDATE tbl_kontrak
    SET status = 'Selesai'
    WHERE id_penempatan = p_id_penempatan_lama;

    INSERT INTO tbl_penempatan
      (id_karyawan, id_klien, lokasi_kerja, jam_datang, jam_pulang, tgl_mulai, tgl_selesai, status, dibuat_oleh)
    VALUES
      (v_karyawan, p_id_klien_baru, p_lokasi_baru,
       IFNULL(p_jam_datang_baru, v_jam_datang), IFNULL(p_jam_pulang_baru, v_jam_pulang),
       p_tgl_mulai_baru, p_tgl_selesai_baru, 'Aktif', p_dibuat_oleh);
    SET v_pen_id = LAST_INSERT_ID();
    INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok, tunjangan)
    VALUES (v_pen_id, v_gaji, v_tunjangan);
  COMMIT;
END //

DELIMITER ;

-- [TAHAP 2]
-- Skema setelah kolom tbl_kontrak.tunjangan dihapus
DELIMITER //

DROP PROCEDURE IF EXISTS sp_rotasi_karyawan //
CREATE PROCEDURE sp_rotasi_karyawan(
  IN p_id_penempatan_lama INT, IN p_id_klien_baru INT,
  IN p_tgl_mulai_baru DATE, IN p_tgl_selesai_baru DATE,
  IN p_lokasi_baru VARCHAR(255),
  IN p_jam_datang_baru TIME, IN p_jam_pulang_baru TIME, IN p_dibuat_oleh INT)
BEGIN
  DECLARE v_tidak_ada INT DEFAULT 0;
  DECLARE v_status_lama VARCHAR(20);
  DECLARE v_status_klien VARCHAR(20);
  DECLARE v_tgl_mulai_lama DATE;
  DECLARE v_karyawan INT;
  DECLARE v_gaji DECIMAL(12,2);
  DECLARE v_jam_datang TIME;
  DECLARE v_jam_pulang TIME;
  DECLARE v_pen_id INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_tidak_ada = 1;

  IF p_tgl_mulai_baru IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal mulai penempatan baru wajib diisi';
  END IF;
  IF p_tgl_selesai_baru IS NOT NULL AND p_tgl_selesai_baru < p_tgl_mulai_baru THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal selesai kontrak baru tidak boleh sebelum tanggal mulai';
  END IF;
  IF p_lokasi_baru IS NULL OR TRIM(p_lokasi_baru) = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lokasi kerja baru wajib diisi';
  END IF;

  START TRANSACTION;
    SELECT status, id_karyawan, tgl_mulai, jam_datang, jam_pulang
    INTO v_status_lama, v_karyawan, v_tgl_mulai_lama, v_jam_datang, v_jam_pulang
    FROM tbl_penempatan
    WHERE id = p_id_penempatan_lama
    FOR UPDATE;

    IF v_tidak_ada = 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan lama tidak ditemukan';
    END IF;
    IF v_status_lama <> 'Aktif' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hanya penempatan berstatus Aktif yang dapat dirotasi';
    END IF;
    IF p_tgl_mulai_baru <= v_tgl_mulai_lama THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal mulai baru harus setelah tanggal mulai penempatan lama';
    END IF;

    SET v_tidak_ada = 0;
    SELECT status
    INTO v_status_klien
    FROM tbl_klien
    WHERE id = p_id_klien_baru
    FOR UPDATE;
    IF v_tidak_ada = 1 OR v_status_klien IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Klien tujuan tidak ditemukan';
    END IF;
    IF v_status_klien <> 'Aktif' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Klien tujuan berstatus Nonaktif';
    END IF;

    SET v_tidak_ada = 0;
    SELECT gaji_pokok
    INTO v_gaji
    FROM tbl_kontrak
    WHERE id_penempatan = p_id_penempatan_lama
    ORDER BY id DESC
    LIMIT 1
    FOR UPDATE;

    IF v_tidak_ada = 1 OR v_gaji IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kontrak penempatan lama tidak ditemukan';
    END IF;

    UPDATE tbl_penempatan
    SET status = 'Rotasi', tgl_selesai = DATE_SUB(p_tgl_mulai_baru, INTERVAL 1 DAY)
    WHERE id = p_id_penempatan_lama AND status = 'Aktif';
    UPDATE tbl_kontrak
    SET status = 'Selesai'
    WHERE id_penempatan = p_id_penempatan_lama;

    INSERT INTO tbl_penempatan
      (id_karyawan, id_klien, lokasi_kerja, jam_datang, jam_pulang, tgl_mulai, tgl_selesai, status, dibuat_oleh)
    VALUES
      (v_karyawan, p_id_klien_baru, p_lokasi_baru,
       IFNULL(p_jam_datang_baru, v_jam_datang), IFNULL(p_jam_pulang_baru, v_jam_pulang),
       p_tgl_mulai_baru, p_tgl_selesai_baru, 'Aktif', p_dibuat_oleh);
    SET v_pen_id = LAST_INSERT_ID();
    INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok)
    VALUES (v_pen_id, v_gaji);
  COMMIT;
END //

DELIMITER ;

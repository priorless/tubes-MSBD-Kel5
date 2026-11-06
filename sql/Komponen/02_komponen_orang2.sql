-- ============================================================
-- /sql/komponen/02_komponen_orang2.sql  (Modul Operasional)
-- ============================================================
DELIMITER //

CREATE PROCEDURE sp_assign_penempatan(
  IN p_id_karyawan INT, IN p_id_klien INT, IN p_tgl_mulai DATE,
  IN p_tgl_selesai DATE, IN p_lokasi VARCHAR(255), IN p_dibuat_oleh INT)
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
      (id_karyawan, id_klien, lokasi_kerja, tgl_mulai, tgl_selesai, status, dibuat_oleh)
    VALUES
      (p_id_karyawan, p_id_klien, p_lokasi, p_tgl_mulai, p_tgl_selesai, 'Aktif', p_dibuat_oleh);
    SET v_pen_id = LAST_INSERT_ID();
    INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok, tunjangan)
    VALUES (v_pen_id, v_tarif, 0);
    UPDATE tbl_karyawan SET status = 'Ditempatkan' WHERE id = p_id_karyawan;
  COMMIT;
END //

CREATE PROCEDURE sp_rotasi_karyawan(
  IN p_id_penempatan_lama INT, IN p_id_klien_baru INT,
  IN p_tgl_mulai_baru DATE, IN p_lokasi_baru VARCHAR(255), IN p_dibuat_oleh INT)
BEGIN
  DECLARE v_karyawan INT;
  DECLARE v_gaji DECIMAL(12,2);
  DECLARE v_tunjangan DECIMAL(12,2);
  DECLARE v_pen_id INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  SELECT id_karyawan INTO v_karyawan FROM tbl_penempatan WHERE id = p_id_penempatan_lama;
  SELECT gaji_pokok, tunjangan INTO v_gaji, v_tunjangan
  FROM tbl_kontrak WHERE id_penempatan = p_id_penempatan_lama;

  START TRANSACTION;
    UPDATE tbl_penempatan SET status = 'Rotasi', tgl_selesai = CURDATE()
    WHERE id = p_id_penempatan_lama;
    UPDATE tbl_kontrak SET status = 'Selesai'
    WHERE id_penempatan = p_id_penempatan_lama;
    INSERT INTO tbl_penempatan
      (id_karyawan, id_klien, lokasi_kerja, tgl_mulai, tgl_selesai, status, dibuat_oleh)
    VALUES
      (v_karyawan, p_id_klien_baru, p_lokasi_baru, p_tgl_mulai_baru, NULL, 'Aktif', p_dibuat_oleh);
    SET v_pen_id = LAST_INSERT_ID();
    INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok, tunjangan)
    VALUES (v_pen_id, v_gaji, v_tunjangan);
  COMMIT;
END //

CREATE VIEW v_monitoring_sebaran_operasional AS
SELECT p.id AS id_penempatan, k.nip, k.nama, jl.nama_layanan,
       kl.nama_perusahaan, p.lokasi_kerja, p.tgl_mulai, p.tgl_selesai,
       IFNULL(DATEDIFF(p.tgl_selesai, CURDATE()), 9999) AS sisa_hari_kontrak
FROM tbl_penempatan p
JOIN tbl_karyawan k ON p.id_karyawan = k.id
JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
JOIN tbl_klien kl ON p.id_klien = kl.id
WHERE p.status = 'Aktif' //

DELIMITER ;
-- Cara tes: CALL sp_assign_penempatan pakai id karyawan Internal -> harus ditolak.
-- Assign karyawan Tersedia -> cek 3 tabel berubah serentak (penempatan, kontrak, status).
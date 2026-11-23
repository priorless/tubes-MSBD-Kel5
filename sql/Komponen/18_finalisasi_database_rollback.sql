-- ============================================================
-- 18_finalisasi_database_rollback.sql — Kebalikan tiap blok di 18_finalisasi_database.sql.
-- Dijalankan oleh down() migration yang bersesuaian.
-- ============================================================

-- [TAHAP 1]
-- Mengembalikan kolom status_lembur, pengisiannya di trigger, v_absensi_harian, dan sp_putuskan_lembur.
-- Data status lama tidak tersimpan; lembur yang ada dikembalikan sebagai 'Menunggu'.
DELIMITER //

ALTER TABLE tbl_absensi
  ADD COLUMN status_lembur ENUM('Tidak Ada','Menunggu','Disetujui','Ditolak') NOT NULL DEFAULT 'Tidak Ada' AFTER jam_lembur //

UPDATE tbl_absensi SET status_lembur = 'Menunggu' WHERE jam_lembur > 0 //

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

    SET v_pulang = fn_jadwal_absensi(NEW.id_penempatan, NEW.tgl, 'pulang');
    IF v_pulang IS NULL THEN
      SET NEW.jam_lembur = 0;
    ELSE
      SET v_sampai_pulang = FLOOR(MOD(TIME_TO_SEC(v_pulang) - TIME_TO_SEC(NEW.jam_masuk) + 86400, 86400) / 60);
      SET NEW.jam_lembur = GREATEST(0, FLOOR((v_durasi - v_sampai_pulang) / 60));
    END IF;

    SET NEW.status_lembur = IF(NEW.jam_lembur > 0, 'Menunggu', 'Tidak Ada');
  END IF;
END //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_absensi_harian AS
SELECT a.id AS id_absensi, a.id_karyawan, k.nip, k.nama, jl.nama_layanan, a.tgl,
       MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun, a.jam_masuk, a.jam_keluar, a.status_kehadiran,
       a.menit_terlambat, fn_hitung_jam_kerja(a.jam_masuk, a.jam_keluar) AS jam_kerja, a.jam_lembur,
       a.status_lembur, a.id_penempatan, kl.nama_perusahaan AS nama_klien, p.lokasi_kerja,
       fn_jadwal_absensi(a.id_penempatan, a.tgl, 'datang') AS jadwal_datang,
       fn_jadwal_absensi(a.id_penempatan, a.tgl, 'pulang') AS jadwal_pulang,
       a.foto_masuk, a.foto_keluar,
       (a.jam_masuk IS NOT NULL AND a.jam_keluar IS NULL AND TIMESTAMP(a.tgl, a.jam_masuk) < NOW() - INTERVAL 16 HOUR) AS lupa_check_out,
       a.keterangan, fn_sumber_jadwal_absensi(a.id_penempatan, a.tgl) AS sumber_jadwal
FROM tbl_absensi a
JOIN tbl_karyawan k ON k.id = a.id_karyawan
LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
LEFT JOIN tbl_penempatan p ON p.id = a.id_penempatan
LEFT JOIN tbl_klien kl ON kl.id = p.id_klien //

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

DELIMITER ;

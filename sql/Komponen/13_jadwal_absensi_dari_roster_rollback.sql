-- ============================================================
-- /sql/komponen/13_jadwal_absensi_dari_roster_rollback.sql
-- Mengembalikan jadwal absensi ke jam penempatan saja (keadaan sebelum file 13):
-- fn_jadwal_absensi 2 parameter (file 08), trg_validasi_absen_masuk (file 08),
-- trg_auto_hitung_lembur (file 11 [TAHAP 6]), v_penugasan_aktif (file 08), v_absensi_harian (file 11 [TAHAP 6]).
-- View dipasang lebih dulu agar tidak ada yang merujuk function yang dihapus.
-- ============================================================

-- [TAHAP 1]
DELIMITER //

CREATE OR REPLACE VIEW v_penugasan_aktif AS
SELECT p.id AS id_penempatan, p.id_karyawan,
       kl.nama_perusahaan AS nama_klien, p.lokasi_kerja, p.tgl_mulai, p.tgl_selesai,
       jl.nama_layanan,
       p.jam_datang AS jadwal_datang,
       p.jam_pulang AS jadwal_pulang
FROM tbl_penempatan p
JOIN tbl_klien kl ON kl.id = p.id_klien
JOIN tbl_karyawan k ON k.id = p.id_karyawan
LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
WHERE p.status = 'Aktif'
  AND p.tgl_mulai <= CURDATE()
  AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= CURDATE()) //

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

DROP TRIGGER IF EXISTS trg_validasi_absen_masuk //
DROP TRIGGER IF EXISTS trg_auto_hitung_lembur //
DROP FUNCTION IF EXISTS fn_sumber_jadwal_absensi //
DROP FUNCTION IF EXISTS fn_jadwal_absensi //

CREATE FUNCTION fn_jadwal_absensi(p_id_penempatan INT, p_jenis VARCHAR(6))
RETURNS TIME
NOT DETERMINISTIC
READS SQL DATA
BEGIN
  IF p_id_penempatan IS NULL THEN
    RETURN NULL;
  END IF;
  RETURN (
    SELECT IF(p_jenis = 'pulang', jam_pulang, jam_datang)
    FROM tbl_penempatan WHERE id = p_id_penempatan
  );
END //

CREATE TRIGGER trg_validasi_absen_masuk
BEFORE INSERT ON tbl_absensi
FOR EACH ROW
FOLLOWS trg_cegah_absen_ganda
BEGIN
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_pen INT;
  DECLARE v_telat INT;
  DECLARE v_tidak_ada INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_tidak_ada = 1;

  IF NEW.jam_masuk IS NOT NULL THEN
    SELECT kategori INTO v_kategori FROM tbl_karyawan WHERE id = NEW.id_karyawan;

    IF NEW.id_penempatan IS NULL THEN
      SELECT p.id INTO v_pen
      FROM tbl_penempatan p
      WHERE p.id_karyawan = NEW.id_karyawan AND p.status = 'Aktif'
        AND p.tgl_mulai <= NEW.tgl AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= NEW.tgl)
      ORDER BY p.tgl_mulai DESC, p.id DESC
      LIMIT 1;
      SET NEW.id_penempatan = v_pen;
    END IF;

    IF NEW.foto_masuk IS NOT NULL THEN
      IF fn_id_absensi_aktif(NEW.id_karyawan) IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Anda masih tercatat bekerja pada shift sebelumnya. Lakukan check-out terlebih dahulu.';
      END IF;

      IF v_kategori = 'Lapangan' AND NEW.id_penempatan IS NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Anda belum memiliki penempatan aktif. Absensi dapat dilakukan setelah ditempatkan oleh bagian Operasional.';
      END IF;
    END IF;

    IF NEW.status_kehadiran = 'Hadir' THEN
      SET v_telat = fn_menit_terlambat(fn_jadwal_absensi(NEW.id_penempatan, 'datang'), NEW.jam_masuk);
      IF v_telat IS NOT NULL AND v_telat > 30 THEN
        SET NEW.status_kehadiran = 'Terlambat';
        SET NEW.menit_terlambat = v_telat;
      END IF;
    END IF;
  END IF;
END //

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

DELIMITER ;

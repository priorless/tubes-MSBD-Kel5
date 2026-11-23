-- ============================================================
-- /sql/komponen/07_komponen_orang4_absensi_foto.sql
-- Modul Tenaga Kerja (Orang 4) — absensi FOTO menggantikan GPS/geofence (file 06).
--
-- TIDAK ADA TABEL BARU.
--   Lokasi resmi absensi  : tbl_absensi.id_penempatan -> tbl_penempatan.lokasi_kerja (+ klien)
--   Jadwal keterlambatan  : tbl_penempatan.jam_datang / jam_pulang (modul Operasional),
--                           fallback tbl_jenis_layanan.jam_masuk / jam_keluar bila jadwal
--                           penempatan kosong. Keduanya kosong -> "Jadwal belum ditentukan",
--                           karyawan TIDAK ditandai terlambat.
--   Foto check-in/out     : tbl_absensi.foto_masuk / foto_keluar (path file privat, watermark
--                           waktu server + alamat aktual sudah tertanam di gambar).
--   Koordinat GPS TIDAK disimpan; lokasi aktual hanya tertulis pada watermark foto.
--
-- Aturan (di trigger, bukan di PHP):
--   1. Absensi ganda per tanggal ditolak (trg_cegah_absen_ganda + UNIQUE uq_absensi_karyawan_tgl).
--   2. Absensi dari aplikasi (foto terisi): shift sebelumnya harus sudah check-out,
--      karyawan Lapangan wajib punya penempatan aktif.
--   3. Terlambat bila check-in > jadwal datang + 30 menit (toleransi tetap 30 menit).
--   4. Check-out minimal 30 menit setelah check-in; lembur di atas durasi jadwal (bawaan 8 jam).
--   5. Shift lewat tengah malam tetap didukung (fn_id_absensi_aktif dari file 06).
--
-- JALANKAN SETELAH FILE 01-06. Di aplikasi cukup: php artisan migrate
-- (migrasi 2026_11_15_064609_ganti_absensi_gps_menjadi_foto menjalankan BAGIAN A
--  secara aman lalu BAGIAN B dari file ini).
-- ============================================================

-- ------------------------------------------------------------
-- BAGIAN A — Struktur tabel (dijalankan sekali saja)
-- ------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_validasi_absen_masuk;
DROP TRIGGER IF EXISTS trg_auto_hitung_lembur;
DROP VIEW IF EXISTS v_penugasan_aktif;
DROP VIEW IF EXISTS v_absensi_harian;
DROP FUNCTION IF EXISTS fn_jarak_meter;

ALTER TABLE tbl_absensi
  DROP COLUMN lat_masuk, DROP COLUMN lng_masuk, DROP COLUMN akurasi_masuk,
  DROP COLUMN jarak_masuk_meter, DROP COLUMN status_lokasi_masuk, DROP COLUMN alasan_lokasi_masuk,
  DROP COLUMN lat_keluar, DROP COLUMN lng_keluar, DROP COLUMN akurasi_keluar,
  DROP COLUMN jarak_keluar_meter, DROP COLUMN status_lokasi_keluar, DROP COLUMN alasan_lokasi_keluar,
  ADD COLUMN foto_masuk VARCHAR(255) NULL,
  ADD COLUMN foto_keluar VARCHAR(255) NULL;

ALTER TABLE tbl_klien
  DROP CONSTRAINT chk_klien_koordinat,
  DROP CONSTRAINT chk_klien_radius,
  DROP COLUMN latitude, DROP COLUMN longitude, DROP COLUMN radius_meter;

ALTER TABLE tbl_jenis_layanan
  DROP COLUMN toleransi_terlambat_menit,
  DROP COLUMN mode_lokasi;

-- ------------------------------------------------------------
-- BAGIAN B — Function, trigger, dan view (aman dijalankan ulang)
-- ------------------------------------------------------------
DELIMITER //

DROP FUNCTION IF EXISTS fn_jadwal_absensi //
-- Jadwal kerja yang berlaku untuk satu absensi.
--   p_jenis = 'datang' | 'pulang'
--   Sumber utama tbl_penempatan (jam_datang & jam_pulang), fallback tbl_jenis_layanan
--   (jam_masuk & jam_keluar) bila jadwal penempatan kosong. NULL = belum ditentukan.
CREATE FUNCTION fn_jadwal_absensi(p_id_karyawan INT, p_id_penempatan INT, p_jenis VARCHAR(6))
RETURNS TIME
NOT DETERMINISTIC
READS SQL DATA
BEGIN
  DECLARE v_datang TIME DEFAULT NULL;
  DECLARE v_pulang TIME DEFAULT NULL;
  DECLARE v_tidak_ada INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_tidak_ada = 1;

  IF p_id_penempatan IS NOT NULL THEN
    SELECT jam_datang, jam_pulang INTO v_datang, v_pulang
    FROM tbl_penempatan WHERE id = p_id_penempatan;
  END IF;

  IF v_datang IS NULL THEN
    SET v_pulang = NULL;
    SELECT jl.jam_masuk, jl.jam_keluar INTO v_datang, v_pulang
    FROM tbl_karyawan k
    JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
    WHERE k.id = p_id_karyawan;
  END IF;

  RETURN IF(p_jenis = 'pulang', v_pulang, v_datang);
END //

DROP FUNCTION IF EXISTS fn_menit_terlambat //
-- Selisih menit check-in terhadap jadwal datang, dibatasi -12..+12 jam
-- agar shift lewat tengah malam (mis. jadwal 23:00, masuk 00:10) tetap benar.
CREATE FUNCTION fn_menit_terlambat(p_jadwal TIME, p_jam_masuk TIME)
RETURNS INT
DETERMINISTIC
BEGIN
  IF p_jadwal IS NULL OR p_jam_masuk IS NULL THEN
    RETURN NULL;
  END IF;
  RETURN FLOOR((MOD(TIME_TO_SEC(p_jam_masuk) - TIME_TO_SEC(p_jadwal) + 86400 + 43200, 86400) - 43200) / 60);
END //

DROP TRIGGER IF EXISTS trg_validasi_absen_masuk //
-- Check-in: penempatan resmi, shift sebelumnya, dan status terlambat (toleransi 30 menit).
-- Aturan aplikasi hanya untuk absensi berfoto; input manual HRD (Izin, Sakit) tidak dicek.
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
      SET v_telat = fn_menit_terlambat(fn_jadwal_absensi(NEW.id_karyawan, NEW.id_penempatan, 'datang'), NEW.jam_masuk);
      IF v_telat IS NOT NULL AND v_telat > 30 THEN
        SET NEW.status_kehadiran = 'Terlambat';
        SET NEW.menit_terlambat = v_telat;
      END IF;
    END IF;
  END IF;
END //

DROP TRIGGER IF EXISTS trg_auto_hitung_lembur //
-- Check-out: minimal 30 menit (absensi berfoto) dan lembur di atas durasi jadwal
-- (bawaan 8 jam bila jadwal belum ditentukan), aman untuk shift malam.
CREATE TRIGGER trg_auto_hitung_lembur
BEFORE UPDATE ON tbl_absensi
FOR EACH ROW
BEGIN
  DECLARE v_durasi INT;
  DECLARE v_datang TIME;
  DECLARE v_pulang TIME;
  DECLARE v_shift_menit INT;
  DECLARE v_pesan VARCHAR(255);

  IF NEW.jam_keluar IS NOT NULL AND OLD.jam_keluar IS NULL AND NEW.jam_masuk IS NOT NULL THEN
    SET v_durasi = FLOOR(MOD(TIME_TO_SEC(NEW.jam_keluar) - TIME_TO_SEC(NEW.jam_masuk) + 86400, 86400) / 60);

    IF NEW.foto_keluar IS NOT NULL AND v_durasi < 30 THEN
      SET v_pesan = CONCAT('Check-out ditolak: minimal 30 menit setelah check-in (baru ', v_durasi, ' menit).');
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_pesan;
    END IF;

    SET v_datang = fn_jadwal_absensi(NEW.id_karyawan, NEW.id_penempatan, 'datang');
    SET v_pulang = fn_jadwal_absensi(NEW.id_karyawan, NEW.id_penempatan, 'pulang');
    SET v_shift_menit = IF(v_datang IS NULL OR v_pulang IS NULL, 0,
      FLOOR(MOD(TIME_TO_SEC(v_pulang) - TIME_TO_SEC(v_datang) + 86400, 86400) / 60));
    IF v_shift_menit = 0 THEN
      SET v_shift_menit = 480;
    END IF;

    SET NEW.jam_lembur = GREATEST(0, FLOOR((v_durasi - v_shift_menit) / 60));
  END IF;
END //

-- Penugasan aktif hari ini + jadwal kerja efektif (halaman absensi karyawan).
CREATE OR REPLACE VIEW v_penugasan_aktif AS
SELECT p.id AS id_penempatan, p.id_karyawan,
       kl.nama_perusahaan AS nama_klien, p.lokasi_kerja, p.tgl_mulai, p.tgl_selesai,
       jl.nama_layanan,
       IF(p.jam_datang IS NOT NULL, p.jam_datang, jl.jam_masuk) AS jadwal_datang,
       IF(p.jam_datang IS NOT NULL, p.jam_pulang, jl.jam_keluar) AS jadwal_pulang,
       CASE WHEN p.jam_datang IS NOT NULL THEN 'Penempatan'
            WHEN jl.jam_masuk IS NOT NULL THEN 'Jenis Layanan' END AS sumber_jadwal
FROM tbl_penempatan p
JOIN tbl_klien kl ON kl.id = p.id_klien
JOIN tbl_karyawan k ON k.id = p.id_karyawan
LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
WHERE p.status = 'Aktif'
  AND p.tgl_mulai <= CURDATE()
  AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= CURDATE()) //

-- Detail absensi per hari: riwayat karyawan & laporan absensi HRD.
-- Lokasi resmi = penempatan yang tercatat pada baris absensi.
CREATE OR REPLACE VIEW v_absensi_harian AS
SELECT a.id AS id_absensi, a.id_karyawan, k.nip, k.nama, jl.nama_layanan,
       a.tgl, MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun,
       a.jam_masuk, a.jam_keluar, a.status_kehadiran, a.menit_terlambat,
       fn_hitung_jam_kerja(a.jam_masuk, a.jam_keluar) AS jam_kerja, a.jam_lembur,
       a.id_penempatan, kl.nama_perusahaan AS nama_klien, p.lokasi_kerja,
       IF(p.jam_datang IS NOT NULL, p.jam_datang, jl.jam_masuk) AS jadwal_datang,
       IF(p.jam_datang IS NOT NULL, p.jam_pulang, jl.jam_keluar) AS jadwal_pulang,
       CASE WHEN p.jam_datang IS NOT NULL THEN 'Penempatan'
            WHEN jl.jam_masuk IS NOT NULL THEN 'Jenis Layanan' END AS sumber_jadwal,
       a.foto_masuk, a.foto_keluar,
       (a.jam_masuk IS NOT NULL AND a.jam_keluar IS NULL
        AND TIMESTAMP(a.tgl, a.jam_masuk) < NOW() - INTERVAL 16 HOUR) AS lupa_check_out,
       a.keterangan
FROM tbl_absensi a
JOIN tbl_karyawan k ON k.id = a.id_karyawan
LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
LEFT JOIN tbl_penempatan p ON p.id = a.id_penempatan
LEFT JOIN tbl_klien kl ON kl.id = p.id_klien //

-- Rekap bulanan (versi 04) + total menit terlambat.
CREATE OR REPLACE VIEW v_rekap_absensi_pribadi AS
SELECT k.id AS id_karyawan, k.nama,
       MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun,
       COUNT(*) AS total_baris,
       SUM(a.status_kehadiran = 'Hadir') AS hari_hadir,
       SUM(a.status_kehadiran = 'Terlambat') AS hari_terlambat,
       SUM(a.status_kehadiran IN ('Izin','Sakit','Alpha')) AS hari_tidak_hadir,
       SUM(a.menit_terlambat) AS total_menit_terlambat,
       SUM(a.jam_lembur) AS total_jam_lembur
FROM tbl_absensi a
JOIN tbl_karyawan k ON a.id_karyawan = k.id
GROUP BY k.id, k.nama, MONTH(a.tgl), YEAR(a.tgl) //

DELIMITER ;

-- Cara tes (MySQL):
--   UPDATE tbl_penempatan SET jam_datang = '07:00', jam_pulang = '15:00' WHERE id = <penempatan>;
--   INSERT INTO tbl_absensi (id_karyawan, tgl, jam_masuk, foto_masuk)
--     VALUES (<karyawan itu>, CURDATE(), '07:45:00', 'absensi/uji.jpg');
--     -> status_kehadiran 'Terlambat', menit_terlambat 45, id_penempatan terisi otomatis.
--   Jadwal penempatan NULL -> pakai jam_masuk tbl_jenis_layanan; keduanya NULL -> tetap 'Hadir'.
--   UPDATE jam_keluar < 30 menit setelah check-in dengan foto_keluar terisi -> ditolak.

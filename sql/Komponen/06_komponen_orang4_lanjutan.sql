-- ============================================================
-- /sql/komponen/06_komponen_orang4_lanjutan.sql
-- Modul Tenaga Kerja (Orang 4) — pengembangan absensi.
--
-- TIDAK ADA TABEL BARU. Lokasi penempatan karyawan sudah ada di tbl_penempatan
-- (modul Operasional). File ini hanya MENAMBAH KOLOM pada tabel yang sudah ada:
--   tbl_jenis_layanan : jadwal shift & aturan lokasi per layanan (Satpam/Driver/Cleaning)
--   tbl_klien         : titik koordinat & radius area kerja klien
--   tbl_absensi       : koordinat GPS, jarak, status lokasi, alasan, id_penempatan
--
-- Alur data absensi:
--   tbl_absensi.id_penempatan -> tbl_penempatan (karyawan X ditempatkan di klien Y)
--                             -> tbl_klien (titik & radius lokasi kerja)
--   tbl_karyawan.id_jenis_layanan -> tbl_jenis_layanan (jam shift, toleransi, mode lokasi)
--
-- Aturan (semua di trigger, bukan di PHP):
--   1. Lokasi GPS check-in/check-out DISIMPAN, jaraknya ke lokasi kerja dihitung.
--   2. Di dalam radius -> 'Di Area'. Di luar radius -> TIDAK ditolak, tetapi wajib
--      menulis alasan (SQLSTATE 45001) lalu ditandai 'Luar Area' untuk diperiksa HRD.
--      (GPS sering meleset di dalam gedung/basement; karyawan yang benar-benar
--       bertugas tidak boleh kehilangan absensinya karena sinyal.)
--   3. Mode lokasi per layanan:
--        'Tetap'    (Satpam, Cleaning Service): check-in & check-out dicek area kerjanya.
--        'Bergerak' (Driver): check-in dicek di titik kumpul/pool klien; check-out
--                   bebas di mana saja ('Bebas') karena perjalanan bisa berakhir di
--                   tempat lain, tetapi koordinat & jaraknya tetap dicatat.
--   4. Jadwal shift per layanan -> status 'Terlambat' & menit terlambat otomatis
--      (toleransi 30 menit).
--   5. Minimal 30 menit kerja sebelum check-out.
--   6. Lapangan tanpa penempatan aktif tidak bisa check-in.
--   7. Shift lewat tengah malam (19:00-07:00) bisa check-out keesokan hari.
--
-- JALANKAN SETELAH FILE 01-05. Di aplikasi cukup: php artisan migrate
-- (migrasi 2026_11_14_161048_tambah_geofence_dan_shift_absensi menambah kolom
--  BAGIAN A secara aman lalu menjalankan BAGIAN B dari file ini).
-- ============================================================

-- ------------------------------------------------------------
-- BAGIAN A — Struktur tabel (dijalankan sekali saja)
-- ------------------------------------------------------------
ALTER TABLE tbl_jenis_layanan
  ADD COLUMN jam_masuk TIME NOT NULL DEFAULT '08:00:00',
  ADD COLUMN jam_keluar TIME NOT NULL DEFAULT '16:00:00',
  ADD COLUMN toleransi_terlambat_menit SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  ADD COLUMN mode_lokasi ENUM('Tetap','Bergerak') NOT NULL DEFAULT 'Tetap';

UPDATE tbl_jenis_layanan SET jam_masuk = '07:00:00', jam_keluar = '15:00:00' WHERE nama_layanan IN ('Satpam', 'Cleaning Service');
UPDATE tbl_jenis_layanan SET jam_masuk = '08:00:00', jam_keluar = '16:00:00', mode_lokasi = 'Bergerak' WHERE nama_layanan = 'Driver';

ALTER TABLE tbl_klien
  ADD COLUMN latitude DECIMAL(10,7) NULL,
  ADD COLUMN longitude DECIMAL(10,7) NULL,
  ADD COLUMN radius_meter SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  ADD CONSTRAINT chk_klien_koordinat CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180),
  ADD CONSTRAINT chk_klien_radius CHECK (radius_meter BETWEEN 25 AND 5000);

ALTER TABLE tbl_absensi
  ADD COLUMN id_penempatan INT NULL AFTER id_karyawan,
  ADD COLUMN menit_terlambat SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER status_kehadiran,
  ADD COLUMN lat_masuk DECIMAL(10,7) NULL,
  ADD COLUMN lng_masuk DECIMAL(10,7) NULL,
  ADD COLUMN akurasi_masuk INT UNSIGNED NULL,
  ADD COLUMN jarak_masuk_meter INT UNSIGNED NULL,
  ADD COLUMN status_lokasi_masuk ENUM('Di Area','Luar Area','Tanpa Titik') NULL,
  ADD COLUMN alasan_lokasi_masuk VARCHAR(255) NULL,
  ADD COLUMN lat_keluar DECIMAL(10,7) NULL,
  ADD COLUMN lng_keluar DECIMAL(10,7) NULL,
  ADD COLUMN akurasi_keluar INT UNSIGNED NULL,
  ADD COLUMN jarak_keluar_meter INT UNSIGNED NULL,
  ADD COLUMN status_lokasi_keluar ENUM('Di Area','Luar Area','Tanpa Titik','Bebas') NULL,
  ADD COLUMN alasan_lokasi_keluar VARCHAR(255) NULL,
  ADD CONSTRAINT fk_absensi_penempatan FOREIGN KEY (id_penempatan) REFERENCES tbl_penempatan (id);

-- ------------------------------------------------------------
-- BAGIAN B — Function, trigger, dan view (aman dijalankan ulang)
-- ------------------------------------------------------------
DELIMITER //

DROP FUNCTION IF EXISTS fn_jarak_meter //
-- Jarak dua koordinat (meter) di permukaan bumi — rumus haversine bawaan MySQL 8.
CREATE FUNCTION fn_jarak_meter(
  p_lat1 DECIMAL(10,7), p_lng1 DECIMAL(10,7), p_lat2 DECIMAL(10,7), p_lng2 DECIMAL(10,7))
RETURNS INT
DETERMINISTIC
BEGIN
  IF p_lat1 IS NULL OR p_lng1 IS NULL OR p_lat2 IS NULL OR p_lng2 IS NULL THEN
    RETURN NULL;
  END IF;
  RETURN ROUND(ST_Distance_Sphere(POINT(p_lng1, p_lat1), POINT(p_lng2, p_lat2)));
END //

DROP FUNCTION IF EXISTS fn_hitung_jam_kerja //
-- Jam kerja utuh; mendukung shift lewat tengah malam (19:00 -> 07:00 = 12 jam).
CREATE FUNCTION fn_hitung_jam_kerja(p_jam_masuk TIME, p_jam_keluar TIME)
RETURNS INT
DETERMINISTIC
BEGIN
  RETURN FLOOR(MOD(TIME_TO_SEC(p_jam_keluar) - TIME_TO_SEC(p_jam_masuk) + 86400, 86400) / 3600);
END //

DROP FUNCTION IF EXISTS fn_id_absensi_aktif //
-- Baris absensi yang sedang berlaku untuk karyawan:
--   absensi HARI INI, atau absensi KEMARIN yang belum check-out dan check-in-nya
--   belum lewat 16 jam (shift malam). Lebih dari 16 jam = dianggap lupa check-out.
CREATE FUNCTION fn_id_absensi_aktif(p_id_karyawan INT)
RETURNS INT
NOT DETERMINISTIC
READS SQL DATA
BEGIN
  RETURN (
    SELECT id FROM tbl_absensi
    WHERE id_karyawan = p_id_karyawan
      AND (tgl = CURDATE()
           OR (tgl = CURDATE() - INTERVAL 1 DAY
               AND jam_masuk IS NOT NULL AND jam_keluar IS NULL
               AND TIMESTAMP(tgl, jam_masuk) >= NOW() - INTERVAL 16 HOUR))
    ORDER BY tgl DESC
    LIMIT 1
  );
END //

DROP TRIGGER IF EXISTS trg_validasi_absen_masuk //
-- Check-in: penempatan aktif, status lokasi (geofence), dan status terlambat.
-- Aturan lokasi hanya untuk absensi dari aplikasi (lat_masuk terisi);
-- input manual HRD (Izin, Sakit, koreksi tanpa koordinat) tidak dicek lokasinya.
CREATE TRIGGER trg_validasi_absen_masuk
BEFORE INSERT ON tbl_absensi
FOR EACH ROW
FOLLOWS trg_cegah_absen_ganda
BEGIN
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_shift_masuk TIME;
  DECLARE v_toleransi INT;
  DECLARE v_pen INT;
  DECLARE v_lat DECIMAL(10,7);
  DECLARE v_lng DECIMAL(10,7);
  DECLARE v_radius INT;
  DECLARE v_klien VARCHAR(150);
  DECLARE v_jarak INT;
  DECLARE v_telat INT;
  DECLARE v_pesan VARCHAR(255);
  DECLARE v_tidak_ada INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_tidak_ada = 1;

  IF NEW.jam_masuk IS NOT NULL THEN
    SELECT k.kategori, jl.jam_masuk, jl.toleransi_terlambat_menit
      INTO v_kategori, v_shift_masuk, v_toleransi
    FROM tbl_karyawan k
    LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
    WHERE k.id = NEW.id_karyawan;

    SELECT p.id, kl.latitude, kl.longitude, kl.radius_meter, kl.nama_perusahaan
      INTO v_pen, v_lat, v_lng, v_radius, v_klien
    FROM tbl_penempatan p
    JOIN tbl_klien kl ON kl.id = p.id_klien
    WHERE p.id_karyawan = NEW.id_karyawan AND p.status = 'Aktif'
      AND p.tgl_mulai <= NEW.tgl AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= NEW.tgl)
    ORDER BY p.tgl_mulai DESC, p.id DESC
    LIMIT 1;

    IF NEW.id_penempatan IS NULL THEN
      SET NEW.id_penempatan = v_pen;
    END IF;

    IF NEW.lat_masuk IS NOT NULL THEN
      IF fn_id_absensi_aktif(NEW.id_karyawan) IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Anda masih tercatat bekerja pada shift sebelumnya. Lakukan check-out terlebih dahulu.';
      END IF;

      IF v_kategori = 'Lapangan' AND v_pen IS NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Anda belum memiliki penempatan aktif. Absensi dapat dilakukan setelah ditempatkan oleh bagian Operasional.';
      END IF;

      SET NEW.alasan_lokasi_masuk = NULLIF(TRIM(NEW.alasan_lokasi_masuk), '');

      IF v_lat IS NULL OR v_lng IS NULL THEN
        SET NEW.status_lokasi_masuk = 'Tanpa Titik';
      ELSE
        SET v_jarak = fn_jarak_meter(NEW.lat_masuk, NEW.lng_masuk, v_lat, v_lng);
        SET NEW.jarak_masuk_meter = v_jarak;
        -- Toleransi akurasi GPS, maksimal 100 m agar tidak bisa dipakai untuk curang.
        IF v_jarak <= v_radius + LEAST(IFNULL(NEW.akurasi_masuk, 0), 100) THEN
          SET NEW.status_lokasi_masuk = 'Di Area';
        ELSE
          IF NEW.alasan_lokasi_masuk IS NULL THEN
            -- 45001 = boleh lanjut setelah karyawan menulis alasan (ditangkap aplikasi).
            SET v_pesan = CONCAT('Lokasi Anda ', FORMAT(v_jarak, 0, 'id_ID'), ' m dari area kerja ', v_klien,
                                 ' (batas ', v_radius, ' m). Tuliskan alasannya agar absensi tetap tercatat.');
            SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT = v_pesan;
          END IF;
          SET NEW.status_lokasi_masuk = 'Luar Area';
        END IF;
      END IF;
    END IF;

    IF v_shift_masuk IS NOT NULL AND NEW.status_kehadiran = 'Hadir' THEN
      SET v_telat = FLOOR((TIME_TO_SEC(NEW.jam_masuk) - TIME_TO_SEC(v_shift_masuk)) / 60);
      IF v_telat > v_toleransi THEN
        SET NEW.status_kehadiran = 'Terlambat';
        SET NEW.menit_terlambat = v_telat;
      END IF;
    END IF;
  END IF;
END //

DROP TRIGGER IF EXISTS trg_auto_hitung_lembur //
-- Check-out: minimal durasi, status lokasi (mode 'Tetap' dicek, 'Bergerak' bebas),
-- dan lembur di atas durasi shift (default 8 jam), aman untuk shift malam.
CREATE TRIGGER trg_auto_hitung_lembur
BEFORE UPDATE ON tbl_absensi
FOR EACH ROW
BEGIN
  DECLARE v_durasi INT;
  DECLARE v_shift_menit INT;
  DECLARE v_mode VARCHAR(10);
  DECLARE v_lat DECIMAL(10,7);
  DECLARE v_lng DECIMAL(10,7);
  DECLARE v_radius INT;
  DECLARE v_klien VARCHAR(150);
  DECLARE v_jarak INT;
  DECLARE v_pesan VARCHAR(255);
  DECLARE v_tidak_ada INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_tidak_ada = 1;

  IF NEW.jam_keluar IS NOT NULL AND OLD.jam_keluar IS NULL AND NEW.jam_masuk IS NOT NULL THEN
    SET v_durasi = FLOOR(MOD(TIME_TO_SEC(NEW.jam_keluar) - TIME_TO_SEC(NEW.jam_masuk) + 86400, 86400) / 60);

    SELECT FLOOR(MOD(TIME_TO_SEC(jl.jam_keluar) - TIME_TO_SEC(jl.jam_masuk) + 86400, 86400) / 60), jl.mode_lokasi
      INTO v_shift_menit, v_mode
    FROM tbl_karyawan k
    JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
    WHERE k.id = NEW.id_karyawan;

    IF v_shift_menit IS NULL OR v_shift_menit = 0 THEN
      SET v_shift_menit = 480;
    END IF;

    IF NEW.lat_keluar IS NOT NULL THEN
      IF v_durasi < 30 THEN
        SET v_pesan = CONCAT('Check-out ditolak: minimal 30 menit setelah check-in (baru ', v_durasi, ' menit).');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_pesan;
      END IF;

      SET NEW.alasan_lokasi_keluar = NULLIF(TRIM(NEW.alasan_lokasi_keluar), '');

      IF NEW.id_penempatan IS NOT NULL THEN
        SELECT kl.latitude, kl.longitude, kl.radius_meter, kl.nama_perusahaan
          INTO v_lat, v_lng, v_radius, v_klien
        FROM tbl_penempatan p
        JOIN tbl_klien kl ON kl.id = p.id_klien
        WHERE p.id = NEW.id_penempatan;
      END IF;

      IF v_lat IS NULL OR v_lng IS NULL THEN
        SET NEW.status_lokasi_keluar = 'Tanpa Titik';
      ELSE
        SET v_jarak = fn_jarak_meter(NEW.lat_keluar, NEW.lng_keluar, v_lat, v_lng);
        SET NEW.jarak_keluar_meter = v_jarak;
        IF IFNULL(v_mode, 'Tetap') = 'Bergerak' THEN
          SET NEW.status_lokasi_keluar = 'Bebas';
        ELSEIF v_jarak <= v_radius + LEAST(IFNULL(NEW.akurasi_keluar, 0), 100) THEN
          SET NEW.status_lokasi_keluar = 'Di Area';
        ELSE
          IF NEW.alasan_lokasi_keluar IS NULL THEN
            SET v_pesan = CONCAT('Lokasi Anda ', FORMAT(v_jarak, 0, 'id_ID'), ' m dari area kerja ', v_klien,
                                 ' (batas ', v_radius, ' m). Tuliskan alasannya agar absensi tetap tercatat.');
            SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT = v_pesan;
          END IF;
          SET NEW.status_lokasi_keluar = 'Luar Area';
        END IF;
      END IF;
    END IF;

    SET NEW.jam_lembur = GREATEST(0, FLOOR((v_durasi - v_shift_menit) / 60));
  END IF;
END //

-- Penugasan aktif hari ini + jadwal shift + titik lokasi kerja (halaman absensi karyawan).
CREATE OR REPLACE VIEW v_penugasan_aktif AS
SELECT p.id AS id_penempatan, p.id_karyawan,
       kl.nama_perusahaan AS nama_klien, p.lokasi_kerja, p.tgl_mulai, p.tgl_selesai,
       kl.latitude, kl.longitude, kl.radius_meter,
       jl.nama_layanan, jl.jam_masuk AS jam_masuk_shift, jl.jam_keluar AS jam_keluar_shift,
       jl.toleransi_terlambat_menit, jl.mode_lokasi
FROM tbl_penempatan p
JOIN tbl_klien kl ON kl.id = p.id_klien
JOIN tbl_karyawan k ON k.id = p.id_karyawan
LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
WHERE p.status = 'Aktif'
  AND p.tgl_mulai <= CURDATE()
  AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= CURDATE()) //

-- Detail absensi per hari: riwayat karyawan & pemantauan absensi HRD.
CREATE OR REPLACE VIEW v_absensi_harian AS
SELECT a.id AS id_absensi, a.id_karyawan, k.nip, k.nama, jl.nama_layanan,
       a.tgl, MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun,
       a.jam_masuk, a.jam_keluar, a.status_kehadiran, a.menit_terlambat,
       fn_hitung_jam_kerja(a.jam_masuk, a.jam_keluar) AS jam_kerja, a.jam_lembur,
       kl.nama_perusahaan AS nama_klien, p.lokasi_kerja, kl.radius_meter,
       a.lat_masuk, a.lng_masuk, a.jarak_masuk_meter, a.status_lokasi_masuk, a.alasan_lokasi_masuk,
       a.lat_keluar, a.lng_keluar, a.jarak_keluar_meter, a.status_lokasi_keluar, a.alasan_lokasi_keluar,
       (a.status_lokasi_masuk = 'Luar Area' OR a.status_lokasi_keluar = 'Luar Area') AS perlu_cek_lokasi,
       (a.jam_masuk IS NOT NULL AND a.jam_keluar IS NULL
        AND TIMESTAMP(a.tgl, a.jam_masuk) < NOW() - INTERVAL 16 HOUR) AS lupa_check_out,
       a.keterangan
FROM tbl_absensi a
JOIN tbl_karyawan k ON k.id = a.id_karyawan
LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
LEFT JOIN tbl_penempatan p ON p.id = a.id_penempatan
LEFT JOIN tbl_klien kl ON kl.id = p.id_klien //

-- Rekap bulanan (versi 04) + total menit terlambat & hari absen di luar area.
CREATE OR REPLACE VIEW v_rekap_absensi_pribadi AS
SELECT k.id AS id_karyawan, k.nama,
       MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun,
       COUNT(*) AS total_baris,
       SUM(a.status_kehadiran = 'Hadir') AS hari_hadir,
       SUM(a.status_kehadiran = 'Terlambat') AS hari_terlambat,
       SUM(a.status_kehadiran IN ('Izin','Sakit','Alpha')) AS hari_tidak_hadir,
       SUM(a.menit_terlambat) AS total_menit_terlambat,
       SUM(IFNULL(a.status_lokasi_masuk = 'Luar Area' OR a.status_lokasi_keluar = 'Luar Area', 0)) AS hari_luar_area,
       SUM(a.jam_lembur) AS total_jam_lembur
FROM tbl_absensi a
JOIN tbl_karyawan k ON a.id_karyawan = k.id
GROUP BY k.id, k.nama, MONTH(a.tgl), YEAR(a.tgl) //

DELIMITER ;

-- Cara tes (MySQL):
--   UPDATE tbl_klien SET latitude = 3.5951956, longitude = 98.6722227, radius_meter = 200 WHERE id = <klien>;
--   INSERT INTO tbl_absensi (id_karyawan, tgl, jam_masuk, lat_masuk, lng_masuk)
--     VALUES (<karyawan ditempatkan di klien itu>, CURDATE(), CURTIME(), -6.2, 106.8);
--     -> ditolak SQLSTATE 45001 (minta alasan)
--   ... tambah alasan_lokasi_masuk = 'GPS meleset di basement' -> diterima, status_lokasi_masuk = 'Luar Area'
--   ... lat/lng dekat titik klien -> 'Di Area', jarak_masuk_meter terisi,
--       status 'Terlambat' bila lewat jam shift + 30 menit.
--   UPDATE jam_keluar < 30 menit setelah check-in dengan lat_keluar terisi -> ditolak.
--   Shift 19:00 -> 07:00: jam_lembur = 12 - 8 = 4 (sebelumnya 0 karena selisih negatif).

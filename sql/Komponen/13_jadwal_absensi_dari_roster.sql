-- ============================================================
-- /sql/komponen/13_jadwal_absensi_dari_roster.sql
-- Keterlambatan & indikasi lembur memakai JADWAL HARIAN (tbl_jadwal_kerja), termasuk jadwal
-- hasil Pertukaran Shift yang disetujui Operasional. TIDAK ADA TABEL BARU.
--
-- Urutan pencarian jadwal untuk (id_penempatan, tanggal absensi) — dipakai trigger DAN view:
--   1. tbl_jadwal_kerja status 'Kerja' -> jam_masuk / jam_pulang jadwal harian;
--   2. tbl_jadwal_kerja status 'Libur' -> NULL (tidak dinilai terlambat, tidak ada indikasi lembur;
--      jam penempatan TIDAK dipakai);
--   3. belum ada baris jadwal (Belum Diatur) -> tbl_penempatan.jam_datang / jam_pulang (perilaku lama).
--
-- Tanggal acuan = tbl_absensi.tgl (tanggal check-in). Check-out shift malam keesokan harinya tetap
-- memakai jadwal tanggal check-in. Aturan lain tidak berubah: toleransi terlambat 30 menit,
-- check-out minimal 30 menit, lembur = jam penuh melewati jam pulang, status_lembur 'Menunggu'.
-- Data absensi yang sudah ada tidak dihitung ulang.
--
-- Menggantikan objek dari file 08 (fn_jadwal_absensi, trg_validasi_absen_masuk, v_penugasan_aktif)
-- dan file 11 [TAHAP 6] (trg_auto_hitung_lembur, v_absensi_harian). Rollback: file 13_..._rollback.sql.
-- Di aplikasi cukup: php artisan migrate (migrasi 2026_11_17_*_jadwal_absensi_dari_roster).
-- ============================================================

-- [TAHAP 1]
DELIMITER //

DROP TRIGGER IF EXISTS trg_validasi_absen_masuk //
DROP TRIGGER IF EXISTS trg_auto_hitung_lembur //

DROP FUNCTION IF EXISTS fn_jadwal_absensi //
-- Jadwal kerja efektif untuk satu penempatan pada satu tanggal.
--   p_jenis = 'datang' | 'pulang'. NULL = tidak ada jadwal yang dinilai (Libur / belum ditentukan).
CREATE FUNCTION fn_jadwal_absensi(p_id_penempatan INT, p_tgl DATE, p_jenis VARCHAR(6))
RETURNS TIME
NOT DETERMINISTIC
READS SQL DATA
BEGIN
  DECLARE v_status VARCHAR(10) DEFAULT NULL;

  IF p_id_penempatan IS NULL THEN
    RETURN NULL;
  END IF;

  -- Subquery skalar (bukan SELECT ... INTO) agar tanggal tanpa jadwal tidak memicu kondisi NOT FOUND.
  -- (id_penempatan, tanggal_kerja) unik, jadwal paling banyak satu baris.
  IF p_tgl IS NOT NULL THEN
    SET v_status = (SELECT j.status FROM tbl_jadwal_kerja j
                    WHERE j.id_penempatan = p_id_penempatan AND j.tanggal_kerja = p_tgl);
  END IF;

  IF v_status = 'Kerja' THEN
    RETURN (SELECT IF(p_jenis = 'pulang', j.jam_pulang, j.jam_masuk) FROM tbl_jadwal_kerja j
            WHERE j.id_penempatan = p_id_penempatan AND j.tanggal_kerja = p_tgl);
  END IF;

  IF v_status = 'Libur' THEN
    RETURN NULL;
  END IF;

  RETURN (
    SELECT IF(p_jenis = 'pulang', jam_pulang, jam_datang)
    FROM tbl_penempatan WHERE id = p_id_penempatan
  );
END //

-- Asal jadwal yang dipakai fn_jadwal_absensi, untuk label di view:
--   'Jadwal Harian' | 'Libur' | 'Penempatan' (jadwal harian belum diatur). NULL = tanpa penempatan.
DROP FUNCTION IF EXISTS fn_sumber_jadwal_absensi //
CREATE FUNCTION fn_sumber_jadwal_absensi(p_id_penempatan INT, p_tgl DATE)
RETURNS VARCHAR(15)
NOT DETERMINISTIC
READS SQL DATA
BEGIN
  DECLARE v_status VARCHAR(10) DEFAULT NULL;

  IF p_id_penempatan IS NULL THEN
    RETURN NULL;
  END IF;

  SET v_status = (SELECT j.status FROM tbl_jadwal_kerja j
                  WHERE j.id_penempatan = p_id_penempatan AND j.tanggal_kerja = p_tgl);

  RETURN CASE v_status WHEN 'Kerja' THEN 'Jadwal Harian' WHEN 'Libur' THEN 'Libur' ELSE 'Penempatan' END;
END //

-- Check-in: penempatan yang berlaku pada tanggal absensi, shift sebelumnya,
-- dan status terlambat (toleransi 30 menit, hanya bila jadwal datang tanggal itu ada).
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
      SET v_telat = fn_menit_terlambat(fn_jadwal_absensi(NEW.id_penempatan, NEW.tgl, 'datang'), NEW.jam_masuk);
      IF v_telat IS NOT NULL AND v_telat > 30 THEN
        SET NEW.status_kehadiran = 'Terlambat';
        SET NEW.menit_terlambat = v_telat;
      END IF;
    END IF;
  END IF;
END //

-- Check-out: minimal 30 menit (absensi berfoto) dan indikasi lembur setelah jadwal pulang
-- tanggal check-in. Lembur baru menunggu persetujuan HRD (sp_putuskan_lembur).
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

-- Penugasan yang berlaku hari ini + jadwal kerja efektif HARI INI (halaman absensi karyawan).
CREATE OR REPLACE VIEW v_penugasan_aktif AS
SELECT p.id AS id_penempatan, p.id_karyawan,
       kl.nama_perusahaan AS nama_klien, p.lokasi_kerja, p.tgl_mulai, p.tgl_selesai,
       jl.nama_layanan,
       fn_jadwal_absensi(p.id, CURDATE(), 'datang') AS jadwal_datang,
       fn_jadwal_absensi(p.id, CURDATE(), 'pulang') AS jadwal_pulang,
       fn_sumber_jadwal_absensi(p.id, CURDATE()) AS sumber_jadwal
FROM tbl_penempatan p
JOIN tbl_klien kl ON kl.id = p.id_klien
JOIN tbl_karyawan k ON k.id = p.id_karyawan
LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
WHERE p.status = 'Aktif'
  AND p.tgl_mulai <= CURDATE()
  AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= CURDATE()) //

-- Absensi harian + status persetujuan lembur; jadwal = jadwal efektif tanggal absensi.
CREATE OR REPLACE VIEW v_absensi_harian AS
SELECT a.id AS id_absensi, a.id_karyawan, k.nip, k.nama, jl.nama_layanan, a.tgl,
       MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun, a.jam_masuk, a.jam_keluar,
       a.status_kehadiran, a.menit_terlambat, fn_hitung_jam_kerja(a.jam_masuk, a.jam_keluar) AS jam_kerja,
       a.jam_lembur, a.status_lembur, a.id_penempatan, kl.nama_perusahaan AS nama_klien, p.lokasi_kerja,
       fn_jadwal_absensi(a.id_penempatan, a.tgl, 'datang') AS jadwal_datang,
       fn_jadwal_absensi(a.id_penempatan, a.tgl, 'pulang') AS jadwal_pulang,
       a.foto_masuk, a.foto_keluar,
       (a.jam_masuk IS NOT NULL AND a.jam_keluar IS NULL
        AND TIMESTAMP(a.tgl, a.jam_masuk) < (NOW() - INTERVAL 16 HOUR)) AS lupa_check_out,
       a.keterangan,
       fn_sumber_jadwal_absensi(a.id_penempatan, a.tgl) AS sumber_jadwal
FROM tbl_absensi a
JOIN tbl_karyawan k ON k.id = a.id_karyawan
LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
LEFT JOIN tbl_penempatan p ON p.id = a.id_penempatan
LEFT JOIN tbl_klien kl ON kl.id = p.id_klien //

DELIMITER ;

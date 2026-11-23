-- ============================================================
-- sql/final/03_function.sql — Function
-- Diekspor dari database db_sji (MySQL 8) setelah Finalisasi Tahap 1–10. DEFINER dihapus agar bisa diimpor
-- di komputer lain. Urutan impor: lihat sql/README.md.
-- ============================================================

DELIMITER //

DROP FUNCTION IF EXISTS `fn_hari_kerja_kontrak` //
CREATE FUNCTION `fn_hari_kerja_kontrak`(p_id_karyawan INT, p_awal DATE, p_akhir DATE) RETURNS int
    READS SQL DATA
BEGIN
  -- Hari kerja per bulan dari kontrak penempatan terbaru dalam periode (sama dengan sp_generate_payroll_bulanan).
  RETURN (
    SELECT c.hari_kerja_per_bulan
    FROM tbl_kontrak c JOIN tbl_penempatan p ON c.id_penempatan = p.id
    WHERE p.id_karyawan = p_id_karyawan
      AND p.tgl_mulai <= p_akhir
      AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= p_awal)
    ORDER BY p.tgl_mulai DESC, p.id DESC, c.id DESC
    LIMIT 1
  );
END //

DROP FUNCTION IF EXISTS `fn_hari_kerja_roster` //
CREATE FUNCTION `fn_hari_kerja_roster`(p_id_karyawan INT, p_awal DATE, p_akhir DATE) RETURNS int
    READS SQL DATA
BEGIN
  RETURN fn_hari_kerja_roster_klien(p_id_karyawan, NULL, p_awal, p_akhir);
END //

DROP FUNCTION IF EXISTS `fn_hari_kerja_roster_klien` //
CREATE FUNCTION `fn_hari_kerja_roster_klien`(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE) RETURNS int
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

DROP FUNCTION IF EXISTS `fn_hari_penempatan` //
CREATE FUNCTION `fn_hari_penempatan`(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE) RETURNS int
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

DROP FUNCTION IF EXISTS `fn_hari_tanpa_jadwal` //
CREATE FUNCTION `fn_hari_tanpa_jadwal`(p_id_karyawan INT, p_awal DATE, p_akhir DATE) RETURNS int
    READS SQL DATA
BEGIN
  RETURN fn_hari_tanpa_jadwal_klien(p_id_karyawan, NULL, p_awal, p_akhir);
END //

DROP FUNCTION IF EXISTS `fn_hari_tanpa_jadwal_klien` //
CREATE FUNCTION `fn_hari_tanpa_jadwal_klien`(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE) RETURNS int
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

DROP FUNCTION IF EXISTS `fn_hari_wajib_roster` //
CREATE FUNCTION `fn_hari_wajib_roster`(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE, p_hari_kerja INT) RETURNS int
    READS SQL DATA
BEGIN
  -- Hari Kerja di roster + hari tanpa jadwal yang diprorata dengan hari kerja kontrak (rumus lama).
  RETURN fn_hari_kerja_roster_klien(p_id_karyawan, p_id_klien, p_awal, p_akhir)
       + ROUND(COALESCE(p_hari_kerja, 0) * fn_hari_tanpa_jadwal_klien(p_id_karyawan, p_id_klien, p_awal, p_akhir) / DAY(LAST_DAY(p_awal)));
END //

DROP FUNCTION IF EXISTS `fn_hitung_jam_kerja` //
CREATE FUNCTION `fn_hitung_jam_kerja`(p_jam_masuk TIME, p_jam_keluar TIME) RETURNS int
    DETERMINISTIC
BEGIN
  RETURN FLOOR(MOD(TIME_TO_SEC(p_jam_keluar) - TIME_TO_SEC(p_jam_masuk) + 86400, 86400) / 3600);
END //

DROP FUNCTION IF EXISTS `fn_hitung_lembur` //
CREATE FUNCTION `fn_hitung_lembur`(p_jam_lembur INT) RETURNS decimal(12,2)
    DETERMINISTIC
BEGIN
  RETURN p_jam_lembur * 25000;
END //

DROP FUNCTION IF EXISTS `fn_hitung_potongan_absen` //
CREATE FUNCTION `fn_hitung_potongan_absen`(
  p_gaji_pokok DECIMAL(12,2), p_hari_kerja INT, p_jumlah_absen INT) RETURNS decimal(12,2)
    DETERMINISTIC
BEGIN
  RETURN ROUND((p_gaji_pokok / p_hari_kerja) * p_jumlah_absen, 2);
END //

DROP FUNCTION IF EXISTS `fn_id_absensi_aktif` //
CREATE FUNCTION `fn_id_absensi_aktif`(p_id_karyawan INT) RETURNS int
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

DROP FUNCTION IF EXISTS `fn_jadwal_absensi` //
CREATE FUNCTION `fn_jadwal_absensi`(p_id_penempatan INT, p_tgl DATE, p_jenis VARCHAR(6)) RETURNS time
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

DROP FUNCTION IF EXISTS `fn_menit_terlambat` //
CREATE FUNCTION `fn_menit_terlambat`(p_jadwal TIME, p_jam_masuk TIME) RETURNS int
    DETERMINISTIC
BEGIN
  IF p_jadwal IS NULL OR p_jam_masuk IS NULL THEN
    RETURN NULL;
  END IF;
  RETURN FLOOR((MOD(TIME_TO_SEC(p_jam_masuk) - TIME_TO_SEC(p_jadwal) + 86400 + 43200, 86400) - 43200) / 60);
END //

DROP FUNCTION IF EXISTS `fn_pph21_pasal17` //
CREATE FUNCTION `fn_pph21_pasal17`(p_pkp DECIMAL(15,2)) RETURNS decimal(15,2)
    DETERMINISTIC
BEGIN
  DECLARE v_pkp DECIMAL(15,2) DEFAULT GREATEST(0, p_pkp);
  RETURN ROUND(
      LEAST(v_pkp, 60000000) * 0.05
    + GREATEST(LEAST(v_pkp, 250000000) - 60000000, 0) * 0.15
    + GREATEST(LEAST(v_pkp, 500000000) - 250000000, 0) * 0.25
    + GREATEST(LEAST(v_pkp, 5000000000) - 500000000, 0) * 0.30
    + GREATEST(v_pkp - 5000000000, 0) * 0.35, 0);
END //

DROP FUNCTION IF EXISTS `fn_profit_klien` //
CREATE FUNCTION `fn_profit_klien`(p_id_klien INT, p_bulan INT, p_tahun INT) RETURNS decimal(12,2)
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

DROP FUNCTION IF EXISTS `fn_ptkp_tahunan` //
CREATE FUNCTION `fn_ptkp_tahunan`(p_id_karyawan INT) RETURNS decimal(15,2)
    READS SQL DATA
BEGIN
  RETURN IF(
    (SELECT status_pernikahan = 'Menikah' AND jenis_kelamin = 'L' FROM tbl_karyawan WHERE id = p_id_karyawan) = 1,
    58500000, 54000000);
END //

DROP FUNCTION IF EXISTS `fn_sisa_hari_dokumen` //
CREATE FUNCTION `fn_sisa_hari_dokumen`(p_tgl_kadaluarsa DATE) RETURNS int
    DETERMINISTIC
BEGIN
  RETURN GREATEST(0, DATEDIFF(p_tgl_kadaluarsa, CURDATE()));
END //

DROP FUNCTION IF EXISTS `fn_status_roster` //
CREATE FUNCTION `fn_status_roster`(p_id_karyawan INT, p_tgl DATE) RETURNS varchar(5) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
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

DROP FUNCTION IF EXISTS `fn_sumber_jadwal_absensi` //
CREATE FUNCTION `fn_sumber_jadwal_absensi`(p_id_penempatan INT, p_tgl DATE) RETURNS varchar(15) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
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

DROP FUNCTION IF EXISTS `fn_tarif_ter_a` //
CREATE FUNCTION `fn_tarif_ter_a`(p_bruto DECIMAL(15,2)) RETURNS decimal(5,2)
    DETERMINISTIC
BEGIN
  RETURN CASE
    WHEN p_bruto <= 5400000 THEN 0
    WHEN p_bruto <= 5650000 THEN 0.25
    WHEN p_bruto <= 5950000 THEN 0.5
    WHEN p_bruto <= 6300000 THEN 0.75
    WHEN p_bruto <= 6750000 THEN 1
    WHEN p_bruto <= 7500000 THEN 1.25
    WHEN p_bruto <= 8550000 THEN 1.5
    WHEN p_bruto <= 9650000 THEN 1.75
    WHEN p_bruto <= 10050000 THEN 2
    WHEN p_bruto <= 10350000 THEN 2.25
    WHEN p_bruto <= 10700000 THEN 2.5
    WHEN p_bruto <= 11050000 THEN 3
    WHEN p_bruto <= 11600000 THEN 3.5
    WHEN p_bruto <= 12500000 THEN 4
    WHEN p_bruto <= 13750000 THEN 5
    WHEN p_bruto <= 15100000 THEN 6
    WHEN p_bruto <= 16950000 THEN 7
    WHEN p_bruto <= 19750000 THEN 8
    WHEN p_bruto <= 24150000 THEN 9
    WHEN p_bruto <= 26450000 THEN 10
    WHEN p_bruto <= 28000000 THEN 11
    WHEN p_bruto <= 30050000 THEN 12
    WHEN p_bruto <= 32400000 THEN 13
    WHEN p_bruto <= 35400000 THEN 14
    WHEN p_bruto <= 39100000 THEN 15
    WHEN p_bruto <= 43850000 THEN 16
    WHEN p_bruto <= 47800000 THEN 17
    WHEN p_bruto <= 51400000 THEN 18
    WHEN p_bruto <= 56300000 THEN 19
    WHEN p_bruto <= 62200000 THEN 20
    WHEN p_bruto <= 68600000 THEN 21
    WHEN p_bruto <= 77500000 THEN 22
    WHEN p_bruto <= 89000000 THEN 23
    WHEN p_bruto <= 103000000 THEN 24
    WHEN p_bruto <= 125000000 THEN 25
    WHEN p_bruto <= 157000000 THEN 26
    WHEN p_bruto <= 206000000 THEN 27
    WHEN p_bruto <= 337000000 THEN 28
    WHEN p_bruto <= 454000000 THEN 29
    WHEN p_bruto <= 550000000 THEN 30
    WHEN p_bruto <= 695000000 THEN 31
    WHEN p_bruto <= 910000000 THEN 32
    WHEN p_bruto <= 1400000000 THEN 33
    ELSE 34
  END;
END //

DROP FUNCTION IF EXISTS `fn_total_hari_klien` //
CREATE FUNCTION `fn_total_hari_klien`(p_id_karyawan INT, p_awal DATE, p_akhir DATE) RETURNS int
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

DROP FUNCTION IF EXISTS `fn_total_hari_wajib_roster` //
CREATE FUNCTION `fn_total_hari_wajib_roster`(p_id_karyawan INT, p_awal DATE, p_akhir DATE, p_hari_kerja INT) RETURNS int
    READS SQL DATA
BEGIN
  -- Jumlah hari wajib roster per klien (pembagi porsi invoice; jumlah porsi semua klien = 1).
  RETURN (
    SELECT COALESCE(SUM(fn_hari_wajib_roster(p_id_karyawan, x.id_klien, p_awal, p_akhir, p_hari_kerja)), 0)
    FROM (SELECT DISTINCT id_klien FROM tbl_penempatan
          WHERE id_karyawan = p_id_karyawan
            AND tgl_mulai <= p_akhir
            AND (tgl_selesai IS NULL OR tgl_selesai >= p_awal)) x
  );
END //

DROP FUNCTION IF EXISTS `fn_total_piutang` //
CREATE FUNCTION `fn_total_piutang`() RETURNS decimal(12,2)
    READS SQL DATA
BEGIN
  RETURN (SELECT COALESCE(SUM(total_tagihan), 0) FROM tbl_invoice WHERE status = 'Belum Lunas');
END //

DELIMITER ;

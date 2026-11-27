-- ============================================================
-- sql/final/04_procedure.sql — Stored procedure
-- Diekspor dari database db_sji (MySQL 8) setelah migration terakhir (sql/Komponen/24). DEFINER dihapus agar bisa
-- diimpor di komputer lain. Urutan impor: lihat sql/README.md.
-- ============================================================

-- Charset koneksi saat impor: klien mysql di Windows memakai cp850 bila tidak diatur.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //

DROP PROCEDURE IF EXISTS `sp_assign_penempatan` //
CREATE PROCEDURE `sp_assign_penempatan`(
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

DROP PROCEDURE IF EXISTS `sp_cek_jadwal_kerja` //
CREATE PROCEDURE `sp_cek_jadwal_kerja`(IN p_id INT, IN p_id_penempatan INT, IN p_tanggal DATE, IN p_status VARCHAR(5),
                                     IN p_jam_masuk TIME, IN p_jam_pulang TIME, IN p_id_pengganti INT)
BEGIN
  DECLARE v_id_karyawan INT;
  DECLARE v_mulai DATE;
  DECLARE v_selesai DATE;
  DECLARE v_layanan INT;
  DECLARE v_pekerja INT;
  DECLARE v_awal DATETIME;
  DECLARE v_akhir DATETIME;
  DECLARE v_bentrok DATE;
  DECLARE v_pesan VARCHAR(255);

  SELECT p.id_karyawan, p.tgl_mulai, p.tgl_selesai, k.id_jenis_layanan INTO v_id_karyawan, v_mulai, v_selesai, v_layanan
  FROM tbl_penempatan p JOIN tbl_karyawan k ON k.id = p.id_karyawan
  WHERE p.id = p_id_penempatan;

  IF v_id_karyawan IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan tidak ditemukan.';
  END IF;
  IF p_tanggal < v_mulai OR (v_selesai IS NOT NULL AND p_tanggal > v_selesai) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal jadwal harus berada dalam masa penempatan.';
  END IF;

  -- Pengganti (backup shift): jenis layanan sama, penempatan aktif pada tanggal itu, dan tidak sedang bekerja
  -- di shift lain pada tanggal yang sama (satu karyawan hanya punya satu absensi per tanggal).
  IF p_id_pengganti IS NOT NULL THEN
    IF p_id_pengganti = v_id_karyawan THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengganti shift tidak boleh karyawan pemilik jadwal.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM tbl_karyawan WHERE id = p_id_pengganti AND id_jenis_layanan = v_layanan) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengganti shift harus memiliki jabatan/jenis layanan yang sama.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM tbl_penempatan p
                   WHERE p.id_karyawan = p_id_pengganti AND p.status = 'Aktif'
                     AND p.tgl_mulai <= p_tanggal AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= p_tanggal)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengganti shift tidak memiliki penempatan aktif pada tanggal tersebut.';
    END IF;
  END IF;

  SET v_pekerja = COALESCE(p_id_pengganti, v_id_karyawan);

  IF p_status = 'Kerja' THEN
    IF p_jam_masuk IS NULL OR p_jam_pulang IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pilih jam tersimpan atau isi jam masuk dan jam pulang terlebih dahulu.';
    END IF;

    -- Shift backup tidak boleh berbagi tanggal dengan shift Kerja lain milik pekerja yang sama.
    IF EXISTS (SELECT 1 FROM tbl_jadwal_kerja j JOIN tbl_penempatan p ON p.id = j.id_penempatan
               WHERE COALESCE(j.id_karyawan_pengganti, p.id_karyawan) = v_pekerja
                 AND (p_id IS NULL OR j.id <> p_id)
                 AND j.status = 'Kerja' AND j.tanggal_kerja = p_tanggal
                 AND (j.id_karyawan_pengganti IS NOT NULL OR p_id_pengganti IS NOT NULL)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Karyawan sudah memiliki shift Kerja (termasuk backup shift) pada tanggal tersebut.';
    END IF;

    -- Shift lewat tengah malam (jam pulang <= jam masuk) berakhir keesokan harinya, jadi jadwal H-1 dan H+1 ikut diperiksa.
    SET v_awal = TIMESTAMP(p_tanggal, p_jam_masuk);
    SET v_akhir = TIMESTAMP(IF(p_jam_pulang <= p_jam_masuk, p_tanggal + INTERVAL 1 DAY, p_tanggal), p_jam_pulang);

    SELECT j.tanggal_kerja INTO v_bentrok
    FROM tbl_jadwal_kerja j
    JOIN tbl_penempatan p ON p.id = j.id_penempatan
    WHERE COALESCE(j.id_karyawan_pengganti, p.id_karyawan) = v_pekerja
      AND NOT (j.id_penempatan = p_id_penempatan AND j.id_karyawan_pengganti IS NULL AND p_id_pengganti IS NULL)
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

DROP PROCEDURE IF EXISTS `sp_cek_koreksi_check_out` //
CREATE PROCEDURE `sp_cek_koreksi_check_out`(IN p_id_absensi INT, IN p_jam_keluar TIME)
BEGIN
  DECLARE v_masuk TIME;
  DECLARE v_pulang TIME;
  DECLARE v_durasi INT;
  DECLARE v_sampai_pulang INT;
  DECLARE v_batas INT;
  DECLARE v_pesan VARCHAR(255);

  SET v_masuk = (SELECT jam_masuk FROM tbl_absensi WHERE id = p_id_absensi);
  IF v_masuk IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Absensi ini tidak memiliki jam check-in sehingga jam keluarnya tidak dapat dikoreksi.';
  END IF;
  IF p_jam_keluar IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jam keluar koreksi wajib diisi.';
  END IF;

  -- Durasi kerja memakai putaran 24 jam yang sama dengan trg_auto_hitung_lembur (shift lewat tengah malam).
  SET v_durasi = FLOOR(MOD(TIME_TO_SEC(p_jam_keluar) - TIME_TO_SEC(v_masuk) + 86400, 86400) / 60);
  SET v_pulang = (SELECT fn_jadwal_absensi(id_penempatan, tgl, 'pulang') FROM tbl_absensi WHERE id = p_id_absensi);

  IF v_pulang IS NULL THEN
    -- Tanpa jadwal: sama dengan jendela sesi aktif fn_id_absensi_aktif (16 jam).
    SET v_batas = 960;
  ELSE
    SET v_sampai_pulang = FLOOR(MOD(TIME_TO_SEC(v_pulang) - TIME_TO_SEC(v_masuk) + 86400, 86400) / 60);
    -- Check-in setelah jadwal pulang (data lama) tidak dianggap punya sisa jam kerja.
    IF v_sampai_pulang > 960 THEN
      SET v_sampai_pulang = 0;
    END IF;
    -- Maksimal 4 jam lembur per hari setelah jadwal pulang.
    SET v_batas = v_sampai_pulang + 240;
  END IF;

  IF v_durasi < 30 OR v_durasi > v_batas THEN
    SET v_pesan = CONCAT('Jam keluar harus antara ',
                         TIME_FORMAT(SEC_TO_TIME(MOD(TIME_TO_SEC(v_masuk) + 1800, 86400)), '%H:%i'), ' dan ',
                         TIME_FORMAT(SEC_TO_TIME(MOD(TIME_TO_SEC(v_masuk) + v_batas * 60, 86400)), '%H:%i'),
                         IF(v_pulang IS NULL,
                            ' (minimal 30 menit, maksimal 16 jam setelah check-in).',
                            CONCAT(' (minimal 30 menit setelah check-in, maksimal 4 jam setelah jadwal pulang ',
                                   TIME_FORMAT(v_pulang, '%H:%i'), ').')));
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_pesan;
  END IF;
END //

DROP PROCEDURE IF EXISTS `sp_cek_penempatan_absensi` //
CREATE PROCEDURE `sp_cek_penempatan_absensi`(IN p_id_karyawan INT, IN p_id_penempatan INT, IN p_tgl DATE)
BEGIN
  DECLARE v_pemilik INT DEFAULT NULL;

  IF p_id_penempatan IS NOT NULL THEN
    SET v_pemilik = (SELECT id_karyawan FROM tbl_penempatan WHERE id = p_id_penempatan);

    IF v_pemilik = p_id_karyawan THEN
      IF EXISTS (SELECT 1 FROM tbl_jadwal_kerja
                 WHERE id_penempatan = p_id_penempatan AND tanggal_kerja = p_tgl AND id_karyawan_pengganti IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shift pada tanggal ini sudah digantikan rekan (backup shift), absensi tidak dapat dicatat.';
      END IF;
    ELSEIF NOT EXISTS (SELECT 1 FROM tbl_jadwal_kerja
                       WHERE id_penempatan = p_id_penempatan AND tanggal_kerja = p_tgl AND id_karyawan_pengganti = p_id_karyawan) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan pada absensi bukan milik karyawan ini dan bukan shift backup yang ditugaskan kepadanya.';
    END IF;
  END IF;
END //

DROP PROCEDURE IF EXISTS `sp_cek_pertukaran_shift` //
CREATE PROCEDURE `sp_cek_pertukaran_shift`(IN p_id_jadwal INT, IN p_id_pengaju INT, IN p_id_rekan INT, IN p_tanggal DATE)
BEGIN
  DECLARE v_pemilik INT DEFAULT NULL;
  DECLARE v_tanggal DATE DEFAULT NULL;
  DECLARE v_layanan INT DEFAULT NULL;

  SELECT p.id_karyawan, j.tanggal_kerja INTO v_pemilik, v_tanggal
  FROM tbl_jadwal_kerja j JOIN tbl_penempatan p ON p.id = j.id_penempatan
  WHERE j.id = p_id_jadwal;

  IF v_pemilik IS NULL OR v_pemilik <> p_id_pengaju THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jadwal yang diajukan bukan milik karyawan pengaju.';
  END IF;
  IF v_tanggal <> p_tanggal THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal kerja pengajuan tidak sesuai dengan tanggal jadwal.';
  END IF;

  SET v_layanan = (SELECT id_jenis_layanan FROM tbl_karyawan WHERE id = p_id_pengaju);
  IF v_layanan IS NULL OR NOT EXISTS (SELECT 1 FROM tbl_karyawan WHERE id = p_id_rekan AND id_jenis_layanan = v_layanan) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rekan pengganti harus memiliki jabatan/jenis layanan yang sama dengan pengaju.';
  END IF;
END //

DROP PROCEDURE IF EXISTS `sp_generate_invoice_klien` //
CREATE PROCEDURE `sp_generate_invoice_klien`(IN p_id_klien INT, IN p_bulan INT, IN p_tahun INT)
BEGIN
  DECLARE v_awal DATE;
  DECLARE v_akhir DATE;
  DECLARE v_persen DECIMAL(5,2);
  DECLARE v_bpjs_metode VARCHAR(10);
  DECLARE v_bpjs_nilai DECIMAL(12,2);
  DECLARE v_id_invoice INT;
  DECLARE v_jumlah INT;
  DECLARE v_sub_total DECIMAL(14,2);
  DECLARE v_fee DECIMAL(14,2);
  DECLARE v_ppn DECIMAL(14,2);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

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

  SELECT management_fee, bpjs_metode, bpjs_nilai INTO v_persen, v_bpjs_metode, v_bpjs_nilai
  FROM tbl_klien WHERE id = p_id_klien;

  IF v_persen IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Management fee klien belum diatur pada data klien (Operasional). Isi terlebih dahulu, 0% pun boleh';
  END IF;
  IF NOT (v_bpjs_metode <=> 'Persentase') THEN
    SET v_bpjs_metode = NULL;
    SET v_bpjs_nilai = NULL;
  END IF;

  SET v_awal = CONCAT(p_tahun, '-', LPAD(p_bulan, 2, '0'), '-01');
  SET v_akhir = LAST_DAY(v_awal);

  IF NOT EXISTS (SELECT 1 FROM tbl_penempatan
                 WHERE id_klien = p_id_klien AND tgl_mulai <= v_akhir
                   AND (tgl_selesai IS NULL OR tgl_selesai >= v_awal)) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Tidak ada tenaga yang ditempatkan di klien ini pada periode tersebut';
  END IF;

  START TRANSACTION;

  INSERT INTO tbl_invoice
    (nomor_invoice, id_klien, periode_bulan, periode_tahun, jumlah_tenaga,
     bpjs_metode, bpjs_nilai, biaya_bpjs, sub_total, persen_fee, management_fee, ppn, total_tagihan)
  VALUES
    -- LPAD minimal 3 digit tanpa memotong id klien >= 1000 (LPAD(1101, 3) = '110' akan bentrok dengan klien 1102).
    (CONCAT('INV-', p_tahun, '-', LPAD(p_bulan, 2, '0'), '-', LPAD(p_id_klien, GREATEST(3, CHAR_LENGTH(p_id_klien)), '0')),
     p_id_klien, p_bulan, p_tahun, 0, v_bpjs_metode, v_bpjs_nilai, 0, 0, v_persen, 0, 0, 0);
  SET v_id_invoice = LAST_INSERT_ID();

  INSERT INTO tbl_invoice_detail
    (id_invoice, id_payroll, id_karyawan, nip, nama, jabatan, hari_klien, hari_total,
     gaji_pokok, tunjangan, upah_backup, upah_lembur, potongan_absen, biaya_tenaga_kerja,
     potongan_pinjaman, potongan_bpjs, pph21, total_penerimaan)
  SELECT v_id_invoice, d.id_payroll, d.id_karyawan, d.nip, d.nama, d.jabatan, d.hari_klien, d.hari_total,
         d.gaji_pokok, d.tunjangan, d.upah_backup, d.upah_lembur, d.potongan_absen,
         d.gaji_pokok + d.tunjangan + d.upah_backup + d.upah_lembur - d.potongan_absen,
         d.potongan_pinjaman, d.potongan_bpjs, d.pph21,
         d.gaji_pokok + d.tunjangan + d.upah_backup + d.upah_lembur - d.potongan_absen
           - d.potongan_pinjaman - d.potongan_bpjs - d.pph21
  FROM (
    SELECT py.id AS id_payroll, py.id_karyawan, k.nip, k.nama,
           COALESCE(jl.nama_layanan, k.kategori) AS jabatan,
           h.hari_klien, h.hari_total,
           ROUND(py.gaji_pokok * h.hari_klien / h.hari_total, 0) AS gaji_pokok,
           ROUND(py.komponen_tunjangan * h.hari_klien / h.hari_total, 0) AS tunjangan,
           ROUND(py.upah_backup * h.hari_klien / h.hari_total, 0) AS upah_backup,
           ROUND(py.total_lembur * h.hari_klien / h.hari_total, 0) AS upah_lembur,
           ROUND(py.potongan_absen * h.hari_klien / h.hari_total, 0) AS potongan_absen,
           ROUND(py.potongan_pinjaman * h.hari_klien / h.hari_total, 0) AS potongan_pinjaman,
           ROUND(py.potongan_bpjs * h.hari_klien / h.hari_total, 0) AS potongan_bpjs,
           ROUND(py.pph21 * h.hari_klien / h.hari_total, 0) AS pph21
    FROM (
      -- Porsi klien = hari wajib roster di klien / total hari wajib roster (sama dengan payroll).
      -- Karyawan tanpa roster sama sekali di periode ini, atau roster sebulan penuh Libur (total 0),
      -- tetap memakai porsi hari kalender penempatan (rumus lama) agar tidak terdistorsi pembulatan.
      SELECT r.id_karyawan,
             IF(r.ada_jadwal AND r.roster_total > 0, r.roster_klien, fn_hari_penempatan(r.id_karyawan, p_id_klien, v_awal, v_akhir)) AS hari_klien,
             IF(r.ada_jadwal AND r.roster_total > 0, r.roster_total, fn_total_hari_klien(r.id_karyawan, v_awal, v_akhir)) AS hari_total
      FROM (
        SELECT t.id_karyawan,
               EXISTS (SELECT 1 FROM tbl_jadwal_kerja j JOIN tbl_penempatan p ON p.id = j.id_penempatan
                       WHERE p.id_karyawan = t.id_karyawan AND j.tanggal_kerja BETWEEN v_awal AND v_akhir) AS ada_jadwal,
               fn_hari_wajib_roster(t.id_karyawan, p_id_klien, v_awal, v_akhir, fn_hari_kerja_kontrak(t.id_karyawan, v_awal, v_akhir)) AS roster_klien,
               fn_total_hari_wajib_roster(t.id_karyawan, v_awal, v_akhir, fn_hari_kerja_kontrak(t.id_karyawan, v_awal, v_akhir)) AS roster_total
        FROM (SELECT DISTINCT id_karyawan FROM tbl_penempatan
            WHERE id_klien = p_id_klien AND tgl_mulai <= v_akhir
              AND (tgl_selesai IS NULL OR tgl_selesai >= v_awal)) t
      ) r
    ) h
    JOIN tbl_payroll py ON py.id_karyawan = h.id_karyawan
                       AND py.periode_bulan = p_bulan AND py.periode_tahun = p_tahun
    JOIN tbl_karyawan k ON k.id = py.id_karyawan
    LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
    WHERE h.hari_klien > 0 AND h.hari_total > 0
  ) d;

  SELECT COUNT(*), COALESCE(SUM(biaya_tenaga_kerja), 0) INTO v_jumlah, v_sub_total
  FROM tbl_invoice_detail WHERE id_invoice = v_id_invoice;

  IF v_jumlah = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Generate payroll periode ini terlebih dahulu: invoice dihitung dari data payroll karyawan klien';
  END IF;

  SET v_fee = ROUND(v_sub_total * v_persen / 100, 0);
  SET v_ppn = ROUND(v_fee * 11 / 100, 0);

  UPDATE tbl_invoice
  SET jumlah_tenaga = v_jumlah, sub_total = v_sub_total, management_fee = v_fee, ppn = v_ppn,
      total_tagihan = v_sub_total + v_fee + v_ppn
  WHERE id = v_id_invoice;

  COMMIT;
END //

DROP PROCEDURE IF EXISTS `sp_generate_payroll_bulanan` //
CREATE PROCEDURE `sp_generate_payroll_bulanan`(IN p_bulan INT, IN p_tahun INT)
BEGIN
  DECLARE v_done INT DEFAULT 0;
  DECLARE v_id INT;
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_awal DATE;
  DECLARE v_akhir DATE;
  DECLARE v_hari_bulan INT;
  DECLARE v_gaji_kontrak DECIMAL(12,2);
  DECLARE v_hari_kerja INT;
  DECLARE v_hari_libur_kerja INT;
  DECLARE v_tarif DECIMAL(16,6);
  DECLARE v_hari_wajib INT;
  DECLARE v_hari_masuk INT;
  DECLARE v_hari_absen INT;
  DECLARE v_gaji DECIMAL(12,2);
  DECLARE v_potongan DECIMAL(12,2);
  DECLARE v_kelipatan_lembur DECIMAL(10,2);
  DECLARE v_lembur DECIMAL(12,2);
  DECLARE v_backup DECIMAL(12,2);
  DECLARE v_tunjangan DECIMAL(12,2);
  DECLARE v_pinjaman DECIMAL(12,2);
  DECLARE v_ada INT;
  DECLARE v_id_klien INT;
  DECLARE v_bpjs_metode VARCHAR(10);
  DECLARE v_bpjs_nilai DECIMAL(12,2);
  DECLARE v_potongan_bpjs DECIMAL(12,2);
  DECLARE v_bruto DECIMAL(15,2);
  DECLARE v_bruto_setahun DECIMAL(15,2);
  DECLARE v_pph_sebelumnya DECIMAL(15,2);
  DECLARE v_jumlah_bulan INT;
  DECLARE v_biaya_jabatan DECIMAL(15,2);
  DECLARE v_pkp DECIMAL(15,2);
  DECLARE v_pph21 DECIMAL(12,2);
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
    SET v_tunjangan = 0;
    SET v_pinjaman = 0;
    SET v_backup = 0;
    SET v_id_klien = NULL;
    SET v_bpjs_metode = NULL;
    SET v_bpjs_nilai = NULL;
    SET v_potongan_bpjs = 0;
    SET v_pph21 = 0;

    -- Payroll yang sudah ada (apa pun statusnya) dilewati: nominal & status bayar tidak ditimpa.
    SELECT COUNT(*) INTO v_ada FROM tbl_payroll
    WHERE id_karyawan = v_id AND periode_bulan = p_bulan AND periode_tahun = p_tahun;

    IF v_ada = 0 THEN
      IF v_kategori = 'Lapangan' THEN
        SET v_gaji_kontrak = NULL;
        SET v_hari_kerja = NULL;
        BEGIN
          DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_gaji_kontrak = NULL;
          SELECT c.gaji_pokok, c.hari_kerja_per_bulan, p.id_klien
          INTO v_gaji_kontrak, v_hari_kerja, v_id_klien
          FROM tbl_kontrak c JOIN tbl_penempatan p ON c.id_penempatan = p.id
          WHERE p.id_karyawan = v_id
            AND p.tgl_mulai <= v_akhir
            AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= v_awal)
          ORDER BY p.tgl_mulai DESC, p.id DESC, c.id DESC
          LIMIT 1;
        END;

        -- Gaji pokok sebulan diinput Keuangan di Komponen Gaji (dari sekretaris); nilai terakhir yang berlaku
        -- sampai periode ini dipakai. Bila belum pernah diinput, tetap memakai gaji pada kontrak penempatan.
        IF v_gaji_kontrak IS NOT NULL THEN
          SET v_gaji_kontrak = COALESCE(fn_gaji_pokok_berlaku(v_id, p_bulan, p_tahun), v_gaji_kontrak);
        END IF;

        -- Penempatan tanpa kontrak: dilewati (tidak menghentikan karyawan lain).
        IF v_gaji_kontrak IS NOT NULL AND v_hari_kerja > 0 THEN
          SET v_tarif = v_gaji_kontrak / v_hari_kerja;
          -- Hari wajib = hari Kerja di roster + hari tanpa jadwal (prorata kontrak seperti rumus lama).
          SET v_hari_wajib = fn_hari_wajib_roster(v_id, NULL, v_awal, v_akhir, v_hari_kerja);
          -- Absensi pada tanggal Libur di roster tidak dihitung hari masuk biasa (dibayar lewat upah_backup).
          -- Sakit dan Cuti (cuti berbayar yang disetujui HRD) dihitung hari masuk, sehingga tidak dipotong.
          SET v_hari_masuk = (SELECT COUNT(*) FROM tbl_absensi a
                              WHERE a.id_karyawan = v_id AND a.tgl BETWEEN v_awal AND v_akhir
                                AND a.status_kehadiran IN ('Hadir', 'Terlambat', 'Sakit', 'Cuti')
                                AND NOT (fn_status_roster(v_id, a.tgl) <=> 'Libur'));
          SET v_hari_libur_kerja = (SELECT COUNT(*) FROM tbl_absensi a
                                    WHERE a.id_karyawan = v_id AND a.tgl BETWEEN v_awal AND v_akhir
                                      AND a.status_kehadiran IN ('Hadir', 'Terlambat')
                                      AND fn_status_roster(v_id, a.tgl) <=> 'Libur');
          SET v_backup = ROUND(v_tarif * v_hari_libur_kerja, 2);
          SET v_hari_absen = GREATEST(0, v_hari_wajib - v_hari_masuk);
          SET v_gaji = ROUND(v_tarif * v_hari_wajib, 2);
          SET v_potongan = ROUND(v_tarif * v_hari_absen, 2);

          SELECT bpjs_metode, bpjs_nilai INTO v_bpjs_metode, v_bpjs_nilai
          FROM tbl_klien WHERE id = v_id_klien;
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
        -- Lembur per absensi valid: jam pertama 1,5x, berikutnya 2x -> 1,5 + 2 * (jam - 1) = 2 * jam - 0,5.
        SET v_kelipatan_lembur = (SELECT COALESCE(SUM(2 * jam_lembur - 0.5), 0) FROM tbl_absensi
                                  WHERE id_karyawan = v_id AND tgl BETWEEN v_awal AND v_akhir
                                    AND jam_masuk IS NOT NULL AND jam_keluar IS NOT NULL
                                    AND status_kehadiran IN ('Hadir', 'Terlambat')
                                    AND jam_lembur >= 1);
        SET v_lembur = ROUND(v_gaji * v_kelipatan_lembur / 173, 2);

        SELECT COALESCE(MAX(tunjangan), 0), COALESCE(MAX(potongan_pinjaman), 0)
        INTO v_tunjangan, v_pinjaman
        FROM tbl_komponen_gaji
        WHERE id_karyawan = v_id AND periode_bulan = p_bulan AND periode_tahun = p_tahun;

        -- BPJS = persentase klien x gaji pokok; selain persentase tidak dihitung.
        IF v_bpjs_metode = 'Persentase' AND v_bpjs_nilai IS NOT NULL THEN
          SET v_potongan_bpjs = ROUND(v_gaji * v_bpjs_nilai / 100, 2);
        ELSE
          SET v_bpjs_metode = NULL;
          SET v_bpjs_nilai = NULL;
        END IF;

        -- PPh 21
        SET v_bruto = v_gaji + v_tunjangan + v_backup + v_lembur;
        IF p_bulan < 12 THEN
          SET v_pph21 = ROUND(v_bruto * fn_tarif_ter_a(v_bruto) / 100, 0);
        ELSE
          SELECT COALESCE(SUM(gaji_pokok + komponen_tunjangan + upah_backup + total_lembur), 0),
                 COALESCE(SUM(pph21), 0), COUNT(*)
          INTO v_bruto_setahun, v_pph_sebelumnya, v_jumlah_bulan
          FROM tbl_payroll
          WHERE id_karyawan = v_id AND periode_tahun = p_tahun AND periode_bulan < 12;

          SET v_bruto_setahun = v_bruto_setahun + v_bruto;
          SET v_jumlah_bulan = v_jumlah_bulan + 1;
          SET v_biaya_jabatan = LEAST(ROUND(v_bruto_setahun * 0.05, 2), 500000 * v_jumlah_bulan);
          SET v_pkp = GREATEST(0, FLOOR((v_bruto_setahun - v_biaya_jabatan - fn_ptkp_tahunan(v_id)) / 1000) * 1000);
          SET v_pph21 = fn_pph21_pasal17(v_pkp) - v_pph_sebelumnya;
        END IF;

        INSERT INTO tbl_payroll
          (id_karyawan, periode_bulan, periode_tahun, gaji_pokok, total_lembur, potongan_absen,
           komponen_tunjangan, upah_backup, potongan_pinjaman, pph21,
           hari_wajib, hari_masuk, hari_absen, id_klien_bpjs, bpjs_metode, bpjs_nilai, potongan_bpjs, total_gaji)
        VALUES
          (v_id, p_bulan, p_tahun, v_gaji, v_lembur, v_potongan,
           v_tunjangan, v_backup, v_pinjaman, v_pph21,
           v_hari_wajib, v_hari_masuk, v_hari_absen, v_id_klien, v_bpjs_metode, v_bpjs_nilai, v_potongan_bpjs,
           v_gaji + v_tunjangan + v_backup + v_lembur - (v_potongan + v_potongan_bpjs + v_pinjaman + v_pph21));
      END IF;
    END IF;
  END LOOP;
  CLOSE cur;
  COMMIT;
END //

DROP PROCEDURE IF EXISTS `sp_input_ketidakhadiran` //
CREATE PROCEDURE `sp_input_ketidakhadiran`(
  IN p_id_karyawan INT, IN p_tgl DATE, IN p_status VARCHAR(10), IN p_keterangan VARCHAR(255))
BEGIN
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_status_karyawan VARCHAR(20);
  DECLARE v_pen_id INT;
  DECLARE v_id_alpha INT DEFAULT NULL;

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

  -- Alpha tanpa check-in (termasuk Alpha otomatis) boleh diganti keterangan yang sebenarnya (Izin/Sakit).
  SET v_id_alpha = (SELECT id FROM tbl_absensi
                    WHERE id_karyawan = p_id_karyawan AND tgl = p_tgl AND status_kehadiran = 'Alpha' AND jam_masuk IS NULL);
  IF v_id_alpha IS NOT NULL AND p_status <> 'Alpha' THEN
    UPDATE tbl_absensi SET status_kehadiran = p_status, keterangan = NULLIF(TRIM(p_keterangan), '') WHERE id = v_id_alpha;
  ELSE
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
  END IF;
END //

DROP PROCEDURE IF EXISTS `sp_rotasi_karyawan` //
CREATE PROCEDURE `sp_rotasi_karyawan`(
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

DROP PROCEDURE IF EXISTS `sp_selesai_penempatan` //
CREATE PROCEDURE `sp_selesai_penempatan`(IN p_id_penempatan INT, IN p_tgl_selesai DATE)
BEGIN
  DECLARE v_status VARCHAR(10);
  DECLARE v_karyawan INT;
  DECLARE v_tgl_mulai DATE;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  IF p_tgl_selesai IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal selesai penempatan wajib diisi';
  END IF;

  START TRANSACTION;
    BEGIN
      DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_status = NULL;
      SELECT status, id_karyawan, tgl_mulai INTO v_status, v_karyawan, v_tgl_mulai
      FROM tbl_penempatan WHERE id = p_id_penempatan
      FOR UPDATE;
    END;

    IF v_status IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan tidak ditemukan';
    END IF;
    IF v_status <> 'Aktif' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan sudah tidak berstatus Aktif';
    END IF;
    IF p_tgl_selesai < v_tgl_mulai THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal selesai tidak boleh sebelum tanggal mulai penempatan';
    END IF;

    UPDATE tbl_penempatan SET status = 'Selesai', tgl_selesai = p_tgl_selesai
    WHERE id = p_id_penempatan;
    UPDATE tbl_kontrak SET status = 'Selesai'
    WHERE id_penempatan = p_id_penempatan AND status = 'Aktif';
    -- Kembali Tersedia hanya bila tidak ada penempatan Aktif lain untuk karyawan ini.
    UPDATE tbl_karyawan SET status = 'Tersedia'
    WHERE id = v_karyawan
      AND NOT EXISTS (SELECT 1 FROM tbl_penempatan
                      WHERE id_karyawan = v_karyawan AND status = 'Aktif');
  COMMIT;
END //

DROP PROCEDURE IF EXISTS `sp_setujui_pengajuan_ketidakhadiran` //
CREATE PROCEDURE `sp_setujui_pengajuan_ketidakhadiran`(IN p_id INT, IN p_diproses_oleh INT)
BEGIN
  DECLARE v_karyawan INT DEFAULT NULL;
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_jenis VARCHAR(10);
  DECLARE v_mulai DATE;
  DECLARE v_selesai DATE;
  DECLARE v_status VARCHAR(10) DEFAULT NULL;
  DECLARE v_alasan VARCHAR(500);
  DECLARE v_tgl DATE;
  DECLARE v_pen INT;
  DECLARE v_id_alpha INT;
  DECLARE v_jumlah INT DEFAULT 0;
  DECLARE v_pesan VARCHAR(255);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN SET @sji_pengajuan_disetujui = NULL; RESIGNAL; END;

  IF p_diproses_oleh IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pemroses pengajuan wajib diisi.';
  END IF;

  SELECT id_karyawan, jenis, tgl_mulai, tgl_selesai, status, alasan
  INTO v_karyawan, v_jenis, v_mulai, v_selesai, v_status, v_alasan
  FROM tbl_pengajuan_ketidakhadiran WHERE id = p_id
  FOR UPDATE;

  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengajuan ketidakhadiran tidak ditemukan.';
  END IF;
  IF v_status <> 'Menunggu' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengajuan ini sudah diproses sebelumnya dan tidak dapat diproses lagi.';
  END IF;

  SET v_kategori = (SELECT kategori FROM tbl_karyawan WHERE id = v_karyawan);

  -- Tahap periksa: tidak ada baris yang ditulis bila satu tanggal saja bermasalah.
  -- Hari Libur di roster (termasuk jadwal yang digantikan rekan) dan hari tanpa penempatan dilewati.
  -- Alpha tanpa check-in (termasuk Alpha otomatis) bukan halangan: barisnya diganti jenis pengajuan.
  SET v_tgl = v_mulai;
  WHILE v_tgl <= v_selesai DO
    SET v_pen = (SELECT id FROM tbl_penempatan
                 WHERE id_karyawan = v_karyawan AND tgl_mulai <= v_tgl AND (tgl_selesai IS NULL OR tgl_selesai >= v_tgl)
                 ORDER BY tgl_mulai DESC, id DESC LIMIT 1);
    IF NOT (fn_status_roster(v_karyawan, v_tgl) <=> 'Libur') AND (v_pen IS NOT NULL OR v_kategori = 'Internal') THEN
      IF EXISTS (SELECT 1 FROM tbl_absensi WHERE id_karyawan = v_karyawan AND tgl = v_tgl
                 AND NOT (status_kehadiran = 'Alpha' AND jam_masuk IS NULL)) THEN
        SET v_pesan = CONCAT('Karyawan sudah memiliki catatan absensi pada ', DATE_FORMAT(v_tgl, '%d-%m-%Y'),
                             '. Pengajuan tidak dapat disetujui.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_pesan;
      END IF;
      -- Payroll yang sudah dibuat tidak dihitung ulang, jadi absensi periodenya tidak boleh berubah lagi.
      IF EXISTS (SELECT 1 FROM tbl_payroll WHERE id_karyawan = v_karyawan
                 AND periode_bulan = MONTH(v_tgl) AND periode_tahun = YEAR(v_tgl)) THEN
        SET v_pesan = CONCAT('Payroll periode ', DATE_FORMAT(v_tgl, '%m/%Y'),
                             ' karyawan ini sudah dibuat. Pengajuan tidak dapat disetujui.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_pesan;
      END IF;
      SET v_jumlah = v_jumlah + 1;
    END IF;
    SET v_tgl = v_tgl + INTERVAL 1 DAY;
  END WHILE;

  IF v_jumlah = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tidak ada hari kerja dalam rentang pengajuan (semua Libur atau di luar masa penempatan).';
  END IF;

  -- Tahap catat.
  SET v_tgl = v_mulai;
  WHILE v_tgl <= v_selesai DO
    SET v_pen = (SELECT id FROM tbl_penempatan
                 WHERE id_karyawan = v_karyawan AND tgl_mulai <= v_tgl AND (tgl_selesai IS NULL OR tgl_selesai >= v_tgl)
                 ORDER BY tgl_mulai DESC, id DESC LIMIT 1);
    IF NOT (fn_status_roster(v_karyawan, v_tgl) <=> 'Libur') AND (v_pen IS NOT NULL OR v_kategori = 'Internal') THEN
      SET v_id_alpha = (SELECT id FROM tbl_absensi
                        WHERE id_karyawan = v_karyawan AND tgl = v_tgl AND status_kehadiran = 'Alpha' AND jam_masuk IS NULL);
      IF v_id_alpha IS NOT NULL THEN
        UPDATE tbl_absensi
        SET status_kehadiran = v_jenis, keterangan = LEFT(CONCAT('Pengajuan #', p_id, ': ', v_alasan), 255)
        WHERE id = v_id_alpha;
      ELSE
        INSERT INTO tbl_absensi (id_karyawan, id_penempatan, tgl, status_kehadiran, keterangan)
        VALUES (v_karyawan, v_pen, v_tgl, v_jenis, LEFT(CONCAT('Pengajuan #', p_id, ': ', v_alasan), 255));
      END IF;
    END IF;
    SET v_tgl = v_tgl + INTERVAL 1 DAY;
  END WHILE;

  SET @sji_pengajuan_disetujui = p_id;
  UPDATE tbl_pengajuan_ketidakhadiran
  SET status = 'Disetujui', diproses_oleh = p_diproses_oleh, diproses_pada = NOW(), updated_at = NOW()
  WHERE id = p_id;
  SET @sji_pengajuan_disetujui = NULL;
END //

DROP PROCEDURE IF EXISTS `sp_tandai_alpha_otomatis` //
CREATE PROCEDURE `sp_tandai_alpha_otomatis`(IN p_waktu DATETIME)
BEGIN
  IF p_waktu IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Waktu acuan Alpha otomatis wajib diisi.';
  END IF;

  -- Satu pekerja bisa punya lebih dari satu shift Kerja pada tanggal yang sama (penempatan berbeda, jam tidak
  -- bertabrakan). Absensi hanya satu baris per tanggal, jadi Alpha dicatat setelah shift TERAKHIR berakhir
  -- (sampai saat itu ia masih bisa check-in), pada penempatan shift pertamanya.
  INSERT INTO tbl_absensi (id_karyawan, id_penempatan, tgl, status_kehadiran, keterangan)
  SELECT s.id_pekerja,
         CAST(SUBSTRING_INDEX(GROUP_CONCAT(s.id_penempatan ORDER BY s.mulai, s.id_penempatan), ',', 1) AS UNSIGNED),
         s.tanggal_kerja,
         'Alpha',
         CONCAT('Alpha otomatis: tidak check-in sampai shift berakhir pukul ', TIME_FORMAT(MAX(s.selesai), '%H:%i'), '.')
  FROM (
    SELECT COALESCE(j.id_karyawan_pengganti, p.id_karyawan) AS id_pekerja,
           j.id_penempatan,
           j.tanggal_kerja,
           TIMESTAMP(j.tanggal_kerja, j.jam_masuk) AS mulai,
           TIMESTAMP(IF(j.jam_pulang <= j.jam_masuk, j.tanggal_kerja + INTERVAL 1 DAY, j.tanggal_kerja), j.jam_pulang) AS selesai
    FROM tbl_jadwal_kerja j
    JOIN tbl_penempatan p ON p.id = j.id_penempatan
    WHERE j.status = 'Kerja'
      AND j.jam_masuk IS NOT NULL AND j.jam_pulang IS NOT NULL
      AND j.tanggal_kerja BETWEEN DATE(p_waktu) - INTERVAL 8 DAY AND DATE(p_waktu)
      AND j.tanggal_kerja >= p.tgl_mulai
      AND (p.tgl_selesai IS NULL OR j.tanggal_kerja <= p.tgl_selesai)
  ) s
  JOIN tbl_karyawan k ON k.id = s.id_pekerja
  WHERE k.status NOT IN ('Nonaktif', 'Cuti')
    AND NOT EXISTS (SELECT 1 FROM tbl_absensi a WHERE a.id_karyawan = s.id_pekerja AND a.tgl = s.tanggal_kerja)
    AND NOT EXISTS (SELECT 1 FROM tbl_payroll py WHERE py.id_karyawan = s.id_pekerja
                    AND py.periode_bulan = MONTH(s.tanggal_kerja) AND py.periode_tahun = YEAR(s.tanggal_kerja))
  GROUP BY s.id_pekerja, s.tanggal_kerja
  HAVING MAX(s.selesai) <= p_waktu
     AND MAX(s.selesai) > p_waktu - INTERVAL 7 DAY;
END //

DROP PROCEDURE IF EXISTS `sp_update_status_bayar` //
CREATE PROCEDURE `sp_update_status_bayar`(
  IN p_jenis VARCHAR(10), IN p_id INT, IN p_tanggal DATE)
BEGIN
  IF p_jenis = 'INVOICE' THEN
    UPDATE tbl_invoice SET status = 'Lunas', tgl_pembayaran = p_tanggal WHERE id = p_id;
  ELSEIF p_jenis = 'PAYROLL' THEN
    IF NOT EXISTS (SELECT 1 FROM tbl_payroll WHERE id = p_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll tidak ditemukan';
    END IF;
    UPDATE tbl_payroll SET status_bayar = 'Sudah Lunas', tgl_bayar = p_tanggal
    WHERE id = p_id AND status_bayar = 'Belum Dibayar';
    IF ROW_COUNT() = 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll ini sudah dicairkan sebelumnya';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jenis harus INVOICE atau PAYROLL';
  END IF;
END //

DELIMITER ;

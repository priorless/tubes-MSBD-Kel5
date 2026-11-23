-- ============================================================
-- 18_finalisasi_database.sql — Finalisasi database db_sji (MySQL 8)
-- Dijalankan bertahap oleh migration 2026_11_23_* lewat App\Support\SqlKomponen::jalankanBlok().
-- Rollback tiap tahap: 18_finalisasi_database_rollback.sql (blok bernomor sama).
-- ============================================================

-- [TAHAP 1]
-- Hapus tuntas fitur persetujuan lembur. Semua lembur valid langsung dibayar payroll,
-- jadi status_lembur, sp_putuskan_lembur, dan pengisian status di trigger tidak dipakai lagi.
DELIMITER //

DROP PROCEDURE IF EXISTS sp_putuskan_lembur //

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
  END IF;
END //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_absensi_harian AS
SELECT a.id AS id_absensi, a.id_karyawan, k.nip, k.nama, jl.nama_layanan, a.tgl,
       MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun, a.jam_masuk, a.jam_keluar, a.status_kehadiran,
       a.menit_terlambat, fn_hitung_jam_kerja(a.jam_masuk, a.jam_keluar) AS jam_kerja, a.jam_lembur,
       a.id_penempatan, kl.nama_perusahaan AS nama_klien, p.lokasi_kerja,
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

ALTER TABLE tbl_absensi DROP COLUMN status_lembur //

DELIMITER ;

-- [TAHAP 2]
-- Payroll & invoice membaca roster (tbl_jadwal_kerja).
-- - hari_wajib Lapangan = hari Kerja di roster + ROUND(hari_kerja_kontrak x hari tanpa jadwal / hari dalam bulan).
-- - Absensi di tanggal Libur roster tidak mengurangi hari_absen dan tidak dihitung hari masuk; kerja (Hadir/Terlambat)
--   di hari Libur dibayar lewat upah_backup = tarif harian kontrak (gaji / hari_kerja_per_bulan) x jumlah harinya.
-- - Invoice membagi payroll per klien dengan hari wajib roster per klien (tanpa roster: hari kalender seperti dulu). PPh21, BPJS, komponen gaji, lembur, fee, PPN tetap.
DELIMITER //

DROP FUNCTION IF EXISTS fn_hari_kerja_roster_klien //
CREATE FUNCTION fn_hari_kerja_roster_klien(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE)
RETURNS INT
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

DROP FUNCTION IF EXISTS fn_hari_kerja_roster //
CREATE FUNCTION fn_hari_kerja_roster(p_id_karyawan INT, p_awal DATE, p_akhir DATE)
RETURNS INT
READS SQL DATA
BEGIN
  RETURN fn_hari_kerja_roster_klien(p_id_karyawan, NULL, p_awal, p_akhir);
END //

DROP FUNCTION IF EXISTS fn_hari_tanpa_jadwal_klien //
CREATE FUNCTION fn_hari_tanpa_jadwal_klien(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE)
RETURNS INT
READS SQL DATA
BEGIN
  -- Hari dalam masa penempatan yang belum punya baris jadwal (Kerja maupun Libur).
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
    )
  );
END //

DROP FUNCTION IF EXISTS fn_hari_tanpa_jadwal //
CREATE FUNCTION fn_hari_tanpa_jadwal(p_id_karyawan INT, p_awal DATE, p_akhir DATE)
RETURNS INT
READS SQL DATA
BEGIN
  RETURN fn_hari_tanpa_jadwal_klien(p_id_karyawan, NULL, p_awal, p_akhir);
END //

DROP FUNCTION IF EXISTS fn_status_roster //
CREATE FUNCTION fn_status_roster(p_id_karyawan INT, p_tgl DATE)
RETURNS VARCHAR(5) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
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

DROP FUNCTION IF EXISTS fn_hari_kerja_kontrak //
CREATE FUNCTION fn_hari_kerja_kontrak(p_id_karyawan INT, p_awal DATE, p_akhir DATE)
RETURNS INT
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

DROP FUNCTION IF EXISTS fn_hari_wajib_roster //
CREATE FUNCTION fn_hari_wajib_roster(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE, p_hari_kerja INT)
RETURNS INT
READS SQL DATA
BEGIN
  -- Hari Kerja di roster + hari tanpa jadwal yang diprorata dengan hari kerja kontrak (rumus lama).
  RETURN fn_hari_kerja_roster_klien(p_id_karyawan, p_id_klien, p_awal, p_akhir)
       + ROUND(COALESCE(p_hari_kerja, 0) * fn_hari_tanpa_jadwal_klien(p_id_karyawan, p_id_klien, p_awal, p_akhir) / DAY(LAST_DAY(p_awal)));
END //

DROP FUNCTION IF EXISTS fn_total_hari_wajib_roster //
CREATE FUNCTION fn_total_hari_wajib_roster(p_id_karyawan INT, p_awal DATE, p_akhir DATE, p_hari_kerja INT)
RETURNS INT
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

DROP PROCEDURE IF EXISTS sp_generate_payroll_bulanan //
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

        -- Penempatan tanpa kontrak: dilewati (tidak menghentikan karyawan lain).
        IF v_gaji_kontrak IS NOT NULL AND v_hari_kerja > 0 THEN
          SET v_tarif = v_gaji_kontrak / v_hari_kerja;
          -- Hari wajib = hari Kerja di roster + hari tanpa jadwal (prorata kontrak seperti rumus lama).
          SET v_hari_wajib = fn_hari_wajib_roster(v_id, NULL, v_awal, v_akhir, v_hari_kerja);
          -- Absensi pada tanggal Libur di roster tidak dihitung hari masuk biasa (dibayar lewat upah_backup).
          SET v_hari_masuk = (SELECT COUNT(*) FROM tbl_absensi a
                              WHERE a.id_karyawan = v_id AND a.tgl BETWEEN v_awal AND v_akhir
                                AND a.status_kehadiran IN ('Hadir', 'Terlambat', 'Sakit')
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

DROP PROCEDURE IF EXISTS sp_generate_invoice_klien //
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

DELIMITER ;

-- [TAHAP 3]
-- Aturan jadwal kerja di level database (BEFORE INSERT/UPDATE tbl_jadwal_kerja). Pesan sama dengan JadwalKerjaService.
-- Riwayat tidak ditulis di sini: JadwalKerjaService sudah menulis tbl_jadwal_kerja_riwayat.
DELIMITER //

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

    -- Shift lewat tengah malam (jam pulang <= jam masuk) berakhir keesokan harinya, jadi jadwal H-1 dan H+1 ikut diperiksa.
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

DROP TRIGGER IF EXISTS trg_cek_jadwal_kerja_insert //
CREATE TRIGGER trg_cek_jadwal_kerja_insert
BEFORE INSERT ON tbl_jadwal_kerja
FOR EACH ROW
BEGIN
  CALL sp_cek_jadwal_kerja(NULL, NEW.id_penempatan, NEW.tanggal_kerja, NEW.status, NEW.jam_masuk, NEW.jam_pulang);
END //

DROP TRIGGER IF EXISTS trg_cek_jadwal_kerja_update //
CREATE TRIGGER trg_cek_jadwal_kerja_update
BEFORE UPDATE ON tbl_jadwal_kerja
FOR EACH ROW
BEGIN
  CALL sp_cek_jadwal_kerja(OLD.id, NEW.id_penempatan, NEW.tanggal_kerja, NEW.status, NEW.jam_masuk, NEW.jam_pulang);
END //

DELIMITER ;

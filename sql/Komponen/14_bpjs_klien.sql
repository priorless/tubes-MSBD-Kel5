-- ============================================================
-- /sql/komponen/14_bpjs_klien.sql
-- Pengaturan default BPJS per klien (satu BPJS gabungan) yang dipakai payroll dan invoice.
--
-- Kolom (dibuat oleh migrasi 2026_11_18_*_bpjs_klien sebelum blok ini dijalankan):
--   tbl_klien.bpjs_metode ('Persentase' | 'Nominal' | NULL = belum ditentukan / tanpa BPJS), bpjs_nilai.
--   tbl_payroll.id_klien_bpjs, bpjs_metode, bpjs_nilai (snapshot), potongan_bpjs.
--   tbl_invoice.bpjs_metode, bpjs_nilai (snapshot), biaya_bpjs.
--
-- Payroll (karyawan Lapangan; Internal tidak punya klien sehingga tanpa BPJS):
--   pendapatan  = gaji pokok + lembur   (tunjangan & upah backup belum ada di sistem)
--   dasar BPJS  = pendapatan - potongan absen
--   Persentase  : potongan_bpjs = ROUND(dasar * bpjs_nilai / 100, 2)
--   Nominal     : potongan_bpjs = bpjs_nilai per tenaga, maksimal sebesar dasar (gaji tidak negatif)
--   total_gaji  = dasar - potongan_bpjs   (PPh 21 belum ada di sistem)
--   Klien BPJS  = klien penempatan yang kontraknya dipakai payroll (penempatan terbaru pada periode).
--
-- Invoice: biaya_bpjs = jumlah potongan_bpjs payroll periode yang sama untuk klien itu
-- (nilainya identik dengan yang dipotong dari karyawan). total_tagihan = tagihan tenaga + biaya_bpjs.
-- Bila klien memakai BPJS, payroll periode itu wajib digenerate lebih dulu.
--
-- Nilai metode/nilai disalin ke payroll & invoice saat dibuat, sehingga perubahan pengaturan
-- klien tidak mengubah payroll/invoice yang sudah ada. Data lama tidak dihitung ulang.
-- Menggantikan sp_generate_payroll_bulanan (file 11 [TAHAP 6]), sp_generate_invoice_klien dan
-- v_slip_gaji_detail (file 11 [TAHAP 5]). Rollback: file 14_bpjs_klien_rollback.sql.
-- ============================================================

-- [TAHAP 1]
DELIMITER //

DROP PROCEDURE IF EXISTS sp_generate_payroll_bulanan //
CREATE PROCEDURE sp_generate_payroll_bulanan(IN p_bulan INT, IN p_tahun INT)
BEGIN
  DECLARE v_done INT DEFAULT 0;
  DECLARE v_id INT;
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_awal DATE;
  DECLARE v_akhir DATE;
  DECLARE v_hari_bulan INT;
  DECLARE v_gaji_kontrak DECIMAL(12,2);
  DECLARE v_hari_kerja INT;
  DECLARE v_hari_penempatan INT;
  DECLARE v_tarif DECIMAL(16,6);
  DECLARE v_hari_wajib INT;
  DECLARE v_hari_masuk INT;
  DECLARE v_hari_absen INT;
  DECLARE v_gaji DECIMAL(12,2);
  DECLARE v_potongan DECIMAL(12,2);
  DECLARE v_jam_lembur INT;
  DECLARE v_lembur DECIMAL(12,2);
  DECLARE v_ada INT;
  DECLARE v_id_klien INT;
  DECLARE v_bpjs_metode VARCHAR(10);
  DECLARE v_bpjs_nilai DECIMAL(12,2);
  DECLARE v_dasar_bpjs DECIMAL(12,2);
  DECLARE v_potongan_bpjs DECIMAL(12,2);
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
    SET v_id_klien = NULL;
    SET v_bpjs_metode = NULL;
    SET v_bpjs_nilai = NULL;
    SET v_potongan_bpjs = 0;

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
          SET v_hari_penempatan = fn_hari_penempatan(v_id, NULL, v_awal, v_akhir);
          SET v_tarif = v_gaji_kontrak / v_hari_kerja;
          SET v_hari_wajib = ROUND(v_hari_kerja * v_hari_penempatan / v_hari_bulan);
          SET v_hari_masuk = (SELECT COUNT(*) FROM tbl_absensi
                              WHERE id_karyawan = v_id AND tgl BETWEEN v_awal AND v_akhir
                                AND status_kehadiran IN ('Hadir', 'Terlambat', 'Sakit'));
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
        SET v_jam_lembur = (SELECT COALESCE(SUM(jam_lembur), 0) FROM tbl_absensi
                            WHERE id_karyawan = v_id AND tgl BETWEEN v_awal AND v_akhir
                              AND status_lembur = 'Disetujui');
        SET v_lembur = fn_hitung_lembur(v_jam_lembur);

        -- BPJS dipotong setelah potongan absen.
        SET v_dasar_bpjs = GREATEST(0, v_gaji + v_lembur - v_potongan);
        IF v_bpjs_metode = 'Persentase' THEN
          SET v_potongan_bpjs = ROUND(v_dasar_bpjs * v_bpjs_nilai / 100, 2);
        ELSEIF v_bpjs_metode = 'Nominal' THEN
          SET v_potongan_bpjs = LEAST(v_bpjs_nilai, v_dasar_bpjs);
        END IF;

        INSERT INTO tbl_payroll
          (id_karyawan, periode_bulan, periode_tahun, gaji_pokok, total_lembur, potongan_absen,
           hari_wajib, hari_masuk, hari_absen, id_klien_bpjs, bpjs_metode, bpjs_nilai, potongan_bpjs, total_gaji)
        VALUES
          (v_id, p_bulan, p_tahun, v_gaji, v_lembur, v_potongan,
           v_hari_wajib, v_hari_masuk, v_hari_absen, v_id_klien, v_bpjs_metode, v_bpjs_nilai, v_potongan_bpjs,
           v_gaji + v_lembur - v_potongan - v_potongan_bpjs);
      END IF;
    END IF;
  END LOOP;
  CLOSE cur;
  COMMIT;
END //

-- Invoice klien prorata + biaya BPJS. Tagihan tenaga tidak berubah: SEMUA penempatan di klien itu
-- yang overlap bulan, tarif_tagihan_klien * hari penempatan dalam bulan / jumlah hari bulan.
DROP PROCEDURE IF EXISTS sp_generate_invoice_klien //
CREATE PROCEDURE sp_generate_invoice_klien(IN p_id_klien INT, IN p_bulan INT, IN p_tahun INT)
BEGIN
  DECLARE v_jumlah INT;
  DECLARE v_total DECIMAL(12,2);
  DECLARE v_awal DATE;
  DECLARE v_akhir DATE;
  DECLARE v_hari_bulan INT;
  DECLARE v_bpjs_metode VARCHAR(10);
  DECLARE v_bpjs_nilai DECIMAL(12,2);
  DECLARE v_jumlah_payroll INT;
  DECLARE v_jumlah_aturan INT;
  DECLARE v_biaya_bpjs DECIMAL(12,2);

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

  SET v_awal = CONCAT(p_tahun, '-', LPAD(p_bulan, 2, '0'), '-01');
  SET v_akhir = LAST_DAY(v_awal);
  SET v_hari_bulan = DAY(v_akhir);

  SELECT COUNT(DISTINCT p.id_karyawan),
         COALESCE(SUM(ROUND(jl.tarif_tagihan_klien
           * (DATEDIFF(LEAST(IFNULL(p.tgl_selesai, v_akhir), v_akhir), GREATEST(p.tgl_mulai, v_awal)) + 1)
           / v_hari_bulan, 2)), 0)
  INTO v_jumlah, v_total
  FROM tbl_penempatan p
  JOIN tbl_karyawan k ON p.id_karyawan = k.id
  JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
  WHERE p.id_klien = p_id_klien
    AND p.tgl_mulai <= v_akhir
    AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= v_awal);

  IF v_jumlah = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Tidak ada tenaga yang ditempatkan di klien ini pada periode tersebut';
  END IF;

  IF (SELECT bpjs_metode FROM tbl_klien WHERE id = p_id_klien) IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM tbl_payroll
                     WHERE id_klien_bpjs = p_id_klien AND periode_bulan = p_bulan AND periode_tahun = p_tahun) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Generate payroll periode ini terlebih dahulu agar biaya BPJS klien dapat dihitung';
  END IF;

  -- Biaya BPJS = total potongan BPJS karyawan klien ini pada payroll periode yang sama. Metode/nilai
  -- diambil dari payroll (yang berlaku saat payroll dibuat); NULL bila tanpa BPJS atau aturannya campuran.
  SELECT COUNT(*), COALESCE(SUM(potongan_bpjs), 0),
         COUNT(DISTINCT bpjs_metode, bpjs_nilai), MAX(bpjs_metode), MAX(bpjs_nilai)
  INTO v_jumlah_payroll, v_biaya_bpjs, v_jumlah_aturan, v_bpjs_metode, v_bpjs_nilai
  FROM tbl_payroll
  WHERE id_klien_bpjs = p_id_klien AND periode_bulan = p_bulan AND periode_tahun = p_tahun;

  IF v_jumlah_aturan <> 1 THEN
    SET v_bpjs_metode = NULL;
    SET v_bpjs_nilai = NULL;
  END IF;

  INSERT INTO tbl_invoice
    (nomor_invoice, id_klien, periode_bulan, periode_tahun, jumlah_tenaga,
     bpjs_metode, bpjs_nilai, biaya_bpjs, total_tagihan)
  VALUES
    (CONCAT('INV-', p_tahun, '-', LPAD(p_bulan, 2, '0'), '-', LPAD(p_id_klien, 3, '0')),
     p_id_klien, p_bulan, p_tahun, v_jumlah,
     v_bpjs_metode, v_bpjs_nilai, v_biaya_bpjs, v_total + v_biaya_bpjs);
END //

-- Slip gaji: rincian hari + potongan BPJS beserta metode/nilai dan klien yang berlaku saat payroll dibuat.
CREATE OR REPLACE VIEW v_slip_gaji_detail AS
SELECT p.id, k.nip, k.nama, k.kategori, p.periode_bulan, p.periode_tahun,
       p.gaji_pokok, p.total_lembur, p.potongan_absen, p.total_gaji,
       p.status_bayar, p.tgl_bayar, p.hari_wajib, p.hari_masuk, p.hari_absen,
       p.potongan_bpjs, p.bpjs_metode, p.bpjs_nilai, p.id_klien_bpjs, kl.nama_perusahaan AS nama_klien_bpjs
FROM tbl_payroll p
JOIN tbl_karyawan k ON p.id_karyawan = k.id
LEFT JOIN tbl_klien kl ON kl.id = p.id_klien_bpjs //

DELIMITER ;

-- ============================================================
-- /sql/komponen/17_invoice_payroll_fee_ppn_rollback.sql
-- Mengembalikan sp_generate_invoice_klien versi 14_bpjs_klien.sql (tagihan tarif prorata + biaya BPJS).
-- ============================================================

-- [TAHAP 1]
DELIMITER //

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

DELIMITER ;

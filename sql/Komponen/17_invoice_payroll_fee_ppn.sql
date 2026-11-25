-- ============================================================
-- /sql/komponen/17_invoice_payroll_fee_ppn.sql
-- Invoice klien dari data payroll tersimpan + Management Fee + PPN 11%.
-- Tabel/kolom dibuat migrasi 2026_11_19_000001_invoice_payroll_fee_ppn sebelum blok ini dijalankan:
--   tbl_invoice.sub_total, persen_fee, management_fee, ppn; tbl_invoice_detail (snapshot lampiran).
--
-- Rincian per karyawan (tbl_invoice_detail), dari tbl_payroll periode yang sama -- payroll TIDAK diubah:
--   porsi        = hari penempatan di klien ini / total hari penempatan semua klien (fn_hari_penempatan,
--                  fn_total_hari_klien; sama dengan pembagian di fn_profit_klien). Karyawan yang masuk/keluar
--                  di tengah bulan sudah prorata di payroll; rotasi antar-klien dibagi menurut porsi ini.
--   komponen     = ROUND(komponen payroll x porsi, 0)  (rupiah penuh)
--   biaya tenaga kerja = gaji pokok + tunjangan + upah back-up + upah lembur - potongan absensi
--                  (potongan BPJS, pinjaman, PPh 21 sudah termasuk di dalamnya -> BPJS tidak ditagih lagi)
--   total penerimaan  = biaya tenaga kerja - potongan pinjaman - potongan BPJS - PPh 21
--
-- Invoice:
--   Sub Total      = SUM(biaya tenaga kerja)
--   Management Fee = ROUND(Sub Total x persen fee klien (tbl_klien.management_fee) / 100, 0)
--   PPN            = ROUND(Management Fee x 11 / 100, 0)
--   Grand Total    = Sub Total + Management Fee + PPN   (disimpan di total_tagihan)
--   biaya_bpjs = 0 (BPJS sudah termasuk Sub Total); bpjs_metode/bpjs_nilai hanya snapshot untuk label lampiran.
-- Ditolak bila management fee klien belum diisi (NULL) atau belum ada payroll karyawan klien pada periode itu.
-- Tukar Shift tidak menghasilkan biaya apa pun. Rollback: 17_invoice_payroll_fee_ppn_rollback.sql
-- ============================================================

-- [TAHAP 1]
DELIMITER //

DROP PROCEDURE IF EXISTS sp_generate_invoice_klien //
CREATE PROCEDURE sp_generate_invoice_klien(IN p_id_klien INT, IN p_bulan INT, IN p_tahun INT)
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
      SELECT t.id_karyawan,
             fn_hari_penempatan(t.id_karyawan, p_id_klien, v_awal, v_akhir) AS hari_klien,
             fn_total_hari_klien(t.id_karyawan, v_awal, v_akhir) AS hari_total
      FROM (SELECT DISTINCT id_karyawan FROM tbl_penempatan
            WHERE id_klien = p_id_klien AND tgl_mulai <= v_akhir
              AND (tgl_selesai IS NULL OR tgl_selesai >= v_awal)) t
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

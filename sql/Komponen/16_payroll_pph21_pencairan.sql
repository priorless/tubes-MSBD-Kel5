-- ============================================================
-- /sql/komponen/16_payroll_pph21_pencairan.sql
-- Payroll: PPh 21 otomatis, upah back-up, pencairan "Sudah Lunas" tanpa Mutasi Kas.
-- Kolom (dibuat migrasi 2026_11_18_060000_payroll_pph21_pencairan sebelum blok ini dijalankan):
--   tbl_payroll.upah_backup, tbl_payroll.pph21; status_bayar ENUM('Belum Dibayar','Sudah Lunas').
--
-- Pendapatan & potongan (sama dengan file 15 / halaman Komponen Gaji):
--   Lembur    : per absensi valid (jam_masuk & jam_keluar terisi, Hadir/Terlambat), jam_lembur >= 1
--               (jam penuh setelah jadwal pulang, diisi trg_auto_hitung_lembur). Jam pertama 1,5x,
--               berikutnya 2x upah per jam; upah per jam = gaji pokok / 173.
--   Back-up   : 0. Belum ada data penugasan Back-up (Pengganti Kosong) & persetujuannya di sistem;
--               Tukar Shift (barter) tidak pernah menghasilkan upah.
--   BPJS      : ROUND(gaji pokok * persentase BPJS klien / 100, 2); selain persentase -> 0.
--   Tunjangan & potongan pinjaman: tbl_komponen_gaji.
--
-- PPh 21 (PP 58/2023, PMK 168/2023, tarif Pasal 17 UU HPP) -- pegawai tetap:
--   bruto bulanan = gaji pokok + tunjangan + upah back-up + upah lembur
--   Januari-November : PPh 21 = ROUND(bruto bulanan x tarif TER kategori A, 0)
--   Desember (masa pajak terakhir):
--     bruto setahun = bruto semua payroll tahun itu (Jan-Des) karyawan tsb.
--     biaya jabatan = MIN(5% x bruto setahun, 500.000 x jumlah bulan payroll)   (maks 6.000.000)
--     PKP           = FLOOR((bruto setahun - biaya jabatan - PTKP) / 1000) x 1000, minimal 0
--     PPh Desember  = PPh Pasal 17 atas PKP - PPh 21 Januari-November (negatif = lebih bayar dikembalikan)
--   PTKP: Menikah & laki-laki = K/0 (58.500.000), selain itu TK/0 (54.000.000). Data HRD belum
--   memuat jumlah tanggungan, sehingga tanggungan dianggap 0 -> TER kategori A (TK/0, K/0).
--
-- total_gaji = gaji pokok + tunjangan + upah back-up + upah lembur
--              - (potongan absen + potongan BPJS + potongan pinjaman + PPh 21)
--
-- Pencairan: sp_update_status_bayar('PAYROLL') hanya mengubah 'Belum Dibayar' -> 'Sudah Lunas' + tgl_bayar;
-- pencairan kedua ditolak. Trigger payroll/invoice tidak lagi menulis tbl_mutasi_keuangan (log aktivitas tetap).
-- Rollback: 16_payroll_pph21_pencairan_rollback.sql
-- ============================================================

-- [TAHAP 1]
DELIMITER //

-- Tarif efektif rata-rata (TER) bulanan kategori A, Lampiran PP 58/2023 (dalam persen).
DROP FUNCTION IF EXISTS fn_tarif_ter_a //
CREATE FUNCTION fn_tarif_ter_a(p_bruto DECIMAL(15,2))
RETURNS DECIMAL(5,2)
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

-- PPh terutang setahun atas PKP, tarif Pasal 17 ayat (1) huruf a UU PPh s.t.d.d. UU HPP.
DROP FUNCTION IF EXISTS fn_pph21_pasal17 //
CREATE FUNCTION fn_pph21_pasal17(p_pkp DECIMAL(15,2))
RETURNS DECIMAL(15,2)
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

-- PTKP setahun dari data HRD (tanggungan belum tersedia -> 0): K/0 untuk laki-laki menikah, selain itu TK/0.
DROP FUNCTION IF EXISTS fn_ptkp_tahunan //
CREATE FUNCTION fn_ptkp_tahunan(p_id_karyawan INT)
RETURNS DECIMAL(15,2)
READS SQL DATA
BEGIN
  RETURN IF(
    (SELECT status_pernikahan = 'Menikah' AND jenis_kelamin = 'L' FROM tbl_karyawan WHERE id = p_id_karyawan) = 1,
    58500000, 54000000);
END //

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

-- Slip gaji: seluruh komponen tersimpan di tbl_payroll + jenis pekerjaan & kategori PTKP karyawan.
CREATE OR REPLACE VIEW v_slip_gaji_detail AS
SELECT p.id, k.nip, k.nama, k.kategori, p.periode_bulan, p.periode_tahun,
       p.gaji_pokok, p.total_lembur, p.potongan_absen,
       p.komponen_tunjangan, p.potongan_pinjaman, p.total_gaji,
       p.status_bayar, p.tgl_bayar, p.hari_wajib, p.hari_masuk, p.hari_absen,
       p.potongan_bpjs, p.bpjs_metode, p.bpjs_nilai, p.id_klien_bpjs, kl.nama_perusahaan AS nama_klien_bpjs,
       p.upah_backup, p.pph21, jl.nama_layanan AS jenis_pekerjaan,
       IF(k.status_pernikahan = 'Menikah' AND k.jenis_kelamin = 'L', 'K/0', 'TK/0') AS status_ptkp
FROM tbl_payroll p
JOIN tbl_karyawan k ON p.id_karyawan = k.id
LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
LEFT JOIN tbl_klien kl ON kl.id = p.id_klien_bpjs //

-- Pencairan payroll: hanya 'Belum Dibayar' -> 'Sudah Lunas'; pencairan kedua ditolak. Invoice tidak berubah.
DROP PROCEDURE IF EXISTS sp_update_status_bayar //
CREATE PROCEDURE sp_update_status_bayar(
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

-- tbl_payroll UPDATE: hanya log perubahan status bayar (tidak lagi menulis Mutasi Kas).
DROP TRIGGER IF EXISTS trg_mutasi_payroll_cair //
CREATE TRIGGER trg_mutasi_payroll_cair
AFTER UPDATE ON tbl_payroll
FOR EACH ROW
BEGIN
  IF NOT (NEW.status_bayar <=> OLD.status_bayar) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_payroll', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status bayar payroll ', LPAD(NEW.periode_bulan, 2, '0'), '/', NEW.periode_tahun, ' karyawan #', NEW.id_karyawan,
                        ': ', OLD.status_bayar, ' -> ', NEW.status_bayar), 255));
  END IF;
END //

-- tbl_invoice UPDATE: hanya log perubahan status (tidak lagi menulis Mutasi Kas).
DROP TRIGGER IF EXISTS trg_mutasi_invoice_lunas //
CREATE TRIGGER trg_mutasi_invoice_lunas
AFTER UPDATE ON tbl_invoice
FOR EACH ROW
BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_invoice', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status invoice ', NEW.nomor_invoice, ': ', OLD.status, ' -> ', NEW.status), 255));
  END IF;
END //

DELIMITER ;

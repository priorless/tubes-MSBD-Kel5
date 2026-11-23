-- ============================================================
-- 18_finalisasi_database_rollback.sql — Kebalikan tiap blok di 18_finalisasi_database.sql.
-- Dijalankan oleh down() migration yang bersesuaian.
-- ============================================================

-- [TAHAP 1]
-- Mengembalikan kolom status_lembur, pengisiannya di trigger, v_absensi_harian, dan sp_putuskan_lembur.
-- Data status lama tidak tersimpan; lembur yang ada dikembalikan sebagai 'Menunggu'.
DELIMITER //

ALTER TABLE tbl_absensi
  ADD COLUMN status_lembur ENUM('Tidak Ada','Menunggu','Disetujui','Ditolak') NOT NULL DEFAULT 'Tidak Ada' AFTER jam_lembur //

UPDATE tbl_absensi SET status_lembur = 'Menunggu' WHERE jam_lembur > 0 //

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

    SET NEW.status_lembur = IF(NEW.jam_lembur > 0, 'Menunggu', 'Tidak Ada');
  END IF;
END //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_absensi_harian AS
SELECT a.id AS id_absensi, a.id_karyawan, k.nip, k.nama, jl.nama_layanan, a.tgl,
       MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun, a.jam_masuk, a.jam_keluar, a.status_kehadiran,
       a.menit_terlambat, fn_hitung_jam_kerja(a.jam_masuk, a.jam_keluar) AS jam_kerja, a.jam_lembur,
       a.status_lembur, a.id_penempatan, kl.nama_perusahaan AS nama_klien, p.lokasi_kerja,
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

DROP PROCEDURE IF EXISTS sp_putuskan_lembur //
CREATE PROCEDURE sp_putuskan_lembur(IN p_id_absensi INT, IN p_keputusan VARCHAR(10))
BEGIN
  DECLARE v_status VARCHAR(10);

  IF p_keputusan IS NULL OR p_keputusan NOT IN ('Disetujui', 'Ditolak') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Keputusan lembur hanya boleh Disetujui atau Ditolak';
  END IF;

  SET v_status = (SELECT status_lembur FROM tbl_absensi WHERE id = p_id_absensi);

  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data absensi tidak ditemukan';
  END IF;
  IF v_status <> 'Menunggu' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hanya lembur yang berstatus Menunggu yang dapat diputuskan';
  END IF;

  UPDATE tbl_absensi SET status_lembur = p_keputusan WHERE id = p_id_absensi;
END //

DELIMITER ;

-- [TAHAP 2]
-- Payroll & invoice kembali ke prorata hari kalender penempatan; fungsi roster dihapus.
DELIMITER //

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

DROP FUNCTION IF EXISTS fn_total_hari_wajib_roster //
DROP FUNCTION IF EXISTS fn_hari_wajib_roster //
DROP FUNCTION IF EXISTS fn_hari_kerja_kontrak //
DROP FUNCTION IF EXISTS fn_status_roster //
DROP FUNCTION IF EXISTS fn_hari_tanpa_jadwal //
DROP FUNCTION IF EXISTS fn_hari_tanpa_jadwal_klien //
DROP FUNCTION IF EXISTS fn_hari_kerja_roster //
DROP FUNCTION IF EXISTS fn_hari_kerja_roster_klien //

DELIMITER ;

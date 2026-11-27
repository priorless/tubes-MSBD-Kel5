-- ============================================================
-- 23_sertifikat_ketidakhadiran_integritas_rollback.sql — Membatalkan 23_sertifikat_ketidakhadiran_integritas.sql.
-- Dijalankan migration dengan urutan TAHAP 4 -> 1. Tabel tbl_pengajuan_ketidakhadiran dihapus migration
-- setelah blok ini; rollback ditolak bila tbl_absensi sudah berisi status 'Cuti'.
-- ============================================================

-- [TAHAP 1]
DELIMITER //

DROP VIEW IF EXISTS v_sertifikat_karyawan //

ALTER TABLE tbl_sertifikat
  DROP CONSTRAINT chk_sertifikat_tanggal,
  DROP INDEX idx_sertifikat_kadaluarsa,
  ADD COLUMN status ENUM('Aktif','Kadaluarsa') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aktif' AFTER file_upload,
  ADD INDEX idx_sertifikat_kadaluarsa_status (tgl_kadaluarsa, status) //

UPDATE tbl_sertifikat SET status = 'Kadaluarsa' WHERE tgl_kadaluarsa < CURDATE() //

CREATE EVENT IF NOT EXISTS ev_update_status_sertifikat
  ON SCHEDULE EVERY 1 DAY
  STARTS TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '00:05:00')
  ON COMPLETION PRESERVE
  ENABLE
  COMMENT 'Tandai sertifikat Kadaluarsa bila tgl_kadaluarsa < hari ini'
  DO
    UPDATE tbl_sertifikat
    SET status = 'Kadaluarsa'
    WHERE status <> 'Kadaluarsa' AND tgl_kadaluarsa < CURDATE() //

DELIMITER ;

-- [TAHAP 2]
DELIMITER //

DROP PROCEDURE IF EXISTS sp_setujui_pengajuan_ketidakhadiran //
DROP TRIGGER IF EXISTS trg_pengajuan_ketidakhadiran_insert //
DROP TRIGGER IF EXISTS trg_pengajuan_ketidakhadiran_update //

ALTER TABLE tbl_absensi
  MODIFY status_kehadiran ENUM('Hadir','Terlambat','Izin','Sakit','Alpha') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Hadir' //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_rekap_absensi_pribadi AS
SELECT k.id AS id_karyawan,
       k.nama,
       MONTH(a.tgl) AS bulan,
       YEAR(a.tgl) AS tahun,
       COUNT(*) AS total_baris,
       SUM(a.status_kehadiran = 'Hadir') AS hari_hadir,
       SUM(a.status_kehadiran = 'Terlambat') AS hari_terlambat,
       SUM(a.status_kehadiran IN ('Izin', 'Sakit', 'Alpha')) AS hari_tidak_hadir,
       SUM(a.menit_terlambat) AS total_menit_terlambat,
       SUM(a.jam_lembur) AS total_jam_lembur
FROM tbl_absensi a
JOIN tbl_karyawan k ON a.id_karyawan = k.id
GROUP BY k.id, k.nama, MONTH(a.tgl), YEAR(a.tgl) //

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

DELIMITER ;

-- [TAHAP 3]
DELIMITER //

DROP TRIGGER IF EXISTS trg_koreksi_check_out_insert //
DROP TRIGGER IF EXISTS trg_koreksi_check_out_update //
DROP PROCEDURE IF EXISTS sp_cek_koreksi_check_out //

DELIMITER ;

-- [TAHAP 4]
DELIMITER //

ALTER TABLE tbl_p2h
  DROP FOREIGN KEY fk_p2h_absensi_karyawan,
  DROP INDEX idx_p2h_absensi_karyawan //

ALTER TABLE tbl_laporan_aktivitas
  DROP FOREIGN KEY fk_laporan_absensi_karyawan,
  DROP INDEX idx_laporan_absensi_karyawan //

ALTER TABLE tbl_absensi
  DROP INDEX uq_absensi_id_karyawan //

DELIMITER ;

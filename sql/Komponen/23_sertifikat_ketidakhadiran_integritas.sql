-- ============================================================
-- 23_sertifikat_ketidakhadiran_integritas.sql — Sertifikat, pengajuan ketidakhadiran oleh karyawan,
-- batas wajar koreksi check-out, dan konsistensi id_karyawan pada dokumentasi absensi.
--
-- [TAHAP 1] tbl_sertifikat   : status tidak lagi disimpan. Kolom status + event ev_update_status_sertifikat
--                              dihapus; status & sisa hari dihitung di view v_sertifikat_karyawan dari
--                              tgl_kadaluarsa. CHECK tgl_kadaluarsa > tgl_terbit.
-- [TAHAP 2] ketidakhadiran   : status_kehadiran + 'Cuti' (cuti berbayar: dihitung hari masuk di payroll Lapangan,
--                              tidak memotong gaji Internal). tbl_pengajuan_ketidakhadiran (dibuat migration) diberi
--                              CHECK + trigger validasi; persetujuan HANYA lewat sp_setujui_pengajuan_ketidakhadiran
--                              yang mencatat baris tbl_absensi per hari kerja dalam rentang pengajuan.
-- [TAHAP 3] koreksi check-out: sp_cek_koreksi_check_out membatasi jam keluar: minimal 30 menit setelah check-in,
--                              maksimal 4 jam setelah jadwal pulang (batas lembur harian), atau 16 jam setelah
--                              check-in bila tidak ada jadwal. Dipanggil trigger saat pengajuan dibuat & disetujui.
-- [TAHAP 4] 3NF terkendali   : tbl_laporan_aktivitas.id_karyawan dan tbl_p2h.id_karyawan wajib sama dengan
--                              tbl_absensi.id_karyawan lewat FOREIGN KEY komposit (id_absensi, id_karyawan).
-- Rollback: 23_sertifikat_ketidakhadiran_integritas_rollback.sql
-- ============================================================

-- [TAHAP 1]
DELIMITER //

DROP EVENT IF EXISTS ev_update_status_sertifikat //

ALTER TABLE tbl_sertifikat
  DROP INDEX idx_sertifikat_kadaluarsa_status,
  DROP COLUMN status,
  ADD INDEX idx_sertifikat_kadaluarsa (tgl_kadaluarsa),
  ADD CONSTRAINT chk_sertifikat_tanggal CHECK (tgl_kadaluarsa > tgl_terbit) //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_sertifikat_karyawan AS
SELECT s.id,
       s.id_karyawan,
       k.nip,
       k.nama,
       s.nama_dokumen,
       s.nomor_dokumen,
       s.tgl_terbit,
       s.tgl_kadaluarsa,
       s.file_upload,
       -- Berlaku sampai akhir tanggal kedaluwarsa; mulai besoknya Kadaluarsa (sama dengan event lama).
       CASE WHEN s.tgl_kadaluarsa < CURDATE() THEN 'Kadaluarsa' ELSE 'Aktif' END AS status,
       fn_sisa_hari_dokumen(s.tgl_kadaluarsa) AS sisa_hari
FROM tbl_sertifikat s
JOIN tbl_karyawan k ON k.id = s.id_karyawan //

DELIMITER ;

-- [TAHAP 2]
DELIMITER //

ALTER TABLE tbl_absensi
  MODIFY status_kehadiran ENUM('Hadir','Terlambat','Izin','Sakit','Cuti','Alpha') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Hadir' //

ALTER TABLE tbl_pengajuan_ketidakhadiran
  ADD CONSTRAINT chk_pengajuan_ketidakhadiran_tanggal CHECK (
    tgl_selesai >= tgl_mulai AND DATEDIFF(tgl_selesai, tgl_mulai) < 31
  ),
  ADD CONSTRAINT chk_pengajuan_ketidakhadiran_diproses CHECK (
    (status = 'Menunggu' AND diproses_oleh IS NULL AND diproses_pada IS NULL)
    OR (status = 'Disetujui' AND diproses_oleh IS NOT NULL AND diproses_pada IS NOT NULL)
    OR (status = 'Ditolak' AND diproses_oleh IS NOT NULL AND diproses_pada IS NOT NULL AND catatan_keputusan IS NOT NULL)
    OR (status = 'Dibatalkan' AND diproses_oleh IS NULL AND diproses_pada IS NOT NULL)
  ) //

DROP TRIGGER IF EXISTS trg_pengajuan_ketidakhadiran_insert //
CREATE TRIGGER trg_pengajuan_ketidakhadiran_insert BEFORE INSERT ON tbl_pengajuan_ketidakhadiran FOR EACH ROW BEGIN
  DECLARE v_pesan VARCHAR(255);
  DECLARE v_bentrok DATE DEFAULT NULL;

  IF NEW.status <> 'Menunggu' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengajuan ketidakhadiran baru harus berstatus Menunggu.';
  END IF;
  IF (SELECT status FROM tbl_karyawan WHERE id = NEW.id_karyawan) = 'Nonaktif' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Karyawan berstatus Nonaktif tidak dapat mengajukan ketidakhadiran.';
  END IF;

  SET v_bentrok = (SELECT MIN(pk.tgl_mulai) FROM tbl_pengajuan_ketidakhadiran pk
                   WHERE pk.id_karyawan = NEW.id_karyawan AND pk.status IN ('Menunggu', 'Disetujui')
                     AND pk.tgl_mulai <= NEW.tgl_selesai AND pk.tgl_selesai >= NEW.tgl_mulai);
  IF v_bentrok IS NOT NULL THEN
    SET v_pesan = CONCAT('Rentang tanggal bertabrakan dengan pengajuan lain yang mulai ', DATE_FORMAT(v_bentrok, '%d-%m-%Y'),
                         ' (Menunggu/Disetujui).');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_pesan;
  END IF;
END //

DROP TRIGGER IF EXISTS trg_pengajuan_ketidakhadiran_update //
CREATE TRIGGER trg_pengajuan_ketidakhadiran_update BEFORE UPDATE ON tbl_pengajuan_ketidakhadiran FOR EACH ROW BEGIN
  IF NOT (NEW.id_karyawan <=> OLD.id_karyawan) OR NOT (NEW.jenis <=> OLD.jenis)
     OR NOT (NEW.tgl_mulai <=> OLD.tgl_mulai) OR NOT (NEW.tgl_selesai <=> OLD.tgl_selesai)
     OR NOT (NEW.alasan <=> OLD.alasan) OR NOT (NEW.file_bukti <=> OLD.file_bukti) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Isi pengajuan ketidakhadiran tidak dapat diubah setelah dikirim.';
  END IF;
  IF OLD.status <> 'Menunggu' AND NOT (NEW.status <=> OLD.status) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengajuan ini sudah diproses sebelumnya dan tidak dapat diproses lagi.';
  END IF;
  -- Persetujuan wajib lewat procedure agar baris absensi tercatat dalam operasi yang sama.
  IF NEW.status = 'Disetujui' AND OLD.status = 'Menunggu' AND NOT (@sji_pengajuan_disetujui <=> NEW.id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Persetujuan ketidakhadiran hanya dapat dilakukan melalui sp_setujui_pengajuan_ketidakhadiran.';
  END IF;
END //

DROP PROCEDURE IF EXISTS sp_setujui_pengajuan_ketidakhadiran //
CREATE PROCEDURE sp_setujui_pengajuan_ketidakhadiran(IN p_id INT, IN p_diproses_oleh INT)
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
  SET v_tgl = v_mulai;
  WHILE v_tgl <= v_selesai DO
    SET v_pen = (SELECT id FROM tbl_penempatan
                 WHERE id_karyawan = v_karyawan AND tgl_mulai <= v_tgl AND (tgl_selesai IS NULL OR tgl_selesai >= v_tgl)
                 ORDER BY tgl_mulai DESC, id DESC LIMIT 1);
    IF NOT (fn_status_roster(v_karyawan, v_tgl) <=> 'Libur') AND (v_pen IS NOT NULL OR v_kategori = 'Internal') THEN
      IF EXISTS (SELECT 1 FROM tbl_absensi WHERE id_karyawan = v_karyawan AND tgl = v_tgl) THEN
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
      INSERT INTO tbl_absensi (id_karyawan, id_penempatan, tgl, status_kehadiran, keterangan)
      VALUES (v_karyawan, v_pen, v_tgl, v_jenis, LEFT(CONCAT('Pengajuan #', p_id, ': ', v_alasan), 255));
    END IF;
    SET v_tgl = v_tgl + INTERVAL 1 DAY;
  END WHILE;

  SET @sji_pengajuan_disetujui = p_id;
  UPDATE tbl_pengajuan_ketidakhadiran
  SET status = 'Disetujui', diproses_oleh = p_diproses_oleh, diproses_pada = NOW(), updated_at = NOW()
  WHERE id = p_id;
  SET @sji_pengajuan_disetujui = NULL;
END //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_rekap_absensi_pribadi AS
SELECT k.id AS id_karyawan,
       k.nama,
       MONTH(a.tgl) AS bulan,
       YEAR(a.tgl) AS tahun,
       COUNT(*) AS total_baris,
       SUM(a.status_kehadiran = 'Hadir') AS hari_hadir,
       SUM(a.status_kehadiran = 'Terlambat') AS hari_terlambat,
       SUM(a.status_kehadiran IN ('Izin', 'Sakit', 'Cuti', 'Alpha')) AS hari_tidak_hadir,
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

DELIMITER ;

-- [TAHAP 3]
DELIMITER //

DROP PROCEDURE IF EXISTS sp_cek_koreksi_check_out //
CREATE PROCEDURE sp_cek_koreksi_check_out(IN p_id_absensi INT, IN p_jam_keluar TIME)
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

DROP TRIGGER IF EXISTS trg_koreksi_check_out_insert //
CREATE TRIGGER trg_koreksi_check_out_insert BEFORE INSERT ON tbl_koreksi_check_out FOR EACH ROW BEGIN
  CALL sp_cek_koreksi_check_out(NEW.id_absensi, NEW.jam_keluar);
END //

DROP TRIGGER IF EXISTS trg_koreksi_check_out_update //
CREATE TRIGGER trg_koreksi_check_out_update BEFORE UPDATE ON tbl_koreksi_check_out FOR EACH ROW BEGIN
  -- Diperiksa ulang saat disetujui, termasuk pengajuan yang dibuat sebelum aturan ini ada.
  IF (NEW.status = 'Disetujui' AND OLD.status <> 'Disetujui')
     OR NOT (NEW.jam_keluar <=> OLD.jam_keluar) OR NOT (NEW.id_absensi <=> OLD.id_absensi) THEN
    CALL sp_cek_koreksi_check_out(NEW.id_absensi, NEW.jam_keluar);
  END IF;
END //

DELIMITER ;

-- [TAHAP 4]
DELIMITER //

ALTER TABLE tbl_absensi
  ADD UNIQUE KEY uq_absensi_id_karyawan (id, id_karyawan) //

ALTER TABLE tbl_laporan_aktivitas
  ADD INDEX idx_laporan_absensi_karyawan (id_absensi, id_karyawan),
  ADD CONSTRAINT fk_laporan_absensi_karyawan FOREIGN KEY (id_absensi, id_karyawan)
    REFERENCES tbl_absensi (id, id_karyawan) ON DELETE RESTRICT ON UPDATE RESTRICT //

ALTER TABLE tbl_p2h
  ADD INDEX idx_p2h_absensi_karyawan (id_absensi, id_karyawan),
  ADD CONSTRAINT fk_p2h_absensi_karyawan FOREIGN KEY (id_absensi, id_karyawan)
    REFERENCES tbl_absensi (id, id_karyawan) ON DELETE RESTRICT ON UPDATE RESTRICT //

DELIMITER ;

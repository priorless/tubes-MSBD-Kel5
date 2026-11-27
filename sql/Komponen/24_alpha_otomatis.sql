-- ============================================================
-- 24_alpha_otomatis.sql — Karyawan yang tidak check-in sampai shift berakhir otomatis tercatat Alpha.
--
-- [TAHAP 1] sp_tandai_alpha_otomatis(p_waktu): untuk setiap pekerja (pemilik jadwal, atau pengganti bila shift
--           di-backup) yang punya jadwal Kerja di roster, semua shift-nya pada tanggal itu sudah berakhir, dan belum
--           punya baris absensi, dicatat satu baris tbl_absensi berstatus Alpha (tanpa jam masuk).
--           Dilewati: karyawan Nonaktif/Cuti, periode yang payroll-nya sudah dibuat, dan shift yang berakhir lebih
--           dari 7 hari lalu (data lama tidak diisi mundur). Dijalankan event ev_tandai_alpha_otomatis tiap 15 menit.
-- [TAHAP 2] Alpha tetap bisa diperbaiki: baris Alpha tanpa check-in DIGANTI (bukan ditolak) oleh
--           sp_setujui_pengajuan_ketidakhadiran (Izin/Sakit/Cuti disetujui HRD) dan sp_input_ketidakhadiran (HRD).
-- Rollback: 24_alpha_otomatis_rollback.sql
-- ============================================================

-- [TAHAP 1]
DELIMITER //

DROP PROCEDURE IF EXISTS sp_tandai_alpha_otomatis //
CREATE PROCEDURE sp_tandai_alpha_otomatis(IN p_waktu DATETIME)
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

DROP EVENT IF EXISTS ev_tandai_alpha_otomatis //
CREATE EVENT ev_tandai_alpha_otomatis
  ON SCHEDULE EVERY 15 MINUTE
  STARTS CURRENT_TIMESTAMP
  ON COMPLETION PRESERVE
  ENABLE
  COMMENT 'Catat Alpha untuk shift Kerja yang berakhir tanpa check-in (sp_tandai_alpha_otomatis)'
  DO CALL sp_tandai_alpha_otomatis(NOW()) //

DELIMITER ;

-- [TAHAP 2]
DELIMITER //

DROP PROCEDURE IF EXISTS sp_input_ketidakhadiran //
CREATE PROCEDURE sp_input_ketidakhadiran(
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

DELIMITER ;

-- ============================================================
-- 24_alpha_otomatis_rollback.sql — Membatalkan 24_alpha_otomatis.sql (dijalankan TAHAP 2 -> 1).
-- Baris Alpha otomatis yang sudah tercatat tidak dihapus (tetap data absensi yang sah).
-- ============================================================

-- [TAHAP 1]
DELIMITER //

DROP EVENT IF EXISTS ev_tandai_alpha_otomatis //
DROP PROCEDURE IF EXISTS sp_tandai_alpha_otomatis //

DELIMITER ;

-- [TAHAP 2]
DELIMITER //

DROP PROCEDURE IF EXISTS sp_input_ketidakhadiran //
CREATE PROCEDURE sp_input_ketidakhadiran(IN p_id_karyawan int, IN p_tgl date, IN p_status varchar(10), IN p_keterangan varchar(255))
BEGIN
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_status_karyawan VARCHAR(20);
  DECLARE v_pen_id INT;

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

DELIMITER ;

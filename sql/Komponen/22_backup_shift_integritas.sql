-- ============================================================
-- 22_backup_shift_integritas.sql — Roker shift menjadi BACKUP SHIFT (1 arah) + integritas absensi.
--
-- Model data:
--   tbl_pertukaran_shift  = pengajuan: pengaju meminta rekan (jenis layanan sama, klien boleh berbeda)
--                           menggantikan SATU jadwal miliknya (id_jadwal_pengaju). Rekan menerima/menolak,
--                           lalu Operasional memutuskan. Status: Menunggu, Disetujui, Ditolak, Dibatalkan, Kedaluwarsa.
--   tbl_jadwal_kerja      = fakta penugasan: id_karyawan_pengganti diisi saat pengajuan Disetujui. Jadwal tetap
--                           milik penempatan pengaju, sehingga lokasi, jam shift, dan klien yang ditagih tidak berubah.
--
-- [TAHAP 1] tbl_pertukaran_shift : CHECK status/rekan/diproses, trigger validasi jadwal-pengaju-tanggal dan
--                                  transisi status, view v_pertukaran_shift_snapshot model 1 arah.
-- [TAHAP 2] tbl_jadwal_kerja     : sp_cek_jadwal_kerja memeriksa pekerja efektif (pengganti atau pemilik),
--                                  pengganti hanya lewat pengajuan Disetujui, jadwal yang sedang diajukan dikunci.
-- [TAHAP 3] tbl_absensi          : hapus trg_cegah_absen_ganda (cukup UNIQUE uq_absensi_karyawan_tgl);
--                                  id_penempatan wajib milik karyawan, kecuali shift backup yang ditugaskan kepadanya.
-- [TAHAP 4] roster payroll       : hari yang digantikan tidak lagi menjadi hari wajib pengaju; hari backup dihitung
--                                  'Libur' bagi pengganti sehingga dibayar sebagai upah_backup (aturan payroll lama).
-- [TAHAP 5] tbl_penempatan       : penempatan berakhir/dirotasi -> pengajuan Menunggu yang terdampak otomatis Dibatalkan.
-- [TAHAP 6] tbl_koreksi_check_out: CHECK status/diproses.
-- Rollback: 22_backup_shift_integritas_rollback.sql
-- ============================================================

-- [TAHAP 1]
DELIMITER //

ALTER TABLE tbl_pertukaran_shift
  ADD CONSTRAINT chk_pertukaran_diproses CHECK (
    (status = 'Menunggu' AND diproses_oleh IS NULL AND diproses_pada IS NULL)
    OR (status = 'Disetujui' AND diproses_oleh IS NOT NULL AND diproses_pada IS NOT NULL)
    OR (status = 'Dibatalkan' AND diproses_pada IS NOT NULL)
    OR (status = 'Ditolak' AND diproses_pada IS NOT NULL AND (diproses_oleh IS NOT NULL OR status_rekan = 'Ditolak'))
    OR (status = 'Kedaluwarsa' AND diproses_oleh IS NULL AND diproses_pada IS NOT NULL)
  ),
  ADD CONSTRAINT chk_pertukaran_rekan CHECK (
    (status_rekan = 'Menunggu') = (ditanggapi_rekan_pada IS NULL)
    AND (status_rekan <> 'Ditolak' OR status = 'Ditolak')
    AND (status <> 'Disetujui' OR status_rekan = 'Diterima')
  ) //

DROP PROCEDURE IF EXISTS sp_cek_pertukaran_shift //
CREATE PROCEDURE sp_cek_pertukaran_shift(IN p_id_jadwal INT, IN p_id_pengaju INT, IN p_id_rekan INT, IN p_tanggal DATE)
BEGIN
  DECLARE v_pemilik INT DEFAULT NULL;
  DECLARE v_tanggal DATE DEFAULT NULL;
  DECLARE v_layanan INT DEFAULT NULL;

  SELECT p.id_karyawan, j.tanggal_kerja INTO v_pemilik, v_tanggal
  FROM tbl_jadwal_kerja j JOIN tbl_penempatan p ON p.id = j.id_penempatan
  WHERE j.id = p_id_jadwal;

  IF v_pemilik IS NULL OR v_pemilik <> p_id_pengaju THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jadwal yang diajukan bukan milik karyawan pengaju.';
  END IF;
  IF v_tanggal <> p_tanggal THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal kerja pengajuan tidak sesuai dengan tanggal jadwal.';
  END IF;

  SET v_layanan = (SELECT id_jenis_layanan FROM tbl_karyawan WHERE id = p_id_pengaju);
  IF v_layanan IS NULL OR NOT EXISTS (SELECT 1 FROM tbl_karyawan WHERE id = p_id_rekan AND id_jenis_layanan = v_layanan) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rekan pengganti harus memiliki jabatan/jenis layanan yang sama dengan pengaju.';
  END IF;
END //

DROP TRIGGER IF EXISTS trg_pertukaran_shift_insert //
CREATE TRIGGER trg_pertukaran_shift_insert BEFORE INSERT ON tbl_pertukaran_shift FOR EACH ROW BEGIN
  IF NEW.status <> 'Menunggu' OR NEW.status_rekan <> 'Menunggu' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengajuan backup shift baru harus berstatus Menunggu.';
  END IF;
  CALL sp_cek_pertukaran_shift(NEW.id_jadwal_pengaju, NEW.id_karyawan_pengaju, NEW.id_karyawan_rekan, NEW.tanggal_kerja);
END //

DROP TRIGGER IF EXISTS trg_pertukaran_shift_update //
CREATE TRIGGER trg_pertukaran_shift_update BEFORE UPDATE ON tbl_pertukaran_shift FOR EACH ROW BEGIN
  IF NOT (NEW.id_jadwal_pengaju <=> OLD.id_jadwal_pengaju) OR NOT (NEW.id_karyawan_pengaju <=> OLD.id_karyawan_pengaju)
     OR NOT (NEW.id_karyawan_rekan <=> OLD.id_karyawan_rekan) OR NOT (NEW.tanggal_kerja <=> OLD.tanggal_kerja) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jadwal, pengaju, rekan, dan tanggal pengajuan tidak dapat diubah.';
  END IF;
  IF OLD.status <> 'Menunggu' AND NOT (NEW.status <=> OLD.status) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengajuan ini sudah diproses sebelumnya dan tidak dapat diproses lagi.';
  END IF;
  IF OLD.status_rekan <> 'Menunggu' AND NOT (NEW.status_rekan <=> OLD.status_rekan) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rekan sudah menanggapi pengajuan ini.';
  END IF;
  IF NEW.status = 'Disetujui' AND OLD.status = 'Menunggu' AND NEW.status_rekan <> 'Diterima' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengajuan belum diterima rekan sehingga belum dapat disetujui.';
  END IF;
END //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_pertukaran_shift_snapshot AS
SELECT ps.id,
       ps.tanggal_kerja,
       ps.status,
       ps.status_rekan,
       ps.id_karyawan_pengaju,
       kp.nama AS nama_pengaju,
       ps.id_karyawan_rekan,
       kr.nama AS nama_rekan,
       JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_pengaju, '$.status')) AS status_jadwal,
       JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_pengaju, '$.jam_masuk')) AS jam_masuk,
       JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_pengaju, '$.jam_pulang')) AS jam_pulang,
       NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ps.snapshot_pengaju, '$.nama_shift_snapshot')), 'null') AS nama_shift,
       ps.created_at
FROM tbl_pertukaran_shift ps
JOIN tbl_karyawan kp ON kp.id = ps.id_karyawan_pengaju
JOIN tbl_karyawan kr ON kr.id = ps.id_karyawan_rekan //

DELIMITER ;

-- [TAHAP 2]
DELIMITER //

ALTER TABLE tbl_jadwal_kerja
  ADD CONSTRAINT chk_jadwal_pengganti_kerja CHECK (id_karyawan_pengganti IS NULL OR status = 'Kerja') //

DROP PROCEDURE IF EXISTS sp_cek_jadwal_kerja //
CREATE PROCEDURE sp_cek_jadwal_kerja(IN p_id INT, IN p_id_penempatan INT, IN p_tanggal DATE, IN p_status VARCHAR(5),
                                     IN p_jam_masuk TIME, IN p_jam_pulang TIME, IN p_id_pengganti INT)
BEGIN
  DECLARE v_id_karyawan INT;
  DECLARE v_mulai DATE;
  DECLARE v_selesai DATE;
  DECLARE v_layanan INT;
  DECLARE v_pekerja INT;
  DECLARE v_awal DATETIME;
  DECLARE v_akhir DATETIME;
  DECLARE v_bentrok DATE;
  DECLARE v_pesan VARCHAR(255);

  SELECT p.id_karyawan, p.tgl_mulai, p.tgl_selesai, k.id_jenis_layanan INTO v_id_karyawan, v_mulai, v_selesai, v_layanan
  FROM tbl_penempatan p JOIN tbl_karyawan k ON k.id = p.id_karyawan
  WHERE p.id = p_id_penempatan;

  IF v_id_karyawan IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan tidak ditemukan.';
  END IF;
  IF p_tanggal < v_mulai OR (v_selesai IS NOT NULL AND p_tanggal > v_selesai) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tanggal jadwal harus berada dalam masa penempatan.';
  END IF;

  -- Pengganti (backup shift): jenis layanan sama, penempatan aktif pada tanggal itu, dan tidak sedang bekerja
  -- di shift lain pada tanggal yang sama (satu karyawan hanya punya satu absensi per tanggal).
  IF p_id_pengganti IS NOT NULL THEN
    IF p_id_pengganti = v_id_karyawan THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengganti shift tidak boleh karyawan pemilik jadwal.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM tbl_karyawan WHERE id = p_id_pengganti AND id_jenis_layanan = v_layanan) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengganti shift harus memiliki jabatan/jenis layanan yang sama.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM tbl_penempatan p
                   WHERE p.id_karyawan = p_id_pengganti AND p.status = 'Aktif'
                     AND p.tgl_mulai <= p_tanggal AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= p_tanggal)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengganti shift tidak memiliki penempatan aktif pada tanggal tersebut.';
    END IF;
  END IF;

  SET v_pekerja = COALESCE(p_id_pengganti, v_id_karyawan);

  IF p_status = 'Kerja' THEN
    IF p_jam_masuk IS NULL OR p_jam_pulang IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pilih jam tersimpan atau isi jam masuk dan jam pulang terlebih dahulu.';
    END IF;

    -- Shift backup tidak boleh berbagi tanggal dengan shift Kerja lain milik pekerja yang sama.
    IF EXISTS (SELECT 1 FROM tbl_jadwal_kerja j JOIN tbl_penempatan p ON p.id = j.id_penempatan
               WHERE COALESCE(j.id_karyawan_pengganti, p.id_karyawan) = v_pekerja
                 AND (p_id IS NULL OR j.id <> p_id)
                 AND j.status = 'Kerja' AND j.tanggal_kerja = p_tanggal
                 AND (j.id_karyawan_pengganti IS NOT NULL OR p_id_pengganti IS NOT NULL)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Karyawan sudah memiliki shift Kerja (termasuk backup shift) pada tanggal tersebut.';
    END IF;

    -- Shift lewat tengah malam (jam pulang <= jam masuk) berakhir keesokan harinya, jadi jadwal H-1 dan H+1 ikut diperiksa.
    SET v_awal = TIMESTAMP(p_tanggal, p_jam_masuk);
    SET v_akhir = TIMESTAMP(IF(p_jam_pulang <= p_jam_masuk, p_tanggal + INTERVAL 1 DAY, p_tanggal), p_jam_pulang);

    SELECT j.tanggal_kerja INTO v_bentrok
    FROM tbl_jadwal_kerja j
    JOIN tbl_penempatan p ON p.id = j.id_penempatan
    WHERE COALESCE(j.id_karyawan_pengganti, p.id_karyawan) = v_pekerja
      AND NOT (j.id_penempatan = p_id_penempatan AND j.id_karyawan_pengganti IS NULL AND p_id_pengganti IS NULL)
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
CREATE TRIGGER trg_cek_jadwal_kerja_insert BEFORE INSERT ON tbl_jadwal_kerja FOR EACH ROW BEGIN
  IF NEW.id_karyawan_pengganti IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengganti shift hanya dapat ditetapkan melalui pengajuan backup shift yang disetujui.';
  END IF;
  CALL sp_cek_jadwal_kerja(NULL, NEW.id_penempatan, NEW.tanggal_kerja, NEW.status, NEW.jam_masuk, NEW.jam_pulang, NULL);
END //

DROP TRIGGER IF EXISTS trg_cek_jadwal_kerja_update //
CREATE TRIGGER trg_cek_jadwal_kerja_update BEFORE UPDATE ON tbl_jadwal_kerja FOR EACH ROW BEGIN
  IF EXISTS (SELECT 1 FROM tbl_pertukaran_shift WHERE id_jadwal_pengaju = OLD.id AND status = 'Menunggu')
     AND (NOT (NEW.status <=> OLD.status) OR NOT (NEW.jam_masuk <=> OLD.jam_masuk) OR NOT (NEW.jam_pulang <=> OLD.jam_pulang)
          OR NOT (NEW.id_penempatan <=> OLD.id_penempatan) OR NOT (NEW.tanggal_kerja <=> OLD.tanggal_kerja)
          OR NOT (NEW.id_karyawan_pengganti <=> OLD.id_karyawan_pengganti)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jadwal ini sedang diajukan untuk backup shift. Proses atau tunggu pengajuan tersebut selesai terlebih dahulu.';
  END IF;
  IF NEW.id_karyawan_pengganti IS NOT NULL AND NOT (NEW.id_karyawan_pengganti <=> OLD.id_karyawan_pengganti)
     AND NOT EXISTS (SELECT 1 FROM tbl_pertukaran_shift
                     WHERE id_jadwal_pengaju = NEW.id AND id_karyawan_rekan = NEW.id_karyawan_pengganti AND status = 'Disetujui') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengganti shift hanya dapat ditetapkan melalui pengajuan backup shift yang disetujui.';
  END IF;
  CALL sp_cek_jadwal_kerja(OLD.id, NEW.id_penempatan, NEW.tanggal_kerja, NEW.status, NEW.jam_masuk, NEW.jam_pulang, NEW.id_karyawan_pengganti);
END //

DELIMITER ;

-- [TAHAP 3]
DELIMITER //

DROP TRIGGER IF EXISTS trg_cegah_absen_ganda //

DROP PROCEDURE IF EXISTS sp_cek_penempatan_absensi //
CREATE PROCEDURE sp_cek_penempatan_absensi(IN p_id_karyawan INT, IN p_id_penempatan INT, IN p_tgl DATE)
BEGIN
  DECLARE v_pemilik INT DEFAULT NULL;

  IF p_id_penempatan IS NOT NULL THEN
    SET v_pemilik = (SELECT id_karyawan FROM tbl_penempatan WHERE id = p_id_penempatan);

    IF v_pemilik = p_id_karyawan THEN
      IF EXISTS (SELECT 1 FROM tbl_jadwal_kerja
                 WHERE id_penempatan = p_id_penempatan AND tanggal_kerja = p_tgl AND id_karyawan_pengganti IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shift pada tanggal ini sudah digantikan rekan (backup shift), absensi tidak dapat dicatat.';
      END IF;
    ELSEIF NOT EXISTS (SELECT 1 FROM tbl_jadwal_kerja
                       WHERE id_penempatan = p_id_penempatan AND tanggal_kerja = p_tgl AND id_karyawan_pengganti = p_id_karyawan) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Penempatan pada absensi bukan milik karyawan ini dan bukan shift backup yang ditugaskan kepadanya.';
    END IF;
  END IF;
END //

DROP TRIGGER IF EXISTS trg_cek_penempatan_absensi_insert //
CREATE TRIGGER trg_cek_penempatan_absensi_insert BEFORE INSERT ON tbl_absensi FOR EACH ROW FOLLOWS trg_validasi_absen_masuk BEGIN
  CALL sp_cek_penempatan_absensi(NEW.id_karyawan, NEW.id_penempatan, NEW.tgl);
END //

DROP TRIGGER IF EXISTS trg_cek_penempatan_absensi_update //
CREATE TRIGGER trg_cek_penempatan_absensi_update BEFORE UPDATE ON tbl_absensi FOR EACH ROW FOLLOWS trg_auto_hitung_lembur BEGIN
  IF NOT (NEW.id_karyawan <=> OLD.id_karyawan) OR NOT (NEW.id_penempatan <=> OLD.id_penempatan) OR NOT (NEW.tgl <=> OLD.tgl) THEN
    CALL sp_cek_penempatan_absensi(NEW.id_karyawan, NEW.id_penempatan, NEW.tgl);
  END IF;
END //

DELIMITER ;

-- [TAHAP 4]
DELIMITER //

DROP FUNCTION IF EXISTS fn_hari_kerja_roster_klien //
CREATE FUNCTION fn_hari_kerja_roster_klien(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE) RETURNS int
    READS SQL DATA
BEGIN
  -- Tanggal berstatus Kerja di roster, lintas penempatan; satu tanggal dihitung sekali.
  -- Jadwal yang digantikan rekan (backup shift) bukan hari wajib pemilik jadwal.
  RETURN (
    SELECT COUNT(DISTINCT j.tanggal_kerja)
    FROM tbl_jadwal_kerja j
    JOIN tbl_penempatan p ON p.id = j.id_penempatan
    WHERE p.id_karyawan = p_id_karyawan
      AND (p_id_klien IS NULL OR p.id_klien = p_id_klien)
      AND j.status = 'Kerja'
      AND j.id_karyawan_pengganti IS NULL
      AND j.tanggal_kerja BETWEEN p_awal AND p_akhir
      AND j.tanggal_kerja >= p.tgl_mulai
      AND (p.tgl_selesai IS NULL OR j.tanggal_kerja <= p.tgl_selesai)
  );
END //

DROP FUNCTION IF EXISTS fn_hari_tanpa_jadwal_klien //
CREATE FUNCTION fn_hari_tanpa_jadwal_klien(p_id_karyawan INT, p_id_klien INT, p_awal DATE, p_akhir DATE) RETURNS int
    READS SQL DATA
BEGIN
  -- Hari dalam masa penempatan yang belum punya baris jadwal (Kerja maupun Libur). Baris jadwal di luar masa
  -- penempatannya (sisa sebelum penempatan diperpendek oleh rotasi) tidak dihitung, sama dengan fn_hari_kerja_roster.
  -- Hari ketika karyawan bertugas sebagai pengganti (backup shift) juga tidak dihitung: hari itu dibayar upah_backup.
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
        AND j.tanggal_kerja >= p.tgl_mulai
        AND (p.tgl_selesai IS NULL OR j.tanggal_kerja <= p.tgl_selesai)
    )
    AND NOT EXISTS (
      SELECT 1 FROM tbl_jadwal_kerja jb
      WHERE jb.id_karyawan_pengganti = p_id_karyawan AND jb.tanggal_kerja = h.tgl
    )
  );
END //

DROP FUNCTION IF EXISTS fn_status_roster //
CREATE FUNCTION fn_status_roster(p_id_karyawan INT, p_tgl DATE) RETURNS varchar(5) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
    READS SQL DATA
BEGIN
  -- 'Kerja' bila ada jadwal Kerja miliknya sendiri (tidak digantikan) di penempatan mana pun pada tanggal itu.
  -- 'Libur' bila hanya Libur, jadwalnya digantikan rekan, atau ia bertugas sebagai pengganti (backup shift),
  -- sehingga absensi backup dibayar sebagai upah_backup. NULL bila belum dijadwalkan.
  RETURN (
    SELECT CASE WHEN SUM(j.status = 'Kerja' AND j.id_karyawan_pengganti IS NULL AND p.id_karyawan = p_id_karyawan) > 0 THEN 'Kerja'
                WHEN COUNT(*) > 0 THEN 'Libur' END
    FROM tbl_jadwal_kerja j
    JOIN tbl_penempatan p ON p.id = j.id_penempatan
    WHERE (p.id_karyawan = p_id_karyawan OR j.id_karyawan_pengganti = p_id_karyawan) AND j.tanggal_kerja = p_tgl
  );
END //

DELIMITER ;

-- [TAHAP 5]
DELIMITER //

DROP TRIGGER IF EXISTS trg_penempatan_batalkan_backup //
CREATE TRIGGER trg_penempatan_batalkan_backup AFTER UPDATE ON tbl_penempatan FOR EACH ROW FOLLOWS trg_penempatan_log_ubah BEGIN
  IF NOT (NEW.status <=> OLD.status) OR NOT (NEW.tgl_selesai <=> OLD.tgl_selesai) OR NOT (NEW.tgl_mulai <=> OLD.tgl_mulai) THEN
    -- Jadwal pengaju berada di penempatan yang berakhir/dirotasi.
    UPDATE tbl_pertukaran_shift ps
    JOIN tbl_jadwal_kerja j ON j.id = ps.id_jadwal_pengaju
    SET ps.status = 'Dibatalkan',
        ps.catatan_keputusan = 'Dibatalkan otomatis: penempatan pengaju berakhir atau dirotasi.',
        ps.diproses_pada = NOW(), ps.updated_at = NOW()
    WHERE ps.status = 'Menunggu' AND j.id_penempatan = NEW.id
      AND (NEW.status <> 'Aktif' OR j.tanggal_kerja < NEW.tgl_mulai
           OR (NEW.tgl_selesai IS NOT NULL AND j.tanggal_kerja > NEW.tgl_selesai));

    -- Rekan tidak lagi punya penempatan aktif pada tanggal shift yang diminta.
    UPDATE tbl_pertukaran_shift ps
    SET ps.status = 'Dibatalkan',
        ps.catatan_keputusan = 'Dibatalkan otomatis: penempatan rekan pengganti berakhir atau dirotasi.',
        ps.diproses_pada = NOW(), ps.updated_at = NOW()
    WHERE ps.status = 'Menunggu' AND ps.id_karyawan_rekan = NEW.id_karyawan
      AND NOT EXISTS (SELECT 1 FROM tbl_penempatan p
                      WHERE p.id_karyawan = NEW.id_karyawan AND p.status = 'Aktif'
                        AND p.tgl_mulai <= ps.tanggal_kerja AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= ps.tanggal_kerja));
  END IF;
END //

DELIMITER ;

-- [TAHAP 6]
DELIMITER //

ALTER TABLE tbl_koreksi_check_out
  ADD CONSTRAINT chk_koreksi_check_out_diproses CHECK (
    (status = 'Menunggu' AND diproses_oleh IS NULL AND diproses_pada IS NULL)
    OR (status <> 'Menunggu' AND diproses_oleh IS NOT NULL AND diproses_pada IS NOT NULL)
  ) //

DELIMITER ;

-- [TAHAP 7]
-- Monitoring tanpa interval & tanpa tahapan: Berkala = laporan bebas waktu dengan nomor urut (periode_ke) per sesi,
-- Perjalanan (Driver) = cukup keterangan. periode_mulai/periode_selesai/tahapan hanya terisi pada data lama.
DELIMITER //

ALTER TABLE tbl_laporan_aktivitas
  DROP CONSTRAINT chk_laporan_jenis,
  ADD CONSTRAINT chk_laporan_jenis CHECK (
    (jenis = 'Berkala' AND periode_ke IS NOT NULL AND tahapan IS NULL)
    OR (jenis = 'Perjalanan' AND periode_ke IS NULL AND periode_mulai IS NULL AND periode_selesai IS NULL)
  ) //

DELIMITER ;

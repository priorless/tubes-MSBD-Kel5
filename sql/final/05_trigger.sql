-- ============================================================
-- sql/final/05_trigger.sql — Trigger
-- Diekspor dari database db_sji (MySQL 8) setelah migration terakhir (sql/Komponen/24). DEFINER dihapus agar bisa
-- diimpor di komputer lain. Urutan impor: lihat sql/README.md.
-- ============================================================

-- Charset koneksi saat impor: klien mysql di Windows memakai cp850 bila tidak diatur.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS `trg_validasi_absen_masuk` //
CREATE TRIGGER `trg_validasi_absen_masuk` BEFORE INSERT ON `tbl_absensi` FOR EACH ROW BEGIN
  DECLARE v_kategori VARCHAR(10);
  DECLARE v_pen INT;
  DECLARE v_telat INT;
  DECLARE v_tidak_ada INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_tidak_ada = 1;

  IF NEW.jam_masuk IS NOT NULL THEN
    SELECT kategori INTO v_kategori FROM tbl_karyawan WHERE id = NEW.id_karyawan;

    IF NEW.id_penempatan IS NULL THEN
      SELECT p.id INTO v_pen
      FROM tbl_penempatan p
      WHERE p.id_karyawan = NEW.id_karyawan AND p.status = 'Aktif'
        AND p.tgl_mulai <= NEW.tgl AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= NEW.tgl)
      ORDER BY p.tgl_mulai DESC, p.id DESC
      LIMIT 1;
      SET NEW.id_penempatan = v_pen;
    END IF;

    IF NEW.foto_masuk IS NOT NULL THEN
      IF fn_id_absensi_aktif(NEW.id_karyawan) IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Anda masih tercatat bekerja pada shift sebelumnya. Lakukan check-out terlebih dahulu.';
      END IF;

      IF v_kategori = 'Lapangan' AND NEW.id_penempatan IS NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Anda belum memiliki penempatan aktif. Absensi dapat dilakukan setelah ditempatkan oleh bagian Operasional.';
      END IF;
    END IF;

    IF NEW.status_kehadiran = 'Hadir' THEN
      SET v_telat = fn_menit_terlambat(fn_jadwal_absensi(NEW.id_penempatan, NEW.tgl, 'datang'), NEW.jam_masuk);
      IF v_telat IS NOT NULL AND v_telat > 30 THEN
        SET NEW.status_kehadiran = 'Terlambat';
        SET NEW.menit_terlambat = v_telat;
      END IF;
    END IF;
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_cek_penempatan_absensi_insert` //
CREATE TRIGGER `trg_cek_penempatan_absensi_insert` BEFORE INSERT ON `tbl_absensi` FOR EACH ROW BEGIN
  CALL sp_cek_penempatan_absensi(NEW.id_karyawan, NEW.id_penempatan, NEW.tgl);
END //

DROP TRIGGER IF EXISTS `trg_auto_hitung_lembur` //
CREATE TRIGGER `trg_auto_hitung_lembur` BEFORE UPDATE ON `tbl_absensi` FOR EACH ROW BEGIN
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
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_cek_penempatan_absensi_update` //
CREATE TRIGGER `trg_cek_penempatan_absensi_update` BEFORE UPDATE ON `tbl_absensi` FOR EACH ROW BEGIN
  IF NOT (NEW.id_karyawan <=> OLD.id_karyawan) OR NOT (NEW.id_penempatan <=> OLD.id_penempatan) OR NOT (NEW.tgl <=> OLD.tgl) THEN
    CALL sp_cek_penempatan_absensi(NEW.id_karyawan, NEW.id_penempatan, NEW.tgl);
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_mutasi_invoice_lunas` //
CREATE TRIGGER `trg_mutasi_invoice_lunas` AFTER UPDATE ON `tbl_invoice` FOR EACH ROW BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_invoice', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status invoice ', NEW.nomor_invoice, ': ', OLD.status, ' -> ', NEW.status), 255));
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_cek_jadwal_kerja_insert` //
CREATE TRIGGER `trg_cek_jadwal_kerja_insert` BEFORE INSERT ON `tbl_jadwal_kerja` FOR EACH ROW BEGIN
  IF NEW.id_karyawan_pengganti IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengganti shift hanya dapat ditetapkan melalui pengajuan backup shift yang disetujui.';
  END IF;
  CALL sp_cek_jadwal_kerja(NULL, NEW.id_penempatan, NEW.tanggal_kerja, NEW.status, NEW.jam_masuk, NEW.jam_pulang, NULL);
END //

DROP TRIGGER IF EXISTS `trg_cek_jadwal_kerja_update` //
CREATE TRIGGER `trg_cek_jadwal_kerja_update` BEFORE UPDATE ON `tbl_jadwal_kerja` FOR EACH ROW BEGIN
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

DROP TRIGGER IF EXISTS `trg_set_status_karyawan_baru` //
CREATE TRIGGER `trg_set_status_karyawan_baru` BEFORE INSERT ON `tbl_karyawan` FOR EACH ROW BEGIN
  IF NEW.kategori = 'Internal' THEN
    IF NEW.gaji_pokok IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Karyawan Internal wajib diisi gaji_pokoknya';
    END IF;
    SET NEW.status = 'Aktif';
    SET NEW.id_jenis_layanan = NULL;
  ELSE
    IF NEW.id_jenis_layanan IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Karyawan Lapangan wajib dipilih jenis layanannya';
    END IF;
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_karyawan_validasi_status` //
CREATE TRIGGER `trg_karyawan_validasi_status` BEFORE UPDATE ON `tbl_karyawan` FOR EACH ROW BEGIN
  DECLARE v_penempatan_aktif INT;

  IF NOT (NEW.status <=> OLD.status) THEN
    IF NEW.kategori = 'Internal' THEN
      IF NEW.status NOT IN ('Aktif', 'Cuti', 'Nonaktif') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status karyawan Internal hanya boleh Aktif, Cuti, atau Nonaktif';
      END IF;
    ELSE
      SET v_penempatan_aktif = (SELECT COUNT(*) FROM tbl_penempatan
                                WHERE id_karyawan = NEW.id AND status = 'Aktif');
      IF NEW.status = 'Ditempatkan' THEN
        IF v_penempatan_aktif = 0 THEN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status Ditempatkan hanya diatur otomatis saat penempatan dibuat oleh Operasional';
        END IF;
      ELSEIF NEW.status NOT IN ('Tersedia', 'Cuti', 'Nonaktif') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status karyawan Lapangan hanya boleh Tersedia, Cuti, atau Nonaktif';
      ELSEIF v_penempatan_aktif > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Status tidak dapat diubah karena karyawan masih memiliki penempatan Aktif. Akhiri penempatan terlebih dahulu';
      END IF;
    END IF;
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_auto_akun_karyawan` //
CREATE TRIGGER `trg_auto_akun_karyawan` AFTER INSERT ON `tbl_karyawan` FOR EACH ROW BEGIN
  DECLARE v_role INT;

  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_karyawan', 'INSERT', NEW.id,
          LEFT(CONCAT('Karyawan baru ', NEW.nip, ' - ', NEW.nama, ' (', NEW.kategori, ', ', NEW.status, ')'), 255));

  IF NEW.kategori = 'Lapangan' THEN
    SELECT id INTO v_role FROM tbl_roles WHERE nama_role = 'Karyawan Lapangan';
    INSERT INTO tbl_users (id_karyawan, username, password, id_role)
    VALUES (NEW.id, NEW.nip, SHA2(NEW.nip, 256), v_role);
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_karyawan_sinkron_akun` //
CREATE TRIGGER `trg_karyawan_sinkron_akun` AFTER UPDATE ON `tbl_karyawan` FOR EACH ROW BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_karyawan', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status karyawan ', NEW.nip, ' - ', NEW.nama, ': ', OLD.status, ' -> ', NEW.status), 255));

    IF NEW.status = 'Nonaktif' THEN
      UPDATE tbl_users SET status = 'Nonaktif' WHERE id_karyawan = NEW.id;
    ELSEIF OLD.status = 'Nonaktif' AND NEW.status IN ('Tersedia', 'Aktif') THEN
      UPDATE tbl_users SET status = 'Aktif' WHERE id_karyawan = NEW.id;
    END IF;
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_karyawan_log_hapus` //
CREATE TRIGGER `trg_karyawan_log_hapus` AFTER DELETE ON `tbl_karyawan` FOR EACH ROW BEGIN
  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_karyawan', 'DELETE', OLD.id,
          LEFT(CONCAT('Karyawan dihapus ', OLD.nip, ' - ', OLD.nama, ' (', OLD.kategori, ')'), 255));
END //

DROP TRIGGER IF EXISTS `trg_klien_log_tambah` //
CREATE TRIGGER `trg_klien_log_tambah` AFTER INSERT ON `tbl_klien` FOR EACH ROW BEGIN
  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_klien', 'INSERT', NEW.id,
          LEFT(CONCAT('Klien baru ', NEW.kode_klien, ' - ', NEW.nama_perusahaan, ' (', NEW.status, ')'), 255));
END //

DROP TRIGGER IF EXISTS `trg_klien_log_ubah` //
CREATE TRIGGER `trg_klien_log_ubah` AFTER UPDATE ON `tbl_klien` FOR EACH ROW BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_klien', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status klien ', NEW.kode_klien, ' - ', NEW.nama_perusahaan, ': ', OLD.status, ' -> ', NEW.status), 255));
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_koreksi_check_out_insert` //
CREATE TRIGGER `trg_koreksi_check_out_insert` BEFORE INSERT ON `tbl_koreksi_check_out` FOR EACH ROW BEGIN
  CALL sp_cek_koreksi_check_out(NEW.id_absensi, NEW.jam_keluar);
END //

DROP TRIGGER IF EXISTS `trg_koreksi_check_out_update` //
CREATE TRIGGER `trg_koreksi_check_out_update` BEFORE UPDATE ON `tbl_koreksi_check_out` FOR EACH ROW BEGIN
  -- Diperiksa ulang saat disetujui, termasuk pengajuan yang dibuat sebelum aturan ini ada.
  IF (NEW.status = 'Disetujui' AND OLD.status <> 'Disetujui')
     OR NOT (NEW.jam_keluar <=> OLD.jam_keluar) OR NOT (NEW.id_absensi <=> OLD.id_absensi) THEN
    CALL sp_cek_koreksi_check_out(NEW.id_absensi, NEW.jam_keluar);
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_log_aktivitas_tolak_update` //
CREATE TRIGGER `trg_log_aktivitas_tolak_update` BEFORE UPDATE ON `tbl_log_aktivitas` FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Log aktivitas tidak boleh diubah karena merupakan jejak audit';
END //

DROP TRIGGER IF EXISTS `trg_log_aktivitas_tolak_delete` //
CREATE TRIGGER `trg_log_aktivitas_tolak_delete` BEFORE DELETE ON `tbl_log_aktivitas` FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Log aktivitas tidak boleh dihapus karena merupakan jejak audit';
END //

DROP TRIGGER IF EXISTS `trg_mutasi_payroll_cair` //
CREATE TRIGGER `trg_mutasi_payroll_cair` AFTER UPDATE ON `tbl_payroll` FOR EACH ROW BEGIN
  IF NOT (NEW.status_bayar <=> OLD.status_bayar) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_payroll', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status bayar payroll ', LPAD(NEW.periode_bulan, 2, '0'), '/', NEW.periode_tahun, ' karyawan #', NEW.id_karyawan,
                        ': ', OLD.status_bayar, ' -> ', NEW.status_bayar), 255));
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_penempatan_log_tambah` //
CREATE TRIGGER `trg_penempatan_log_tambah` AFTER INSERT ON `tbl_penempatan` FOR EACH ROW BEGIN
  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_penempatan', 'INSERT', NEW.id,
          LEFT(CONCAT('Penempatan baru: ',
                      IFNULL((SELECT CONCAT(nip, ' - ', nama) FROM tbl_karyawan WHERE id = NEW.id_karyawan), CONCAT('karyawan #', NEW.id_karyawan)),
                      ' ke ', IFNULL((SELECT nama_perusahaan FROM tbl_klien WHERE id = NEW.id_klien), CONCAT('klien #', NEW.id_klien)),
                      ' mulai ', NEW.tgl_mulai), 255));
END //

DROP TRIGGER IF EXISTS `trg_penempatan_log_ubah` //
CREATE TRIGGER `trg_penempatan_log_ubah` AFTER UPDATE ON `tbl_penempatan` FOR EACH ROW BEGIN
  IF NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_penempatan', 'UPDATE', NEW.id,
            LEFT(CONCAT('Status penempatan #', NEW.id, ' (',
                        IFNULL((SELECT nama FROM tbl_karyawan WHERE id = NEW.id_karyawan), CONCAT('karyawan #', NEW.id_karyawan)),
                        '): ', OLD.status, ' -> ', NEW.status,
                        IF(NEW.tgl_selesai IS NULL, '', CONCAT(', selesai ', NEW.tgl_selesai))), 255));
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_penempatan_batalkan_backup` //
CREATE TRIGGER `trg_penempatan_batalkan_backup` AFTER UPDATE ON `tbl_penempatan` FOR EACH ROW BEGIN
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

DROP TRIGGER IF EXISTS `trg_pengajuan_ketidakhadiran_insert` //
CREATE TRIGGER `trg_pengajuan_ketidakhadiran_insert` BEFORE INSERT ON `tbl_pengajuan_ketidakhadiran` FOR EACH ROW BEGIN
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

DROP TRIGGER IF EXISTS `trg_pengajuan_ketidakhadiran_update` //
CREATE TRIGGER `trg_pengajuan_ketidakhadiran_update` BEFORE UPDATE ON `tbl_pengajuan_ketidakhadiran` FOR EACH ROW BEGIN
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

DROP TRIGGER IF EXISTS `trg_pertukaran_shift_insert` //
CREATE TRIGGER `trg_pertukaran_shift_insert` BEFORE INSERT ON `tbl_pertukaran_shift` FOR EACH ROW BEGIN
  IF NEW.status <> 'Menunggu' OR NEW.status_rekan <> 'Menunggu' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pengajuan backup shift baru harus berstatus Menunggu.';
  END IF;
  CALL sp_cek_pertukaran_shift(NEW.id_jadwal_pengaju, NEW.id_karyawan_pengaju, NEW.id_karyawan_rekan, NEW.tanggal_kerja);
END //

DROP TRIGGER IF EXISTS `trg_pertukaran_shift_update` //
CREATE TRIGGER `trg_pertukaran_shift_update` BEFORE UPDATE ON `tbl_pertukaran_shift` FOR EACH ROW BEGIN
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

DROP TRIGGER IF EXISTS `trg_users_log_tambah` //
CREATE TRIGGER `trg_users_log_tambah` AFTER INSERT ON `tbl_users` FOR EACH ROW BEGIN
  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_users', 'INSERT', NEW.id,
          LEFT(CONCAT('Akun baru ', NEW.username, ' (role ',
                      IFNULL((SELECT nama_role FROM tbl_roles WHERE id = NEW.id_role), NEW.id_role), ', ', NEW.status, ')'), 255));
END //

DROP TRIGGER IF EXISTS `trg_users_log_ubah` //
CREATE TRIGGER `trg_users_log_ubah` AFTER UPDATE ON `tbl_users` FOR EACH ROW BEGIN
  IF NOT (NEW.id_role <=> OLD.id_role) OR NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
    VALUES (@app_user_id, 'tbl_users', 'UPDATE', NEW.id,
            LEFT(CONCAT('Akun ', NEW.username, ': ', CONCAT_WS('; ',
                 IF(NEW.id_role <=> OLD.id_role, NULL,
                    CONCAT('role ', IFNULL((SELECT nama_role FROM tbl_roles WHERE id = OLD.id_role), OLD.id_role),
                           ' -> ', IFNULL((SELECT nama_role FROM tbl_roles WHERE id = NEW.id_role), NEW.id_role))),
                 IF(NEW.status <=> OLD.status, NULL, CONCAT('status ', OLD.status, ' -> ', NEW.status)))), 255));
  END IF;
END //

DROP TRIGGER IF EXISTS `trg_users_log_hapus` //
CREATE TRIGGER `trg_users_log_hapus` AFTER DELETE ON `tbl_users` FOR EACH ROW BEGIN
  INSERT INTO tbl_log_aktivitas (id_user, nama_tabel, aksi, id_data, keterangan)
  VALUES (@app_user_id, 'tbl_users', 'DELETE', OLD.id,
          LEFT(CONCAT('Akun dihapus ', OLD.username), 255));
END //

DELIMITER ;

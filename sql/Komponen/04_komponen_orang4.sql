-- ============================================================
-- /sql/komponen/04_komponen_orang4.sql  (Modul Tenaga Kerja: absensi)
-- ============================================================
DELIMITER //

CREATE TRIGGER trg_cegah_absen_ganda
BEFORE INSERT ON tbl_absensi
FOR EACH ROW
BEGIN
  DECLARE v_ada INT;
  SELECT COUNT(*) INTO v_ada FROM tbl_absensi
  WHERE id_karyawan = NEW.id_karyawan AND tgl = NEW.tgl;
  IF v_ada > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Karyawan sudah absen pada tanggal ini';
  END IF;
END //

CREATE TRIGGER trg_auto_hitung_lembur
BEFORE UPDATE ON tbl_absensi
FOR EACH ROW
BEGIN
  IF NEW.jam_keluar IS NOT NULL AND OLD.jam_keluar IS NULL THEN
    SET NEW.jam_lembur = GREATEST(0, TIMESTAMPDIFF(HOUR, NEW.jam_masuk, NEW.jam_keluar) - 8);
  END IF;
END //

CREATE FUNCTION fn_hitung_jam_kerja(p_jam_masuk TIME, p_jam_keluar TIME)
RETURNS INT
DETERMINISTIC
BEGIN
  RETURN GREATEST(0, TIMESTAMPDIFF(HOUR, p_jam_masuk, p_jam_keluar));
END //

CREATE VIEW v_rekap_absensi_pribadi AS
SELECT k.id AS id_karyawan, k.nama,
       MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun,
       COUNT(*) AS total_baris,
       SUM(a.status_kehadiran = 'Hadir') AS hari_hadir,
       SUM(a.status_kehadiran = 'Terlambat') AS hari_terlambat,
       SUM(a.status_kehadiran IN ('Izin','Sakit','Alpha')) AS hari_tidak_hadir,
       SUM(a.jam_lembur) AS total_jam_lembur
FROM tbl_absensi a
JOIN tbl_karyawan k ON a.id_karyawan = k.id
GROUP BY k.id, k.nama, MONTH(a.tgl), YEAR(a.tgl) //

DELIMITER ;
-- Cara tes: insert absen karyawan+tanggal sama dua kali -> yang kedua ditolak.
-- Update jam_keluar dari 07:00 ke 18:00 -> jam_lembur harus terisi 3.
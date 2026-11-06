-- ============================================================
-- /sql/komponen/01_komponen_orang1.sql  (Modul HRD)
-- ============================================================
DELIMITER //

-- Status & validasi otomatis saat karyawan baru diinput
CREATE TRIGGER trg_set_status_karyawan_baru
BEFORE INSERT ON tbl_karyawan
FOR EACH ROW
BEGIN
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

-- Akun login otomatis untuk karyawan lapangan baru.
-- Akun staff internal dibuat manual lewat form HRD karena role-nya dipilih.
CREATE TRIGGER trg_auto_akun_karyawan
AFTER INSERT ON tbl_karyawan
FOR EACH ROW
BEGIN
  DECLARE v_role INT;
  IF NEW.kategori = 'Lapangan' THEN
    SELECT id INTO v_role FROM tbl_roles WHERE nama_role = 'Karyawan Lapangan';
    INSERT INTO tbl_users (id_karyawan, username, password, id_role)
    VALUES (NEW.id, NEW.nip, SHA2(NEW.nip, 255), v_role);
  END IF;
END //

CREATE FUNCTION fn_sisa_hari_dokumen(p_tgl_kadaluarsa DATE)
RETURNS INT
DETERMINISTIC
BEGIN
  RETURN GREATEST(0, DATEDIFF(p_tgl_kadaluarsa, CURDATE()));
END //

CREATE VIEW v_pool_karyawan_hrd AS
SELECT k.id, k.nip, k.nama, jl.nama_layanan, k.status, k.rating, k.tgl_masuk
FROM tbl_karyawan k
JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
WHERE k.kategori = 'Lapangan' AND k.status = 'Tersedia' //

DELIMITER ;
-- Cara tes: INSERT karyawan Internal tanpa gaji_pokok -> harus ditolak.
-- INSERT karyawan Lapangan -> cek tbl_users, akun harus muncul sendiri.
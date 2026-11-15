-- ============================================================
-- /sql/komponen/03_komponen_orang3.sql  (Modul Keuangan: kalkulator & slip)
-- ============================================================
DELIMITER //

-- Aturan owner SJI: absen 1 hari = gaji harian dipotong
CREATE FUNCTION fn_hitung_potongan_absen(
  p_gaji_pokok DECIMAL(12,2), p_hari_kerja INT, p_jumlah_absen INT)
RETURNS DECIMAL(12,2)
DETERMINISTIC
BEGIN
  RETURN ROUND((p_gaji_pokok / p_hari_kerja) * p_jumlah_absen, 2);
END //

-- Tarif lembur Rp 25.000 per jam (sepakati di notulen kalau mau diubah)
CREATE FUNCTION fn_hitung_lembur(p_jam_lembur INT)
RETURNS DECIMAL(12,2)
DETERMINISTIC
BEGIN
  RETURN p_jam_lembur * 25000;
END //

CREATE TRIGGER trg_update_rating
AFTER INSERT ON tbl_evaluasi_kinerja
FOR EACH ROW
BEGIN
  UPDATE tbl_karyawan
  SET rating = (SELECT ROUND(AVG(rating), 1)
                FROM tbl_evaluasi_kinerja
                WHERE id_karyawan = NEW.id_karyawan)
  WHERE id = NEW.id_karyawan;
END //

CREATE VIEW v_slip_gaji_detail AS
SELECT p.id, k.nip, k.nama, k.kategori,
       p.periode_bulan, p.periode_tahun,
       p.gaji_pokok, p.total_lembur, p.potongan_absen,
       p.total_gaji, p.status_bayar, p.tgl_bayar
FROM tbl_payroll p
JOIN tbl_karyawan k ON p.id_karyawan = k.id //

DELIMITER ;
-- Cara tes: SELECT fn_hitung_potongan_absen(3500000, 25, 1); -> harus 140000.
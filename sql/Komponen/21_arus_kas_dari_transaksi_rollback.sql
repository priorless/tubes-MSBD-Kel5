-- ============================================================
-- 21_arus_kas_dari_transaksi_rollback.sql — Kebalikan 21_arus_kas_dari_transaksi.sql:
-- arus kas kembali hanya dari tbl_mutasi_keuangan.
-- ============================================================

-- [TAHAP 1]
DELIMITER //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_arus_kas_bulanan AS
SELECT YEAR(tgl_mutasi) AS tahun,
       MONTH(tgl_mutasi) AS bulan,
       SUM(CASE WHEN jenis = 'Pemasukan' THEN nominal ELSE 0 END) AS total_masuk,
       SUM(CASE WHEN jenis = 'Pengeluaran' THEN nominal ELSE 0 END) AS total_keluar,
       SUM(CASE WHEN jenis = 'Pemasukan' THEN nominal ELSE -nominal END) AS selisih
FROM tbl_mutasi_keuangan
GROUP BY YEAR(tgl_mutasi), MONTH(tgl_mutasi) //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_dashboard_eksekutif AS
SELECT
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Lapangan' AND status = 'Ditempatkan') AS lapangan_bertugas,
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Lapangan' AND status = 'Tersedia') AS lapangan_siap,
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Internal' AND status = 'Aktif') AS internal_aktif,
  fn_total_piutang() AS total_piutang,
  (SELECT COALESCE(SUM(CASE WHEN jenis = 'Pemasukan' THEN nominal ELSE -nominal END), 0)
     FROM tbl_mutasi_keuangan
    WHERE tgl_mutasi BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())) AS profit_bulan_berjalan //

DELIMITER ;

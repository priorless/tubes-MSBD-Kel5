-- ============================================================
-- 21_arus_kas_dari_transaksi.sql — Arus kas dihitung langsung dari transaksi yang sudah dibayar.
--   Pemasukan  : tbl_invoice  status = 'Lunas'        -> total_tagihan pada tgl_pembayaran
--   Pengeluaran: tbl_payroll  status_bayar = 'Sudah Lunas' -> total_gaji (gaji bersih) pada tgl_bayar
--   Ditambah baris lama tbl_mutasi_keuangan (sejak file 16 trigger tidak lagi menulis ke tabel ini,
--   sehingga tidak ada transaksi yang terhitung dua kali).
-- Berlaku untuk v_arus_kas_bulanan dan kolom profit_bulan_berjalan di v_dashboard_eksekutif.
-- Rollback: 21_arus_kas_dari_transaksi_rollback.sql
-- ============================================================

-- [TAHAP 1]
DELIMITER //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_arus_kas_bulanan AS
SELECT YEAR(kas.tgl) AS tahun,
       MONTH(kas.tgl) AS bulan,
       SUM(kas.masuk) AS total_masuk,
       SUM(kas.keluar) AS total_keluar,
       SUM(kas.masuk - kas.keluar) AS selisih
FROM (
    SELECT i.tgl_pembayaran AS tgl, i.total_tagihan AS masuk, 0 AS keluar
    FROM tbl_invoice i
    WHERE i.status = 'Lunas' AND i.tgl_pembayaran IS NOT NULL
    UNION ALL
    SELECT p.tgl_bayar, 0, p.total_gaji
    FROM tbl_payroll p
    WHERE p.status_bayar = 'Sudah Lunas' AND p.tgl_bayar IS NOT NULL
    UNION ALL
    SELECT m.tgl_mutasi,
           CASE WHEN m.jenis = 'Pemasukan' THEN m.nominal ELSE 0 END,
           CASE WHEN m.jenis = 'Pengeluaran' THEN m.nominal ELSE 0 END
    FROM tbl_mutasi_keuangan m
) AS kas
GROUP BY YEAR(kas.tgl), MONTH(kas.tgl) //

CREATE OR REPLACE SQL SECURITY DEFINER VIEW v_dashboard_eksekutif AS
SELECT
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Lapangan' AND status = 'Ditempatkan') AS lapangan_bertugas,
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Lapangan' AND status = 'Tersedia') AS lapangan_siap,
  (SELECT COUNT(*) FROM tbl_karyawan WHERE kategori = 'Internal' AND status = 'Aktif') AS internal_aktif,
  fn_total_piutang() AS total_piutang,
  (
    (SELECT COALESCE(SUM(total_tagihan), 0) FROM tbl_invoice
      WHERE status = 'Lunas' AND tgl_pembayaran BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE()))
    - (SELECT COALESCE(SUM(total_gaji), 0) FROM tbl_payroll
      WHERE status_bayar = 'Sudah Lunas' AND tgl_bayar BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE()))
    + (SELECT COALESCE(SUM(CASE WHEN jenis = 'Pemasukan' THEN nominal ELSE -nominal END), 0) FROM tbl_mutasi_keuangan
      WHERE tgl_mutasi BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE()))
  ) AS profit_bulan_berjalan //

DELIMITER ;

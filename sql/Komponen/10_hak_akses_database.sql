-- ============================================================
-- /sql/komponen/10_hak_akses_database.sql
-- Manajemen hak akses MySQL (DCL): satu ROLE per bagian, prinsip hak minimum.
--
-- JALANKAN PALING TERAKHIR (setelah file 01-09 dan 11), dengan database db_sji terpilih (USE db_sji).
-- Di aplikasi cukup: php artisan migrate (migrasi 2026_11_15_112908_hak_akses_database; setiap
-- migrasi 2026_11_16_* yang menambah/menghapus objek juga memasang ulang file ini).
-- File ini AMAN dijalankan ulang: setiap role dicabut semua haknya dulu, lalu diberi ulang,
-- sehingga isi file ini selalu menjadi satu-satunya sumber kebenaran hak akses.
--
-- File ini TIDAK membuat user dan TIDAK berisi password (repo public).
-- User per bagian dibuat dengan:  php artisan sji:buat-user-db
-- (password acak, ditampilkan sekali di terminal).
--
-- Prinsip:
--   1. Tidak ada role yang boleh CREATE/DROP/ALTER/GRANT.
--   2. Perubahan data penting (penempatan, kontrak, payroll, invoice, buku kas) HANYA lewat
--      stored procedure / trigger. Semua procedure, function, dan view di db_sji memakai
--      SQL SECURITY DEFINER, sehingga cukup diberi EXECUTE/SELECT tanpa hak ke tabel dasarnya.
--   3. Data pribadi (no_ktp, alamat, no_telp, email, tgl_lahir) dan tbl_users.password
--      hanya terlihat oleh HRD. Bagian lain diberi hak per KOLOM.
--   4. Karyawan Lapangan TIDAK diberi akun MySQL: view absensi/slip gaji berisi data semua
--      karyawan dan GRANT tidak bisa membatasi per baris. Pembatasan "data milik sendiri"
--      dilakukan aplikasi (App\Support\KaryawanAktif).
--   5. Tabel internal Laravel (users, sessions, cache, jobs, migrations, ...) tidak diberikan
--      ke role mana pun; hanya dipakai aplikasi lewat user root/aplikasi.
-- ============================================================

CREATE ROLE IF NOT EXISTS 'sji_role_hrd', 'sji_role_operasional', 'sji_role_keuangan', 'sji_role_ceo';

REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'sji_role_hrd', 'sji_role_operasional', 'sji_role_keuangan', 'sji_role_ceo';

-- ------------------------------------------------------------
-- HRD — rekrutmen, data karyawan, dokumen, akun login
-- ------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE, DELETE ON tbl_karyawan TO 'sji_role_hrd';
GRANT SELECT, INSERT, UPDATE, DELETE ON tbl_sertifikat TO 'sji_role_hrd';
GRANT SELECT, INSERT, UPDATE, DELETE ON tbl_users TO 'sji_role_hrd';
GRANT SELECT ON tbl_roles TO 'sji_role_hrd';
GRANT SELECT ON tbl_jenis_layanan TO 'sji_role_hrd';
GRANT SELECT ON tbl_klien TO 'sji_role_hrd';
GRANT SELECT ON tbl_penempatan TO 'sji_role_hrd';
GRANT SELECT ON tbl_absensi TO 'sji_role_hrd';
GRANT SELECT ON v_pool_karyawan_hrd TO 'sji_role_hrd';
GRANT SELECT ON v_absensi_harian TO 'sji_role_hrd';
GRANT SELECT ON v_rekap_absensi_pribadi TO 'sji_role_hrd';
-- Dokumentasi absensi (laporan aktivitas Satpam/Cleaning Service/Driver & P2H Driver): hanya baca.
GRANT SELECT ON tbl_laporan_aktivitas TO 'sji_role_hrd';
GRANT SELECT ON tbl_p2h TO 'sji_role_hrd';
GRANT SELECT ON tbl_p2h_item TO 'sji_role_hrd';
GRANT SELECT ON v_dashboard_eksekutif TO 'sji_role_hrd';
GRANT EXECUTE ON FUNCTION fn_sisa_hari_dokumen TO 'sji_role_hrd';
GRANT EXECUTE ON PROCEDURE sp_input_ketidakhadiran TO 'sji_role_hrd';

-- ------------------------------------------------------------
-- Operasional — klien, penempatan, rotasi, monitoring lapangan
-- Penempatan & kontrak TIDAK bisa diubah langsung: wajib lewat procedure.
-- ------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE, DELETE ON tbl_klien TO 'sji_role_operasional';
GRANT SELECT ON tbl_penempatan TO 'sji_role_operasional';
GRANT SELECT ON tbl_kontrak TO 'sji_role_operasional';
GRANT SELECT ON tbl_jenis_layanan TO 'sji_role_operasional';
GRANT SELECT (id, nip, nama, kategori, id_jenis_layanan, status, tgl_masuk)
  ON tbl_karyawan TO 'sji_role_operasional';
GRANT SELECT ON v_monitoring_sebaran_operasional TO 'sji_role_operasional';
GRANT SELECT ON v_penugasan_aktif TO 'sji_role_operasional';
GRANT SELECT ON v_pool_karyawan_hrd TO 'sji_role_operasional';
GRANT EXECUTE ON PROCEDURE sp_assign_penempatan TO 'sji_role_operasional';
GRANT EXECUTE ON PROCEDURE sp_rotasi_karyawan TO 'sji_role_operasional';
GRANT EXECUTE ON PROCEDURE sp_selesai_penempatan TO 'sji_role_operasional';
-- Jadwal Kerja (roster): tanpa DELETE, template dinonaktifkan dan riwayat hanya ditambah.
GRANT SELECT, INSERT, UPDATE ON tbl_template_shift TO 'sji_role_operasional';
GRANT SELECT, INSERT, UPDATE ON tbl_jadwal_kerja TO 'sji_role_operasional';
GRANT SELECT, INSERT ON tbl_jadwal_kerja_riwayat TO 'sji_role_operasional';
-- sp_cek_jadwal_kerja sengaja tanpa GRANT: hanya dipanggil trigger trg_cek_jadwal_kerja_insert/_update (hak definer).
-- Template Jadwal Bulanan: template dinonaktifkan (tanpa DELETE); DELETE pada detail diperlukan
-- karena mengosongkan hari = menghapus baris pola (Belum Diatur); log penerapan hanya ditambah.
GRANT SELECT, INSERT, UPDATE ON tbl_template_jadwal TO 'sji_role_operasional';
GRANT SELECT, INSERT, UPDATE, DELETE ON tbl_template_jadwal_detail TO 'sji_role_operasional';
GRANT SELECT, INSERT ON tbl_template_jadwal_penerapan TO 'sji_role_operasional';
-- Pertukaran Shift: Operasional hanya membaca dan memutuskan (UPDATE status); pengajuan dibuat karyawan.
GRANT SELECT, UPDATE ON tbl_pertukaran_shift TO 'sji_role_operasional';

-- ------------------------------------------------------------
-- Keuangan — payroll, invoice, buku kas
-- Payroll, invoice, dan mutasi kas TIDAK bisa diubah langsung: wajib lewat procedure/trigger.
-- Fungsi hari roster (fn_hari_kerja_roster[_klien], fn_hari_tanpa_jadwal[_klien], fn_status_roster,
-- fn_hari_kerja_kontrak, fn_hari_wajib_roster, fn_total_hari_wajib_roster) sengaja tanpa GRANT:
-- hanya dipanggil dari sp_generate_payroll_bulanan/sp_generate_invoice_klien (SQL SECURITY DEFINER).
-- ------------------------------------------------------------
GRANT SELECT ON tbl_payroll TO 'sji_role_keuangan';
GRANT SELECT, INSERT, UPDATE ON tbl_komponen_gaji TO 'sji_role_keuangan';
GRANT SELECT ON tbl_invoice TO 'sji_role_keuangan';
-- Lampiran invoice (snapshot dari payroll) dan catatan tukar shift pada lampiran: hanya dibaca.
GRANT SELECT ON tbl_invoice_detail TO 'sji_role_keuangan';
GRANT SELECT ON tbl_pertukaran_shift TO 'sji_role_keuangan';
-- Mode edit dokumen tagihan: teks surat & catatan manual per invoice, riwayat audit hanya ditambah.
GRANT SELECT, INSERT, UPDATE ON tbl_invoice_dokumen TO 'sji_role_keuangan';
GRANT SELECT, INSERT, UPDATE, DELETE ON tbl_invoice_catatan TO 'sji_role_keuangan';
GRANT SELECT, INSERT ON tbl_invoice_dokumen_riwayat TO 'sji_role_keuangan';
GRANT SELECT ON tbl_mutasi_keuangan TO 'sji_role_keuangan';
GRANT SELECT ON tbl_klien TO 'sji_role_keuangan';
GRANT SELECT ON tbl_kontrak TO 'sji_role_keuangan';
GRANT SELECT ON tbl_penempatan TO 'sji_role_keuangan';
GRANT SELECT ON tbl_jenis_layanan TO 'sji_role_keuangan';
GRANT SELECT (id, nip, nama, kategori, id_jenis_layanan, gaji_pokok, status)
  ON tbl_karyawan TO 'sji_role_keuangan';
GRANT SELECT (id, id_karyawan, id_penempatan, tgl, status_kehadiran, menit_terlambat, jam_lembur)
  ON tbl_absensi TO 'sji_role_keuangan';
GRANT SELECT ON v_slip_gaji_detail TO 'sji_role_keuangan';
GRANT SELECT ON v_arus_kas_bulanan TO 'sji_role_keuangan';
GRANT EXECUTE ON PROCEDURE sp_generate_payroll_bulanan TO 'sji_role_keuangan';
GRANT EXECUTE ON PROCEDURE sp_generate_invoice_klien TO 'sji_role_keuangan';
GRANT EXECUTE ON PROCEDURE sp_update_status_bayar TO 'sji_role_keuangan';
GRANT EXECUTE ON FUNCTION fn_total_piutang TO 'sji_role_keuangan';
GRANT EXECUTE ON FUNCTION fn_hitung_lembur TO 'sji_role_keuangan';
GRANT EXECUTE ON FUNCTION fn_hitung_potongan_absen TO 'sji_role_keuangan';

-- ------------------------------------------------------------
-- CEO — monitoring eksekutif, HANYA BACA, tanpa data pribadi & akun login
-- ------------------------------------------------------------
GRANT SELECT ON tbl_klien TO 'sji_role_ceo';
GRANT SELECT ON tbl_penempatan TO 'sji_role_ceo';
GRANT SELECT ON tbl_kontrak TO 'sji_role_ceo';
GRANT SELECT ON tbl_payroll TO 'sji_role_ceo';
GRANT SELECT ON tbl_invoice TO 'sji_role_ceo';
GRANT SELECT ON tbl_mutasi_keuangan TO 'sji_role_ceo';
GRANT SELECT ON tbl_jenis_layanan TO 'sji_role_ceo';
GRANT SELECT (id, nip, nama, kategori, id_jenis_layanan, gaji_pokok, status, tgl_masuk)
  ON tbl_karyawan TO 'sji_role_ceo';
GRANT SELECT ON v_dashboard_eksekutif TO 'sji_role_ceo';
GRANT SELECT ON v_arus_kas_bulanan TO 'sji_role_ceo';
GRANT SELECT ON v_monitoring_sebaran_operasional TO 'sji_role_ceo';
GRANT SELECT ON v_slip_gaji_detail TO 'sji_role_ceo';
GRANT SELECT ON tbl_log_aktivitas TO 'sji_role_ceo';
GRANT SELECT ON tbl_template_shift TO 'sji_role_ceo';
GRANT SELECT ON tbl_jadwal_kerja TO 'sji_role_ceo';
GRANT SELECT ON tbl_template_jadwal TO 'sji_role_ceo';
GRANT SELECT ON tbl_template_jadwal_detail TO 'sji_role_ceo';
GRANT SELECT ON tbl_template_jadwal_penerapan TO 'sji_role_ceo';
GRANT EXECUTE ON FUNCTION fn_profit_klien TO 'sji_role_ceo';
GRANT EXECUTE ON FUNCTION fn_total_piutang TO 'sji_role_ceo';

-- ------------------------------------------------------------
-- Contoh membuat user secara manual (JANGAN commit password asli; lebih baik pakai
-- php artisan sji:buat-user-db):
--   CREATE USER 'sji_hrd'@'localhost' IDENTIFIED BY '<password-kuat>';
--   GRANT 'sji_role_hrd' TO 'sji_hrd'@'localhost';
--   SET DEFAULT ROLE 'sji_role_hrd' TO 'sji_hrd'@'localhost';
-- Cek hak akses:  SHOW GRANTS FOR 'sji_hrd'@'localhost' USING 'sji_role_hrd';
-- ------------------------------------------------------------

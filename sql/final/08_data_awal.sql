-- ============================================================
-- sql/final/08_data_awal.sql — Data awal: tbl_roles, tbl_jenis_layanan, migrations (agar php artisan migrate tidak mengulang migrasi)
-- Diekspor dari database db_sji (MySQL 8) setelah migration terakhir (sql/Komponen/24). DEFINER dihapus agar bisa
-- diimpor di komputer lain. Urutan impor: lihat sql/README.md.
-- ============================================================

-- Charset koneksi saat impor: klien mysql di Windows memakai cp850 bila tidak diatur.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

LOCK TABLES `tbl_roles` WRITE;
INSERT INTO `tbl_roles` (`id`, `nama_role`, `deskripsi`) VALUES (1,'HRD','Rekrutmen, data karyawan, dokumen');
INSERT INTO `tbl_roles` (`id`, `nama_role`, `deskripsi`) VALUES (2,'Operasional','Penempatan, rotasi, monitoring lapangan');
INSERT INTO `tbl_roles` (`id`, `nama_role`, `deskripsi`) VALUES (3,'Keuangan','Payroll semua karyawan, invoice, pencatatan status pembayaran');
INSERT INTO `tbl_roles` (`id`, `nama_role`, `deskripsi`) VALUES (4,'Karyawan Lapangan','Absensi dan informasi pribadi satpam/driver/cleaning service');
INSERT INTO `tbl_roles` (`id`, `nama_role`, `deskripsi`) VALUES (5,'CEO','Monitoring eksekutif dan profitabilitas');
UNLOCK TABLES;

LOCK TABLES `tbl_jenis_layanan` WRITE;
INSERT INTO `tbl_jenis_layanan` (`id`, `nama_layanan`, `tarif_dasar`, `tarif_tagihan_klien`, `deskripsi`) VALUES (1,'Satpam',3500000.00,4500000.00,'Layanan keamanan lingkungan');
INSERT INTO `tbl_jenis_layanan` (`id`, `nama_layanan`, `tarif_dasar`, `tarif_tagihan_klien`, `deskripsi`) VALUES (2,'Driver',3200000.00,4200000.00,'Layanan pengemudi operasional');
INSERT INTO `tbl_jenis_layanan` (`id`, `nama_layanan`, `tarif_dasar`, `tarif_tagihan_klien`, `deskripsi`) VALUES (3,'Cleaning Service',2800000.00,3700000.00,'Layanan kebersihan gedung');
UNLOCK TABLES;

LOCK TABLES `migrations` WRITE;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_11_10_092842_add_auth_columns_to_tbl_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_11_14_161048_tambah_geofence_dan_shift_absensi',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_11_15_052355_add_jadwal_kerja_to_tbl_penempatan_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_11_15_064609_ganti_absensi_gps_menjadi_foto',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_11_15_085812_jadwal_absensi_hanya_dari_penempatan',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_11_15_000000_add_foto_checkin_to_tbl_absensi',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_11_15_071700_remove_tunjangan_from_payroll_calculation',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_11_15_111405_perbaikan_database',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_11_15_112908_hak_akses_database',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_11_16_070835_tahap1_hapus_fitur_rating',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_11_16_071548_tahap2_bersihkan_kolom_tak_terpakai',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_11_16_071754_tahap3_input_ketidakhadiran',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_11_16_072431_tahap4_status_karyawan_dan_akhiri_penempatan',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_11_16_073131_tahap5_payroll_invoice_prorata',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_11_16_074230_tahap6_persetujuan_lembur',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_11_16_080423_tahap7_status_sertifikat_otomatis',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_11_16_080731_tahap8_log_aktivitas',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_11_16_153307_align_sp_rotasi_karyawan_contract_dates',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_11_16_155958_create_jadwal_kerja_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_11_16_173550_create_template_jadwal_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_11_17_005858_create_sp_selesai_penempatan',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_11_17_050241_add_status_pernikahan_and_nama_ibu_kandung_to_tbl_karyawan_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_11_17_065725_add_management_fee_to_tbl_klien_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_11_17_065726_add_filter_ids_to_v_monitoring_sebaran_operasional',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_11_17_131228_add_jenis_jaminan_kesehatan_to_tbl_karyawan_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_11_17_132028_create_tbl_pertukaran_shift_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_11_17_134725_jadwal_absensi_dari_roster',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_11_17_230642_create_laporan_aktivitas_dan_p2h_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_11_17_230643_add_persetujuan_rekan_to_tbl_pertukaran_shift_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_11_18_000001_create_tbl_komponen_gaji',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_11_18_023510_add_tanggal_kerja_rekan_to_tbl_pertukaran_shift_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_11_18_041642_bpjs_klien_payroll_invoice',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_11_18_050000_gabung_komponen_gaji_bpjs_klien',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_11_18_060000_payroll_pph21_pencairan',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_11_19_000001_invoice_payroll_fee_ppn',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_11_19_000002_invoice_dokumen_edit',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_11_23_042014_finalisasi_tahap1_hapus_persetujuan_lembur',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_11_23_044935_finalisasi_tahap2_payroll_invoice_roster',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_11_23_050734_finalisasi_tahap3_aturan_jadwal_kerja',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_11_23_054448_finalisasi_tahap4_konsistensi_struktur',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_11_23_060544_finalisasi_tahap5_indeks_optimasi',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_11_23_062404_finalisasi_tahap7_teknologi_lanjutan',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_11_23_063356_finalisasi_tahap8_role_aplikasi',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_11_23_064517_finalisasi_tahap10_perbaikan_hari_tanpa_jadwal',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_11_23_081550_log_append_only',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_11_23_082124_perbaikan_jadwal_event_sertifikat',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_11_23_085129_arus_kas_dari_transaksi',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_11_25_043434_gaji_pokok_komponen_gaji',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_11_25_140336_backup_shift_dan_integritas_absensi',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_11_27_212431_sertifikat_ketidakhadiran_dan_integritas_absensi',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_11_27_220209_alpha_otomatis_shift_tanpa_absensi',10);
UNLOCK TABLES;

-- ============================================================
-- sql/final/08_data_awal.sql — Data awal: tbl_roles, tbl_jenis_layanan, migrations (agar php artisan migrate tidak mengulang migrasi)
-- Diekspor dari database db_sji (MySQL 8) setelah Finalisasi Tahap 1–8. DEFINER dihapus agar bisa diimpor
-- di komputer lain. Urutan impor: lihat sql/README.md.
-- ============================================================

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
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_11_10_092842_add_auth_columns_to_tbl_users_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_11_15_000000_add_foto_checkin_to_tbl_absensi',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_11_14_161048_tambah_geofence_dan_shift_absensi',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_11_15_052355_add_jadwal_kerja_to_tbl_penempatan_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_11_15_064609_ganti_absensi_gps_menjadi_foto',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_11_15_071700_remove_tunjangan_from_payroll_calculation',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_11_15_085812_jadwal_absensi_hanya_dari_penempatan',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_11_15_111405_perbaikan_database',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_11_15_112908_hak_akses_database',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_11_16_070835_tahap1_hapus_fitur_rating',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_11_16_071548_tahap2_bersihkan_kolom_tak_terpakai',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_11_16_071754_tahap3_input_ketidakhadiran',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_11_16_072431_tahap4_status_karyawan_dan_akhiri_penempatan',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_11_16_073131_tahap5_payroll_invoice_prorata',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_11_16_074230_tahap6_persetujuan_lembur',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_11_16_080423_tahap7_status_sertifikat_otomatis',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_11_16_080731_tahap8_log_aktivitas',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_11_16_153307_align_sp_rotasi_karyawan_contract_dates',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_11_16_155958_create_jadwal_kerja_tables',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_11_16_173550_create_template_jadwal_tables',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_11_17_005858_create_sp_selesai_penempatan',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_11_17_050241_add_status_pernikahan_and_nama_ibu_kandung_to_tbl_karyawan_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_11_17_065725_add_management_fee_to_tbl_klien_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_11_17_065726_add_filter_ids_to_v_monitoring_sebaran_operasional',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_11_17_132028_create_tbl_pertukaran_shift_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_11_17_134725_jadwal_absensi_dari_roster',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_11_18_023510_add_tanggal_kerja_rekan_to_tbl_pertukaran_shift_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_11_18_041642_bpjs_klien_payroll_invoice',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_11_17_131228_add_jenis_jaminan_kesehatan_to_tbl_karyawan_table',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_11_17_230642_create_laporan_aktivitas_dan_p2h_tables',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_11_17_230643_add_persetujuan_rekan_to_tbl_pertukaran_shift_table',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_11_18_000001_create_tbl_komponen_gaji',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_11_18_050000_gabung_komponen_gaji_bpjs_klien',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_11_18_060000_payroll_pph21_pencairan',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_11_19_000001_invoice_payroll_fee_ppn',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_11_19_000002_invoice_dokumen_edit',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_11_23_042014_finalisasi_tahap1_hapus_persetujuan_lembur',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_11_23_044935_finalisasi_tahap2_payroll_invoice_roster',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_11_23_050734_finalisasi_tahap3_aturan_jadwal_kerja',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_11_23_054448_finalisasi_tahap4_konsistensi_struktur',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_11_23_060544_finalisasi_tahap5_indeks_optimasi',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_11_23_062404_finalisasi_tahap7_teknologi_lanjutan',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_11_23_063356_finalisasi_tahap8_role_aplikasi',24);
UNLOCK TABLES;

-- ============================================================
-- sql/final/01_tabel.sql — Struktur tabel
-- Diekspor dari database db_sji (MySQL 8) setelah Finalisasi Tahap 1–10. DEFINER dihapus agar bisa diimpor
-- di komputer lain. Urutan impor: lihat sql/README.md.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_absensi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_karyawan` int NOT NULL,
  `id_penempatan` int DEFAULT NULL,
  `tgl` date NOT NULL,
  `jam_masuk` time DEFAULT NULL,
  `jam_keluar` time DEFAULT NULL,
  `status_kehadiran` enum('Hadir','Terlambat','Izin','Sakit','Alpha') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Hadir',
  `menit_terlambat` smallint unsigned NOT NULL DEFAULT '0',
  `jam_lembur` int NOT NULL DEFAULT '0',
  `keterangan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_masuk` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_keluar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_absensi_karyawan_tgl` (`id_karyawan`,`tgl`),
  KEY `idx_absensi_tgl` (`tgl`),
  KEY `fk_absensi_penempatan` (`id_penempatan`),
  CONSTRAINT `fk_absensi_karyawan` FOREIGN KEY (`id_karyawan`) REFERENCES `tbl_karyawan` (`id`),
  CONSTRAINT `fk_absensi_penempatan` FOREIGN KEY (`id_penempatan`) REFERENCES `tbl_penempatan` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_invoice` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nomor_invoice` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_klien` int NOT NULL,
  `periode_bulan` int NOT NULL,
  `periode_tahun` int NOT NULL,
  `jumlah_tenaga` int NOT NULL,
  `bpjs_metode` enum('Persentase','Nominal') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bpjs_nilai` decimal(12,2) DEFAULT NULL,
  `biaya_bpjs` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sub_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `persen_fee` decimal(5,2) NOT NULL DEFAULT '0.00',
  `management_fee` decimal(14,2) NOT NULL DEFAULT '0.00',
  `ppn` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_tagihan` decimal(12,2) NOT NULL,
  `status` enum('Belum Lunas','Lunas') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Belum Lunas',
  `tgl_pembayaran` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_nomor` (`nomor_invoice`),
  UNIQUE KEY `uq_invoice_periode` (`id_klien`,`periode_bulan`,`periode_tahun`),
  KEY `idx_invoice_status` (`status`),
  CONSTRAINT `fk_invoice_klien` FOREIGN KEY (`id_klien`) REFERENCES `tbl_klien` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_invoice_catatan` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_invoice` int NOT NULL,
  `isi` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dibuat_oleh` int NOT NULL,
  `dibuat_pada` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `diubah_oleh` int DEFAULT NULL,
  `diubah_pada` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_catatan_invoice` (`id_invoice`),
  KEY `fk_invoice_catatan_dibuat_oleh` (`dibuat_oleh`),
  KEY `fk_invoice_catatan_diubah_oleh` (`diubah_oleh`),
  CONSTRAINT `fk_invoice_catatan_dibuat_oleh` FOREIGN KEY (`dibuat_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_invoice_catatan_diubah_oleh` FOREIGN KEY (`diubah_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoice_catatan_invoice` FOREIGN KEY (`id_invoice`) REFERENCES `tbl_invoice` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_invoice_detail` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_invoice` int NOT NULL,
  `id_payroll` int NOT NULL,
  `id_karyawan` int NOT NULL,
  `nip` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jabatan` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hari_klien` smallint unsigned NOT NULL,
  `hari_total` smallint unsigned NOT NULL,
  `gaji_pokok` decimal(14,2) NOT NULL DEFAULT '0.00',
  `tunjangan` decimal(14,2) NOT NULL DEFAULT '0.00',
  `upah_backup` decimal(14,2) NOT NULL DEFAULT '0.00',
  `upah_lembur` decimal(14,2) NOT NULL DEFAULT '0.00',
  `potongan_absen` decimal(14,2) NOT NULL DEFAULT '0.00',
  `biaya_tenaga_kerja` decimal(14,2) NOT NULL DEFAULT '0.00',
  `potongan_pinjaman` decimal(14,2) NOT NULL DEFAULT '0.00',
  `potongan_bpjs` decimal(14,2) NOT NULL DEFAULT '0.00',
  `pph21` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_penerimaan` decimal(14,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_detail_payroll` (`id_invoice`,`id_payroll`),
  KEY `idx_invoice_detail_payroll` (`id_payroll`),
  CONSTRAINT `fk_invoice_detail_invoice` FOREIGN KEY (`id_invoice`) REFERENCES `tbl_invoice` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_invoice_dokumen` (
  `id_invoice` int NOT NULL,
  `penerima` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hal` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paragraf_pembuka` text COLLATE utf8mb4_unicode_ci,
  `paragraf_penutup` text COLLATE utf8mb4_unicode_ci,
  `salam_penutup` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `versi` int unsigned NOT NULL DEFAULT '0',
  `diubah_oleh` int DEFAULT NULL,
  `diubah_pada` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id_invoice`),
  KEY `fk_invoice_dokumen_diubah_oleh` (`diubah_oleh`),
  CONSTRAINT `fk_invoice_dokumen_diubah_oleh` FOREIGN KEY (`diubah_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoice_dokumen_invoice` FOREIGN KEY (`id_invoice`) REFERENCES `tbl_invoice` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_invoice_dokumen_riwayat` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_invoice` int NOT NULL,
  `versi` int unsigned NOT NULL,
  `data_sebelum` json NOT NULL,
  `data_sesudah` json NOT NULL,
  `diubah_oleh` int NOT NULL,
  `waktu` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_riwayat_versi` (`id_invoice`,`versi`),
  KEY `fk_invoice_dokumen_riwayat_diubah_oleh` (`diubah_oleh`),
  CONSTRAINT `fk_invoice_dokumen_riwayat_diubah_oleh` FOREIGN KEY (`diubah_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_invoice_riwayat_invoice` FOREIGN KEY (`id_invoice`) REFERENCES `tbl_invoice` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_jadwal_kerja` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_penempatan` int NOT NULL,
  `tanggal_kerja` date NOT NULL,
  `status` enum('Kerja','Libur') COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_template_shift` int unsigned DEFAULT NULL,
  `nama_shift_snapshot` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jam_masuk` time DEFAULT NULL,
  `jam_pulang` time DEFAULT NULL,
  `durasi_menit` smallint unsigned DEFAULT NULL,
  `dibuat_oleh` int DEFAULT NULL,
  `diubah_oleh` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jadwal_penempatan_tanggal` (`id_penempatan`,`tanggal_kerja`),
  KEY `idx_jadwal_tanggal_penempatan` (`tanggal_kerja`,`id_penempatan`),
  KEY `fk_jadwal_template_shift` (`id_template_shift`),
  KEY `fk_jadwal_kerja_dibuat_oleh` (`dibuat_oleh`),
  KEY `fk_jadwal_kerja_diubah_oleh` (`diubah_oleh`),
  CONSTRAINT `fk_jadwal_kerja_dibuat_oleh` FOREIGN KEY (`dibuat_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jadwal_kerja_diubah_oleh` FOREIGN KEY (`diubah_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jadwal_penempatan` FOREIGN KEY (`id_penempatan`) REFERENCES `tbl_penempatan` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jadwal_template_shift` FOREIGN KEY (`id_template_shift`) REFERENCES `tbl_template_shift` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_jadwal_kerja_riwayat` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_jadwal_kerja` int unsigned NOT NULL,
  `aksi` enum('Dibuat','Diubah','Disalin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_sebelum` json DEFAULT NULL,
  `data_sesudah` json NOT NULL,
  `status_sesudah` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (json_unquote(json_extract(`data_sesudah`,_utf8mb4'$.status'))) STORED,
  `diubah_oleh` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jadwal_riwayat_tanggal` (`id_jadwal_kerja`,`created_at`),
  KEY `fk_jadwal_kerja_riwayat_diubah_oleh` (`diubah_oleh`),
  KEY `idx_riwayat_status_sesudah` (`status_sesudah`,`created_at`),
  CONSTRAINT `fk_jadwal_kerja_riwayat_diubah_oleh` FOREIGN KEY (`diubah_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jadwal_riwayat_jadwal` FOREIGN KEY (`id_jadwal_kerja`) REFERENCES `tbl_jadwal_kerja` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_jenis_layanan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nama_layanan` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tarif_dasar` decimal(12,2) NOT NULL,
  `tarif_tagihan_klien` decimal(12,2) NOT NULL,
  `deskripsi` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_layanan_nama` (`nama_layanan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_karyawan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nip` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kategori` enum('Lapangan','Internal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Lapangan',
  `id_jenis_layanan` int DEFAULT NULL,
  `gaji_pokok` decimal(12,2) DEFAULT NULL,
  `no_ktp` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tempat_lahir` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tgl_lahir` date NOT NULL,
  `jenis_kelamin` enum('L','P') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_pernikahan` enum('Belum Menikah','Menikah') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_ibu_kandung` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_jaminan_kesehatan` enum('BPJS','Asuransi Kesehatan Swasta') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alamat` text COLLATE utf8mb4_unicode_ci,
  `no_telp` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Tersedia','Ditempatkan','Aktif','Cuti','Nonaktif') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tersedia',
  `tgl_masuk` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_karyawan_nip` (`nip`),
  UNIQUE KEY `uq_karyawan_ktp` (`no_ktp`),
  UNIQUE KEY `uq_karyawan_email` (`email`),
  KEY `idx_karyawan_nama` (`nama`),
  KEY `idx_karyawan_status` (`status`),
  KEY `idx_karyawan_kategori` (`kategori`),
  KEY `fk_karyawan_layanan` (`id_jenis_layanan`),
  FULLTEXT KEY `ft_karyawan_nama` (`nama`),
  CONSTRAINT `fk_karyawan_layanan` FOREIGN KEY (`id_jenis_layanan`) REFERENCES `tbl_jenis_layanan` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_klien` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kode_klien` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_perusahaan` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alamat` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `pic_nama` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pic_jabatan` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pic_telp` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pic_email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `management_fee` decimal(5,2) DEFAULT NULL COMMENT 'Persentase management fee; NULL = belum ditentukan',
  `bpjs_metode` enum('Persentase','Nominal') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Metode BPJS default; NULL = belum ditentukan / tanpa BPJS',
  `bpjs_nilai` decimal(12,2) DEFAULT NULL COMMENT 'Persen (0-100) atau nominal rupiah per tenaga',
  `status` enum('Aktif','Nonaktif') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aktif',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_klien_kode` (`kode_klien`),
  FULLTEXT KEY `ft_klien_nama_perusahaan` (`nama_perusahaan`),
  CONSTRAINT `chk_klien_bpjs` CHECK (((((`bpjs_metode` is null) and (`bpjs_nilai` is null)) or ((`bpjs_metode` = _utf8mb4'Persentase') and (`bpjs_nilai` > 0) and (`bpjs_nilai` <= 100)) or ((`bpjs_metode` = _utf8mb4'Nominal') and (`bpjs_nilai` > 0))) is true)),
  CONSTRAINT `chk_klien_management_fee` CHECK (((`management_fee` is null) or (`management_fee` between 0 and 100)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_komponen_gaji` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_karyawan` int NOT NULL,
  `periode_bulan` tinyint unsigned NOT NULL,
  `periode_tahun` smallint unsigned NOT NULL,
  `tunjangan` decimal(12,2) NOT NULL DEFAULT '0.00',
  `potongan_pinjaman` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_komponen_gaji_karyawan_periode` (`id_karyawan`,`periode_bulan`,`periode_tahun`),
  KEY `idx_komponen_gaji_periode` (`periode_tahun`,`periode_bulan`),
  CONSTRAINT `fk_komponen_gaji_karyawan` FOREIGN KEY (`id_karyawan`) REFERENCES `tbl_karyawan` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_kontrak` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_penempatan` int NOT NULL,
  `gaji_pokok` decimal(12,2) NOT NULL,
  `hari_kerja_per_bulan` int NOT NULL DEFAULT '25',
  `status` enum('Aktif','Selesai') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aktif',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kontrak_penempatan` (`id_penempatan`),
  CONSTRAINT `fk_kontrak_pen` FOREIGN KEY (`id_penempatan`) REFERENCES `tbl_penempatan` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_laporan_aktivitas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_absensi` int NOT NULL,
  `id_karyawan` int NOT NULL,
  `jenis` enum('Berkala','Perjalanan') COLLATE utf8mb4_unicode_ci NOT NULL,
  `periode_ke` smallint unsigned DEFAULT NULL,
  `periode_mulai` datetime DEFAULT NULL,
  `periode_selesai` datetime DEFAULT NULL,
  `tahapan` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keterangan` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `foto_utama` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `foto_koreksi` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jumlah_upload` tinyint unsigned NOT NULL DEFAULT '1',
  `waktu_lapor` datetime NOT NULL,
  `waktu_koreksi` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_laporan_absensi_periode` (`id_absensi`,`periode_ke`),
  KEY `idx_laporan_karyawan_waktu` (`id_karyawan`,`waktu_lapor`),
  CONSTRAINT `fk_laporan_absensi` FOREIGN KEY (`id_absensi`) REFERENCES `tbl_absensi` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_laporan_karyawan` FOREIGN KEY (`id_karyawan`) REFERENCES `tbl_karyawan` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_laporan_jenis` CHECK ((((`jenis` = _utf8mb4'Berkala') and (`periode_ke` is not null) and (`periode_mulai` is not null) and (`periode_selesai` is not null)) or ((`jenis` = _utf8mb4'Perjalanan') and (`periode_ke` is null) and (`tahapan` is not null)))),
  CONSTRAINT `chk_laporan_jumlah_upload` CHECK ((`jumlah_upload` between 1 and 2)),
  CONSTRAINT `chk_laporan_koreksi` CHECK (((`jumlah_upload` = 2) = (`foto_koreksi` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_log_aktivitas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `waktu` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `id_user` int DEFAULT NULL,
  `nama_tabel` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `aksi` enum('INSERT','UPDATE','DELETE') COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_data` int DEFAULT NULL,
  `keterangan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`,`waktu`),
  KEY `idx_log_tabel_waktu` (`nama_tabel`,`waktu`),
  KEY `idx_log_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
/*!50100 PARTITION BY RANGE (unix_timestamp(`waktu`))
(PARTITION p2025 VALUES LESS THAN (1767200400) ENGINE = InnoDB,
 PARTITION p2026 VALUES LESS THAN (1798736400) ENGINE = InnoDB,
 PARTITION p2027 VALUES LESS THAN (1830272400) ENGINE = InnoDB,
 PARTITION p2028 VALUES LESS THAN (1861894800) ENGINE = InnoDB,
 PARTITION pmax VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;
CREATE TABLE `tbl_mutasi_keuangan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tgl_mutasi` date NOT NULL,
  `jenis` enum('Pemasukan','Pengeluaran') COLLATE utf8mb4_unicode_ci NOT NULL,
  `referensi` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `keterangan` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nominal` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mutasi_tgl` (`tgl_mutasi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_p2h` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_absensi` int NOT NULL,
  `id_karyawan` int NOT NULL,
  `foto_kendaraan_awal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `waktu_pemeriksaan` datetime DEFAULT NULL,
  `kondisi_akhir` enum('Baik','Perlu Perhatian','Rusak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `catatan_akhir` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_kendaraan_akhir` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `waktu_selesai` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_p2h_absensi` (`id_absensi`),
  KEY `fk_p2h_karyawan` (`id_karyawan`),
  CONSTRAINT `fk_p2h_absensi` FOREIGN KEY (`id_absensi`) REFERENCES `tbl_absensi` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_p2h_karyawan` FOREIGN KEY (`id_karyawan`) REFERENCES `tbl_karyawan` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_p2h_item` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_p2h` int unsigned NOT NULL,
  `komponen` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kondisi` enum('Baik','Perlu Perhatian','Rusak') COLLATE utf8mb4_unicode_ci NOT NULL,
  `keterangan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_p2h_item_komponen` (`id_p2h`,`komponen`),
  CONSTRAINT `fk_p2h_item_p2h` FOREIGN KEY (`id_p2h`) REFERENCES `tbl_p2h` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_payroll` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_karyawan` int NOT NULL,
  `periode_bulan` int NOT NULL,
  `periode_tahun` int NOT NULL,
  `gaji_pokok` decimal(12,2) NOT NULL,
  `total_lembur` decimal(12,2) NOT NULL DEFAULT '0.00',
  `potongan_absen` decimal(12,2) NOT NULL DEFAULT '0.00',
  `hari_wajib` smallint unsigned DEFAULT NULL,
  `hari_masuk` smallint unsigned DEFAULT NULL,
  `hari_absen` smallint unsigned DEFAULT NULL,
  `id_klien_bpjs` int DEFAULT NULL,
  `bpjs_metode` enum('Persentase','Nominal') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bpjs_nilai` decimal(12,2) DEFAULT NULL,
  `potongan_bpjs` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_gaji` decimal(12,2) NOT NULL,
  `status_bayar` enum('Belum Dibayar','Sudah Lunas') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Belum Dibayar',
  `tgl_bayar` date DEFAULT NULL,
  `komponen_tunjangan` decimal(12,2) NOT NULL DEFAULT '0.00',
  `upah_backup` decimal(12,2) NOT NULL DEFAULT '0.00',
  `potongan_pinjaman` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pph21` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_periode` (`id_karyawan`,`periode_bulan`,`periode_tahun`),
  KEY `idx_payroll_periode` (`periode_bulan`,`periode_tahun`),
  KEY `idx_payroll_klien_bpjs_periode` (`id_klien_bpjs`,`periode_tahun`,`periode_bulan`),
  CONSTRAINT `fk_payroll_karyawan` FOREIGN KEY (`id_karyawan`) REFERENCES `tbl_karyawan` (`id`),
  CONSTRAINT `fk_payroll_klien_bpjs` FOREIGN KEY (`id_klien_bpjs`) REFERENCES `tbl_klien` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_penempatan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_karyawan` int NOT NULL,
  `id_klien` int NOT NULL,
  `lokasi_kerja` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jam_datang` time DEFAULT NULL,
  `jam_pulang` time DEFAULT NULL,
  `tgl_mulai` date NOT NULL,
  `tgl_selesai` date DEFAULT NULL,
  `status` enum('Aktif','Selesai','Rotasi') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aktif',
  `dibuat_oleh` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_penempatan_status` (`status`),
  KEY `fk_pen_user` (`dibuat_oleh`),
  KEY `fk_pen_karyawan` (`id_karyawan`),
  KEY `fk_pen_klien` (`id_klien`),
  KEY `idx_penempatan_karyawan_status` (`id_karyawan`,`status`),
  KEY `idx_penempatan_klien_status_mulai` (`id_klien`,`status`,`tgl_mulai`),
  CONSTRAINT `fk_pen_karyawan` FOREIGN KEY (`id_karyawan`) REFERENCES `tbl_karyawan` (`id`),
  CONSTRAINT `fk_pen_klien` FOREIGN KEY (`id_klien`) REFERENCES `tbl_klien` (`id`),
  CONSTRAINT `fk_pen_user` FOREIGN KEY (`dibuat_oleh`) REFERENCES `tbl_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_pertukaran_shift` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_karyawan_pengaju` int NOT NULL,
  `id_karyawan_rekan` int NOT NULL,
  `id_jadwal_pengaju` int unsigned NOT NULL,
  `id_jadwal_rekan` int unsigned NOT NULL,
  `tanggal_kerja` date NOT NULL,
  `tanggal_kerja_rekan` date DEFAULT NULL,
  `snapshot_pengaju` json NOT NULL,
  `snapshot_rekan` json NOT NULL,
  `alasan` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Menunggu','Disetujui','Ditolak') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Menunggu',
  `status_rekan` enum('Menunggu','Diterima','Ditolak') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Menunggu',
  `catatan_rekan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ditanggapi_rekan_pada` timestamp NULL DEFAULT NULL,
  `catatan_keputusan` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `diproses_oleh` int DEFAULT NULL,
  `diproses_pada` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pertukaran_status_tanggal` (`status`,`tanggal_kerja`),
  KEY `idx_pertukaran_pengaju` (`id_karyawan_pengaju`,`created_at`),
  KEY `fk_pertukaran_rekan` (`id_karyawan_rekan`),
  KEY `fk_pertukaran_jadwal_pengaju` (`id_jadwal_pengaju`),
  KEY `fk_pertukaran_jadwal_rekan` (`id_jadwal_rekan`),
  KEY `fk_pertukaran_shift_diproses_oleh` (`diproses_oleh`),
  CONSTRAINT `fk_pertukaran_jadwal_pengaju` FOREIGN KEY (`id_jadwal_pengaju`) REFERENCES `tbl_jadwal_kerja` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pertukaran_jadwal_rekan` FOREIGN KEY (`id_jadwal_rekan`) REFERENCES `tbl_jadwal_kerja` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pertukaran_pengaju` FOREIGN KEY (`id_karyawan_pengaju`) REFERENCES `tbl_karyawan` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pertukaran_rekan` FOREIGN KEY (`id_karyawan_rekan`) REFERENCES `tbl_karyawan` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pertukaran_shift_diproses_oleh` FOREIGN KEY (`diproses_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_pertukaran_beda_karyawan` CHECK ((`id_karyawan_pengaju` <> `id_karyawan_rekan`)),
  CONSTRAINT `chk_pertukaran_diproses` CHECK ((((`status` = _utf8mb4'Menunggu') and (`diproses_oleh` is null) and (`diproses_pada` is null)) or ((`status` <> _utf8mb4'Menunggu') and (`diproses_oleh` is not null) and (`diproses_pada` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nama_role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deskripsi` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_nama` (`nama_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_sertifikat` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_karyawan` int NOT NULL,
  `nama_dokumen` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nomor_dokumen` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tgl_terbit` date NOT NULL,
  `tgl_kadaluarsa` date NOT NULL,
  `file_upload` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Aktif','Kadaluarsa') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aktif',
  PRIMARY KEY (`id`),
  KEY `fk_sert_karyawan` (`id_karyawan`),
  KEY `idx_sertifikat_kadaluarsa_status` (`tgl_kadaluarsa`,`status`),
  CONSTRAINT `fk_sert_karyawan` FOREIGN KEY (`id_karyawan`) REFERENCES `tbl_karyawan` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_template_jadwal` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nama_template` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jenis` enum('Pribadi','Bersama') COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_karyawan` int DEFAULT NULL,
  `id_klien` int DEFAULT NULL,
  `id_jenis_layanan` int DEFAULT NULL,
  `keterangan` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Aktif','Nonaktif') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aktif',
  `dibuat_oleh` int DEFAULT NULL,
  `diubah_oleh` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_jadwal_nama` (`nama_template`),
  KEY `idx_template_jadwal_jenis_status` (`jenis`,`status`),
  KEY `idx_template_jadwal_karyawan` (`id_karyawan`,`status`),
  KEY `idx_template_jadwal_klien` (`id_klien`,`status`),
  KEY `fk_template_jadwal_layanan` (`id_jenis_layanan`),
  KEY `fk_template_jadwal_dibuat_oleh` (`dibuat_oleh`),
  KEY `fk_template_jadwal_diubah_oleh` (`diubah_oleh`),
  CONSTRAINT `fk_template_jadwal_dibuat_oleh` FOREIGN KEY (`dibuat_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_template_jadwal_diubah_oleh` FOREIGN KEY (`diubah_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_template_jadwal_karyawan` FOREIGN KEY (`id_karyawan`) REFERENCES `tbl_karyawan` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_template_jadwal_klien` FOREIGN KEY (`id_klien`) REFERENCES `tbl_klien` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_template_jadwal_layanan` FOREIGN KEY (`id_jenis_layanan`) REFERENCES `tbl_jenis_layanan` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_template_jadwal_pemilik` CHECK ((((`jenis` = _utf8mb4'Pribadi') and (`id_karyawan` is not null) and (`id_klien` is null) and (`id_jenis_layanan` is null)) or ((`jenis` = _utf8mb4'Bersama') and (`id_karyawan` is null) and (`id_klien` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_template_jadwal_detail` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_template_jadwal` int unsigned NOT NULL,
  `hari_ke` tinyint unsigned NOT NULL,
  `status` enum('Kerja','Libur') COLLATE utf8mb4_unicode_ci NOT NULL,
  `jam_masuk` time DEFAULT NULL,
  `jam_pulang` time DEFAULT NULL,
  `durasi_menit` smallint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_jadwal_detail_hari` (`id_template_jadwal`,`hari_ke`),
  CONSTRAINT `fk_template_jadwal_detail_template` FOREIGN KEY (`id_template_jadwal`) REFERENCES `tbl_template_jadwal` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_template_jadwal_detail_hari` CHECK ((`hari_ke` between 1 and 31)),
  CONSTRAINT `chk_template_jadwal_detail_jam` CHECK ((((`status` = _utf8mb4'Libur') and (`jam_masuk` is null) and (`jam_pulang` is null) and (`durasi_menit` is null)) or ((`status` = _utf8mb4'Kerja') and (`jam_masuk` is not null) and (`jam_pulang` is not null) and (`durasi_menit` between 1 and 1439))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_template_jadwal_penerapan` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_template_jadwal` int unsigned NOT NULL,
  `id_karyawan` int NOT NULL,
  `id_penempatan` int NOT NULL,
  `bulan_tujuan` date NOT NULL,
  `mode_konflik` enum('Lewati','Timpa') COLLATE utf8mb4_unicode_ci NOT NULL,
  `jumlah_dibuat` smallint unsigned NOT NULL DEFAULT '0',
  `jumlah_ditimpa` smallint unsigned NOT NULL DEFAULT '0',
  `jumlah_dilewati` smallint unsigned NOT NULL DEFAULT '0',
  `diterapkan_oleh` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_penerapan_template` (`id_template_jadwal`,`created_at`),
  KEY `idx_penerapan_karyawan_bulan` (`id_karyawan`,`bulan_tujuan`),
  KEY `fk_penerapan_penempatan` (`id_penempatan`),
  KEY `fk_template_jadwal_penerapan_diterapkan_oleh` (`diterapkan_oleh`),
  CONSTRAINT `fk_penerapan_karyawan` FOREIGN KEY (`id_karyawan`) REFERENCES `tbl_karyawan` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_penerapan_penempatan` FOREIGN KEY (`id_penempatan`) REFERENCES `tbl_penempatan` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_penerapan_template` FOREIGN KEY (`id_template_jadwal`) REFERENCES `tbl_template_jadwal` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_template_jadwal_penerapan_diterapkan_oleh` FOREIGN KEY (`diterapkan_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_penerapan_bulan` CHECK ((dayofmonth(`bulan_tujuan`) = 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_template_shift` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_klien` int NOT NULL,
  `nama_shift` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jam_masuk` time NOT NULL,
  `jam_pulang` time NOT NULL,
  `durasi_menit` smallint unsigned NOT NULL,
  `status` enum('Aktif','Nonaktif') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aktif',
  `dibuat_oleh` int DEFAULT NULL,
  `diubah_oleh` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template_shift_klien_nama` (`id_klien`,`nama_shift`),
  KEY `idx_template_shift_klien_status` (`id_klien`,`status`),
  KEY `fk_template_shift_dibuat_oleh` (`dibuat_oleh`),
  KEY `fk_template_shift_diubah_oleh` (`diubah_oleh`),
  CONSTRAINT `fk_template_shift_dibuat_oleh` FOREIGN KEY (`dibuat_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_template_shift_diubah_oleh` FOREIGN KEY (`diubah_oleh`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_template_shift_klien` FOREIGN KEY (`id_klien`) REFERENCES `tbl_klien` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tbl_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_karyawan` int DEFAULT NULL,
  `nama` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_role` int NOT NULL,
  `status` enum('Aktif','Nonaktif') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aktif',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_karyawan` (`id_karyawan`),
  KEY `fk_users_role` (`id_role`),
  CONSTRAINT `fk_users_karyawan` FOREIGN KEY (`id_karyawan`) REFERENCES `tbl_karyawan` (`id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`id_role`) REFERENCES `tbl_roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;

SET FOREIGN_KEY_CHECKS = 1;

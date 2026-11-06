CREATE DATABASE IF NOT EXISTS db_sji
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE db_sji;

-- ============================================================
-- [JATAH ORANG 1] TABEL MASTER
-- ============================================================
CREATE TABLE tbl_roles (
  id INT NOT NULL AUTO_INCREMENT,
  nama_role VARCHAR(50) NOT NULL,
  deskripsi VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_nama (nama_role)
) ENGINE=InnoDB;

CREATE TABLE tbl_jenis_layanan (
  id INT NOT NULL AUTO_INCREMENT,
  nama_layanan VARCHAR(50) NOT NULL,
  tarif_dasar DECIMAL(12,2) NOT NULL,
  tarif_tagihan_klien DECIMAL(12,2) NOT NULL,
  deskripsi VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_layanan_nama (nama_layanan)
) ENGINE=InnoDB;

CREATE TABLE tbl_karyawan (
  id INT NOT NULL AUTO_INCREMENT,
  nip VARCHAR(20) NOT NULL,
  nama VARCHAR(100) NOT NULL,
  kategori ENUM('Lapangan','Internal') NOT NULL DEFAULT 'Lapangan',
  id_jenis_layanan INT NULL,
  gaji_pokok DECIMAL(12,2) NULL,
  tunjangan DECIMAL(12,2) NULL DEFAULT 0,
  no_ktp VARCHAR(16) NOT NULL,
  tempat_lahir VARCHAR(50) NULL,
  tgl_lahir DATE NOT NULL,
  jenis_kelamin ENUM('L','P') NOT NULL,
  alamat TEXT NULL,
  no_telp VARCHAR(15) NOT NULL,
  email VARCHAR(100) NULL,
  foto VARCHAR(255) NULL,
  status ENUM('Tersedia','Ditempatkan','Aktif','Cuti','Nonaktif') NOT NULL DEFAULT 'Tersedia',
  rating DECIMAL(2,1) NOT NULL DEFAULT 0.0,
  tgl_masuk DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_karyawan_nip (nip),
  UNIQUE KEY uq_karyawan_ktp (no_ktp),
  UNIQUE KEY uq_karyawan_email (email),
  KEY idx_karyawan_nama (nama),
  KEY idx_karyawan_status (status),
  KEY idx_karyawan_kategori (kategori),
  CONSTRAINT fk_karyawan_layanan FOREIGN KEY (id_jenis_layanan)
    REFERENCES tbl_jenis_layanan (id)
) ENGINE=InnoDB;

CREATE TABLE tbl_users (
  id INT NOT NULL AUTO_INCREMENT,
  id_karyawan INT NULL,
  username VARCHAR(50) NOT NULL,
  password VARCHAR(255) NOT NULL,
  id_role INT NOT NULL,
  status ENUM('Aktif','Nonaktif') NOT NULL DEFAULT 'Aktif',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_karyawan (id_karyawan),
  CONSTRAINT fk_users_karyawan FOREIGN KEY (id_karyawan)
    REFERENCES tbl_karyawan (id),
  CONSTRAINT fk_users_role FOREIGN KEY (id_role)
    REFERENCES tbl_roles (id)
) ENGINE=InnoDB;

CREATE TABLE tbl_sertifikat (
  id INT NOT NULL AUTO_INCREMENT,
  id_karyawan INT NOT NULL,
  nama_dokumen VARCHAR(100) NOT NULL,
  nomor_dokumen VARCHAR(50) NULL,
  tgl_terbit DATE NOT NULL,
  tgl_kadaluarsa DATE NOT NULL,
  file_upload VARCHAR(255) NULL,
  status ENUM('Aktif','Kadaluarsa') NOT NULL DEFAULT 'Aktif',
  PRIMARY KEY (id),
  CONSTRAINT fk_sert_karyawan FOREIGN KEY (id_karyawan)
    REFERENCES tbl_karyawan (id)
) ENGINE=InnoDB;

-- ============================================================
-- [JATAH ORANG 2] TABEL KLIEN & PENEMPATAN (khusus Lapangan)
-- ============================================================
CREATE TABLE tbl_klien (
  id INT NOT NULL AUTO_INCREMENT,
  kode_klien VARCHAR(10) NOT NULL,
  nama_perusahaan VARCHAR(150) NOT NULL,
  alamat TEXT NOT NULL,
  pic_nama VARCHAR(100) NOT NULL,
  pic_jabatan VARCHAR(50) NULL,
  pic_telp VARCHAR(15) NOT NULL,
  pic_email VARCHAR(100) NULL,
  status ENUM('Aktif','Nonaktif') NOT NULL DEFAULT 'Aktif',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_klien_kode (kode_klien)
) ENGINE=InnoDB;

CREATE TABLE tbl_penempatan (
  id INT NOT NULL AUTO_INCREMENT,
  id_karyawan INT NOT NULL,
  id_klien INT NOT NULL,
  lokasi_kerja VARCHAR(255) NOT NULL,
  tgl_mulai DATE NOT NULL,
  tgl_selesai DATE NULL,
  status ENUM('Aktif','Selesai','Rotasi') NOT NULL DEFAULT 'Aktif',
  dibuat_oleh INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_penempatan_status (status),
  CONSTRAINT fk_pen_karyawan FOREIGN KEY (id_karyawan)
    REFERENCES tbl_karyawan (id),
  CONSTRAINT fk_pen_klien FOREIGN KEY (id_klien)
    REFERENCES tbl_klien (id),
  CONSTRAINT fk_pen_user FOREIGN KEY (dibuat_oleh)
    REFERENCES tbl_users (id)
) ENGINE=InnoDB;

CREATE TABLE tbl_kontrak (
  id INT NOT NULL AUTO_INCREMENT,
  id_penempatan INT NOT NULL,
  gaji_pokok DECIMAL(12,2) NOT NULL,
  tunjangan DECIMAL(12,2) NOT NULL DEFAULT 0,
  hari_kerja_per_bulan INT NOT NULL DEFAULT 25,
  status ENUM('Aktif','Selesai') NOT NULL DEFAULT 'Aktif',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_kontrak_penempatan (id_penempatan),
  CONSTRAINT fk_kontrak_pen FOREIGN KEY (id_penempatan)
    REFERENCES tbl_penempatan (id)
) ENGINE=InnoDB;

-- ============================================================
-- [JATAH ORANG 4] TABEL ABSENSI (semua karyawan absen di sini)
-- ============================================================
CREATE TABLE tbl_absensi (
  id INT NOT NULL AUTO_INCREMENT,
  id_karyawan INT NOT NULL,
  tgl DATE NOT NULL,
  jam_masuk TIME NULL,
  jam_keluar TIME NULL,
  status_kehadiran ENUM('Hadir','Terlambat','Izin','Sakit','Alpha') NOT NULL DEFAULT 'Hadir',
  jam_lembur INT NOT NULL DEFAULT 0,
  keterangan VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_absensi_karyawan_tgl (id_karyawan, tgl),
  KEY idx_absensi_tgl (tgl),
  CONSTRAINT fk_absensi_karyawan FOREIGN KEY (id_karyawan)
    REFERENCES tbl_karyawan (id)
) ENGINE=InnoDB;

-- ============================================================
-- [JATAH ORANG 3] TABEL KEUANGAN & EVALUASI
-- ============================================================
CREATE TABLE tbl_payroll (
  id INT NOT NULL AUTO_INCREMENT,
  id_karyawan INT NOT NULL,
  periode_bulan INT NOT NULL,
  periode_tahun INT NOT NULL,
  gaji_pokok DECIMAL(12,2) NOT NULL,
  tunjangan DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_lembur DECIMAL(12,2) NOT NULL DEFAULT 0,
  potongan_absen DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_gaji DECIMAL(12,2) NOT NULL,
  status_bayar ENUM('Belum Dibayar','Sudah Dibayar') NOT NULL DEFAULT 'Belum Dibayar',
  tgl_bayar DATE NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payroll_periode (id_karyawan, periode_bulan, periode_tahun),
  KEY idx_payroll_periode (periode_bulan, periode_tahun),
  CONSTRAINT fk_payroll_karyawan FOREIGN KEY (id_karyawan)
    REFERENCES tbl_karyawan (id)
) ENGINE=InnoDB;

CREATE TABLE tbl_invoice (
  id INT NOT NULL AUTO_INCREMENT,
  nomor_invoice VARCHAR(20) NOT NULL,
  id_klien INT NOT NULL,
  periode_bulan INT NOT NULL,
  periode_tahun INT NOT NULL,
  jumlah_tenaga INT NOT NULL,
  total_tagihan DECIMAL(12,2) NOT NULL,
  status ENUM('Belum Lunas','Lunas') NOT NULL DEFAULT 'Belum Lunas',
  tgl_pembayaran DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_nomor (nomor_invoice),
  UNIQUE KEY uq_invoice_periode (id_klien, periode_bulan, periode_tahun),
  KEY idx_invoice_status (status),
  CONSTRAINT fk_invoice_klien FOREIGN KEY (id_klien)
    REFERENCES tbl_klien (id)
) ENGINE=InnoDB;

CREATE TABLE tbl_mutasi_keuangan (
  id INT NOT NULL AUTO_INCREMENT,
  tgl_mutasi DATE NOT NULL,
  jenis ENUM('Pemasukan','Pengeluaran') NOT NULL,
  referensi VARCHAR(50) NOT NULL,
  keterangan VARCHAR(255) NOT NULL,
  nominal DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_mutasi_tgl (tgl_mutasi)
) ENGINE=InnoDB;

CREATE TABLE tbl_evaluasi_kinerja (
  id INT NOT NULL AUTO_INCREMENT,
  id_karyawan INT NOT NULL,
  id_klien INT NOT NULL,
  periode_bulan INT NOT NULL,
  periode_tahun INT NOT NULL,
  rating INT NOT NULL,
  catatan TEXT NULL,
  dinilai_oleh INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evaluasi_periode (id_karyawan, periode_bulan, periode_tahun),
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT fk_eval_karyawan FOREIGN KEY (id_karyawan)
    REFERENCES tbl_karyawan (id),
  CONSTRAINT fk_eval_klien FOREIGN KEY (id_klien)
    REFERENCES tbl_klien (id),
  CONSTRAINT fk_eval_user FOREIGN KEY (dinilai_oleh)
    REFERENCES tbl_users (id)
) ENGINE=InnoDB;

-- ============================================================
-- [DATA AWAL WAJIB]
-- Akun login TIDAK di-seed di sini; dibuat saat fase dummy data
-- dengan password hash dari PHP.
-- ============================================================
INSERT INTO tbl_roles (nama_role, deskripsi) VALUES
('HRD', 'Rekrutmen, data karyawan, dokumen'),
('Operasional', 'Penempatan, rotasi, monitoring lapangan'),
('Keuangan', 'Payroll semua karyawan, invoice, pencatatan status pembayaran'),
('Karyawan Lapangan', 'Absensi dan informasi pribadi satpam/driver/cleaning service'),
('CEO', 'Monitoring eksekutif dan profitabilitas');

INSERT INTO tbl_jenis_layanan
(nama_layanan, tarif_dasar, tarif_tagihan_klien, deskripsi) VALUES
('Satpam', 3500000, 4500000, 'Layanan keamanan lingkungan'),
('Driver', 3200000, 4200000, 'Layanan pengemudi operasional'),
('Cleaning Service', 2800000, 3700000, 'Layanan kebersihan gedung');
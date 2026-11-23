# Backup & Recovery Database db_sji

Dokumen ini menjelaskan strategi backup database aplikasi PT Satu Jaya Indonesia, cara membuat backup, cara memulihkannya (restore), cara menguji restore, dan alasan memakai `mysqldump`.

## 1. Strategi

| Aspek | Ketentuan |
|---|---|
| Jenis backup | **Full logical backup**: seluruh struktur dan data dalam satu file `.sql` |
| Alat | `mysqldump --single-transaction --routines --triggers --events` |
| Jadwal | Harian pukul **01:00** lewat Laravel Scheduler (`routes/console.php`) |
| Lokasi | `storage/app/backup/{database}_YYYYmmdd_HHMMSS.sql` (tidak ikut Git) |
| Masa simpan | **7 hari**. Backup yang lebih lama dihapus otomatis setiap kali backup baru dibuat |
| Uji pemulihan | `sji:restore-db` ke database uji terpisah, lalu jumlah baris tabel utama dicocokkan |

**Mengapa `--single-transaction`.** Semua tabel db_sji memakai InnoDB. Opsi ini membuat snapshot yang konsisten pada satu titik waktu tanpa mengunci tabel, sehingga aplikasi tetap bisa dipakai selama backup berjalan.

**Mengapa `--routines --triggers --events`.** Logika bisnis db_sji banyak berada di dalam database:
- function dan procedure, misalnya `sp_generate_payroll_bulanan`, `sp_generate_invoice_klien`, dan `fn_hari_wajib_roster`;
- trigger, misalnya `trg_auto_hitung_lembur`, `trg_cek_jadwal_kerja_*`, dan trigger log aktivitas;
- event `ev_update_status_sertifikat`.

Tanpa opsi-opsi ini, hasil restore hanya berisi tabel, dan aplikasi tidak berjalan benar.

**RPO/RTO.** Dengan backup harian, data yang bisa hilang paling banyak satu hari (RPO ≤ 24 jam). Waktu pemulihan (RTO) adalah lama restore satu file `.sql`. Untuk ukuran data saat ini, restore selesai dalam hitungan detik.

## 2. Konfigurasi

Program `mysqldump` dan `mysql` dicari di `PATH`. Kalau tidak ada di `PATH` (umum di Laragon/XAMPP), isi path-nya di `.env`:

```dotenv
MYSQLDUMP_PATH=C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysqldump.exe
MYSQL_PATH=C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe
```

Host, port, user, password, dan nama database diambil dari koneksi database aplikasi (`DB_*` di `.env`). Password dikirim lewat variabel lingkungan `MYSQL_PWD`, bukan lewat argumen, supaya tidak terlihat di daftar proses.

## 3. Membuat backup

```bash
php artisan sji:backup-db
```

Contoh hasil:

```text
Backup tersimpan: C:\tubes-MSBD-Kel5\Tubes-MSBD\storage\app/backup\db_sji_20261123_061200.sql
```

### Backup otomatis harian

Jadwal backup terdaftar di `routes/console.php`:

```php
Schedule::command('sji:backup-db')->dailyAt('01:00')->withoutOverlapping();
```

Jadwal ini hanya berjalan kalau scheduler Laravel aktif:
- **Saat pengembangan:** biarkan `php artisan schedule:work` berjalan.
- **Server Linux:** pasang cron `* * * * * cd /path/aplikasi && php artisan schedule:run >> /dev/null 2>&1`.
- **Windows:** buat tugas di Task Scheduler yang menjalankan `php artisan schedule:run` setiap menit.

Untuk memastikan jadwalnya terdaftar, jalankan `php artisan schedule:list`.

## 4. Restore

### 4.1 Uji restore (aman, tidak menyentuh database aplikasi)

```bash
php artisan sji:restore-db db_sji_20261123_061200.sql
# atau database uji lain:
php artisan sji:restore-db db_sji_20261123_061200.sql --database=db_sji_uji
```

Perintah ini menjalankan langkah berikut:
1. Menolak kalau database tujuan sama dengan database aplikasi, atau kalau namanya tidak valid. Nama hanya boleh berisi huruf, angka, dan `_`.
2. Menghapus lalu membuat ulang database uji (default `db_sji_restore`).
3. Memulihkan file backup ke database uji dengan program `mysql`.
4. Menonaktifkan event di database uji supaya tidak ikut berjalan setiap hari.
5. Mencocokkan jumlah baris tabel utama dengan database aplikasi: `tbl_users`, `tbl_karyawan`, `tbl_klien`, `tbl_penempatan`, `tbl_kontrak`, `tbl_jadwal_kerja`, `tbl_absensi`, `tbl_payroll`, `tbl_invoice`, `tbl_sertifikat`, dan `tbl_log_aktivitas`.

### 4.2 Pemulihan sungguhan (database aplikasi rusak/hilang)

Pemulihan ke database aplikasi sengaja **tidak** disediakan sebagai perintah otomatis, supaya data produksi tidak tertimpa karena salah ketik. Langkah manualnya:

```bash
# 1. Backup kondisi sekarang dulu (meskipun rusak), untuk berjaga-jaga.
php artisan sji:backup-db
# 2. Uji file backup yang akan dipakai.
php artisan sji:restore-db db_sji_YYYYmmdd_HHMMSS.sql
# 3. Bila cocok, pulihkan ke database aplikasi.
mysql -u root -e "DROP DATABASE db_sji; CREATE DATABASE db_sji CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root db_sji < storage/app/backup/db_sji_YYYYmmdd_HHMMSS.sql
# 4. Pasang ulang hak akses role (GRANT tidak ikut dalam dump database).
php artisan migrate --force
```

## 5. Hasil uji restore

Uji pada 23 November 2026 dengan backup `db_sji_20261123_061200.sql`:

```text
+-------------------+-------------------+--------------------------------+--------+
| Tabel             | Database aplikasi | Hasil restore (db_sji_restore) | Status |
+-------------------+-------------------+--------------------------------+--------+
| tbl_users         | 9                 | 9                              | Cocok  |
| tbl_karyawan      | 6                 | 6                              | Cocok  |
| tbl_klien         | 2                 | 2                              | Cocok  |
| tbl_penempatan    | 3                 | 3                              | Cocok  |
| tbl_kontrak       | 3                 | 3                              | Cocok  |
| tbl_jadwal_kerja  | 8                 | 8                              | Cocok  |
| tbl_absensi       | 5                 | 5                              | Cocok  |
| tbl_payroll       | 0                 | 0                              | Cocok  |
| tbl_invoice       | 0                 | 0                              | Cocok  |
| tbl_sertifikat    | 0                 | 0                              | Cocok  |
| tbl_log_aktivitas | 19                | 19                             | Cocok  |
+-------------------+-------------------+--------------------------------+--------+
Semua tabel utama cocok. Backup dapat dipulihkan.
```

Objek database juga dibandingkan, dan jumlahnya identik di kedua database:

| Objek | db_sji | db_sji_restore |
|---|---|---|
| Routine (function + procedure) | 31 | 31 |
| Trigger | 19 | 19 |
| Event | 1 | 1 (dinonaktifkan) |
| View | 8 | 8 |
| CHECK constraint | 11 | 11 |
| Foreign key | 51 | 51 |

Pengamanan restore juga sudah diuji. Perintah `php artisan sji:restore-db ... --database=db_sji` ditolak dengan pesan *"Restore ke database aplikasi (db_sji) ditolak. Pakai database uji terpisah."*

Tes otomatis ada di `tests/Feature/BackupRestoreDatabaseTest.php` dan mencakup:
- opsi mysqldump yang dipakai,
- password yang tidak muncul di argumen,
- penghapusan backup yang lebih tua dari 7 hari,
- kegagalan backup tanpa meninggalkan file,
- penolakan restore ke database aplikasi atau ke nama database yang tidak valid.

## 6. Mengapa mysqldump, bukan Export phpMyAdmin

1. **CHECK constraint hilang.** Dalam pengujian tim, hasil export phpMyAdmin tidak menyertakan CHECK constraint, misalnya `chk_pertukaran_diproses`, `chk_klien_management_fee`, dan `chk_template_jadwal_detail_jam`. Database hasil impor tetap jalan, tetapi aturan validasi di level database hilang tanpa peringatan. `mysqldump` dari MySQL 8 menuliskan CHECK sebagai bagian dari `CREATE TABLE`. Hasil di atas menunjukkan 11 dari 11 CHECK ikut terpulihkan.
2. **Routine, trigger, dan event tidak boleh tertinggal.** Opsi ini harus dicentang manual di phpMyAdmin. Dengan `sji:backup-db` opsinya selalu dipakai.
3. **Konsistensi.** `--single-transaction` menghasilkan snapshot satu titik waktu tanpa mengunci tabel.
4. **Bisa dijadwalkan dan diuji otomatis.** Export phpMyAdmin harus dilakukan manual lewat browser, dan untuk data besar bisa terputus karena batas waktu PHP atau ukuran unduhan.

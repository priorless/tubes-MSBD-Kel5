# Folder `sql/` — Database db_sji

Database aplikasi PT Satu Jaya Indonesia memakai MySQL 8. Folder ini berisi tiga jenis file:

| Folder / file | Isi | Dipakai untuk |
|---|---|---|
| `schema_sji.sql` | Skema awal proyek (sebelum dipecah per komponen) | Sejarah; tidak perlu dijalankan lagi |
| `Komponen/` | **Riwayat perubahan** database, berurutan `01` … `18`. Tiap file yang punya pasangan `_rollback.sql` bisa dibatalkan | Dijalankan otomatis oleh **migration Laravel** (`php artisan migrate`) lewat `App\Support\SqlKomponen` |
| `final/` | **Kondisi akhir** database, satu file per jenis objek, tanpa `DEFINER` | Instalasi baru tanpa menjalankan riwayat, bahan laporan, dan rujukan cepat |

## `Komponen/` — riwayat perubahan

Setiap file mencatat perubahan pada satu tahap pengerjaan. Isinya menimpa atau melengkapi file sebelumnya, misalnya `sp_generate_payroll_bulanan` ditulis ulang di beberapa file. Karena itu, untuk melihat definisi yang **berlaku sekarang**, buka `final/`, bukan `Komponen/`.

- File diberi blok `-- [TAHAP n]` yang dibaca migration dengan `SqlKomponen::jalankanBlok()`.
- `10_hak_akses_database.sql` adalah satu-satunya sumber hak akses (role dan GRANT). File ini dijalankan ulang di akhir setiap migration yang menambah atau menghapus objek.
- `18_finalisasi_database.sql` berisi perubahan Finalisasi Tahap 1–8, dengan pasangan `18_finalisasi_database_rollback.sql`.
- `19_log_append_only.sql` membuat `tbl_log_aktivitas` append-only: trigger `BEFORE UPDATE`/`BEFORE DELETE` menolak perubahan log dengan `SIGNAL 45000`, sedangkan INSERT tetap normal. Pasangannya `19_log_append_only_rollback.sql`. Tidak ada event purge log; bila log lama perlu dibuang, pakai `ALTER TABLE tbl_log_aktivitas TRUNCATE PARTITION p<tahun>` (DDL partisi tidak memicu trigger). Trigger ini juga sudah ada di `sql/final/05_trigger.sql`.
- `20_perbaikan_jadwal_event_sertifikat.sql` mengembalikan jam mulai `ev_update_status_sertifikat` ke 00:05 (ekspor final sempat tercatat 06:05). Pasangannya `20_perbaikan_jadwal_event_sertifikat_rollback.sql`.
- **Ada dua file bernomor 14** (`14_bpjs_klien.sql` dan `14_komponen_gaji.sql`). Keduanya sudah dipanggil migration dengan nama itu. Ini riwayat dan **tidak perlu di-rename**, karena mengganti nama akan memutus migration yang merujuknya.
- File lama tidak boleh diubah. Perubahan baru selalu ditambahkan sebagai file atau blok baru.

## `final/` — kondisi akhir

| File | Isi |
|---|---|
| `01_tabel.sql` | Struktur 36 tabel (termasuk tabel internal Laravel), lengkap dengan PRIMARY/FOREIGN KEY, indeks, FULLTEXT, CHECK constraint, kolom generated, dan partisi `tbl_log_aktivitas` |
| `02_view.sql` | 10 view, diurutkan sesuai ketergantungan antar-view |
| `03_function.sql` | Function |
| `04_procedure.sql` | Stored procedure |
| `05_trigger.sql` | Trigger, sesuai urutan eksekusinya |
| `06_event.sql` | Event `ev_update_status_sertifikat` |
| `07_hak_akses.sql` | Salinan `Komponen/10_hak_akses_database.sql` (role per bagian + `sji_role_app`) |
| `08_data_awal.sql` | Data wajib: `tbl_roles`, `tbl_jenis_layanan`, dan isi tabel `migrations` |

Semua file diekspor dari database setelah Finalisasi Tahap 1–10 dengan `mysqldump --no-data --skip-comments` dan `SHOW CREATE ...`. Klausa `DEFINER=` dan nilai `AUTO_INCREMENT=` dihapus supaya bisa diimpor oleh user MySQL mana pun di komputer lain. Teks `SQL SECURITY DEFINER` pada view dan routine tetap ada. Itu mode keamanan, bukan nama user.

> **Perhatian untuk `07_hak_akses.sql`:** role MySQL berlaku untuk seluruh server, dan file ini diawali `REVOKE ALL PRIVILEGES FROM` setiap role. Jalankan file ini **hanya pada database yang dipakai aplikasi**. Kalau dijalankan pada database lain di server yang sama, misalnya database uji, hak role atas `db_sji` ikut tercabut. Untuk memulihkannya, jalankan ulang file ini pada `db_sji`, atau `php artisan tinker --execute 'App\Support\SqlKomponen::pasangHakAkses();'`.

`08_data_awal.sql` sengaja menyertakan isi tabel `migrations`. Dengan begitu `php artisan migrate` setelah impor tidak menjalankan ulang migration yang perubahannya sudah ada di `final/`.

## Instalasi baru

### Cara A — dari `final/` (cepat)

**Perhatikan urutannya:** `03_function` harus dijalankan **sebelum** `02_view`, karena view memanggil function seperti `fn_hitung_jam_kerja` dan MySQL memeriksa function itu saat view dibuat.

```bash
mysql -u root -e "CREATE DATABASE db_sji CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root db_sji < sql/final/01_tabel.sql
mysql -u root db_sji < sql/final/03_function.sql
mysql -u root db_sji < sql/final/02_view.sql
mysql -u root db_sji < sql/final/04_procedure.sql
mysql -u root db_sji < sql/final/05_trigger.sql
mysql -u root db_sji < sql/final/06_event.sql
mysql -u root db_sji < sql/final/07_hak_akses.sql
mysql -u root db_sji < sql/final/08_data_awal.sql
```

Kalau memakai phpMyAdmin, impor file satu per satu dengan urutan yang sama.

Langkah berikutnya:

1. Salin `.env`, isi `DB_DATABASE=db_sji`, lalu jalankan `php artisan key:generate`.
2. Jalankan `php artisan migrate`. Hasilnya harus *Nothing to migrate*, atau hanya migration yang lebih baru dari ekspor ini.
3. Buat akun login pertama lewat seeder atau perintah aplikasi.
4. Aktifkan event scheduler supaya status sertifikat diperbarui setiap hari: `SET GLOBAL event_scheduler = ON;`, atau tambahkan `event_scheduler=ON` di `my.ini`.
5. Opsional, user MySQL per bagian: `php artisan sji:buat-user-db`. User aplikasi dengan hak terbatas: `php artisan sji:buat-user-db app`.

Cara ini sudah diuji. Hasil impor ke database kosong dengan urutan di atas identik dengan db_sji: 36 tabel, 10 view, 31 routine, 19 trigger, 1 event, 11 CHECK, 51 FK, 124 indeks, dan 5 partisi. Semua view bisa dijalankan, dan `sp_generate_payroll_bulanan` berjalan tanpa error.

### Cara B — dari migration (riwayat lengkap)

```bash
mysql -u root -e "CREATE DATABASE db_sji CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate
```

Migration menjalankan seluruh riwayat `Komponen/` secara berurutan. Hasil akhirnya sama dengan `final/`.

## Dokumentasi terkait

- `docs/optimasi-database.md`: indeks dan hasil EXPLAIN sebelum dan sesudah.
- `docs/backup-recovery.md`: `php artisan sji:backup-db` / `sji:restore-db`.
- `docs/teknologi-lanjutan.md`: JSON, partisi, FULLTEXT.
- `docs/keamanan-database.md`: role, GRANT, SQL SECURITY DEFINER, uji SQL injection.

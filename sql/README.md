# Folder `sql/` — Database db_sji

Database aplikasi PT Satu Jaya Indonesia memakai MySQL 8. Folder ini berisi tiga jenis file:

| Folder / file | Isi | Dipakai untuk |
|---|---|---|
| `schema_sji.sql` | Skema awal proyek (sebelum dipecah per komponen) | Sejarah; tidak perlu dijalankan lagi |
| `Komponen/` | **Riwayat perubahan** database, berurutan `01` … `24`. Tiap file yang punya pasangan `_rollback.sql` bisa dibatalkan | Dijalankan otomatis oleh **migration Laravel** (`php artisan migrate`) lewat `App\Support\SqlKomponen` |
| `final/` | **Kondisi akhir** database, satu file per jenis objek, tanpa `DEFINER` | Instalasi baru tanpa menjalankan riwayat, bahan laporan, dan rujukan cepat |

## `Komponen/` — riwayat perubahan

Setiap file mencatat perubahan pada satu tahap pengerjaan. Isinya menimpa atau melengkapi file sebelumnya, misalnya `sp_generate_payroll_bulanan` ditulis ulang di beberapa file. Karena itu, untuk melihat definisi yang **berlaku sekarang**, buka `final/`, bukan `Komponen/`.

- File diberi blok `-- [TAHAP n]` yang dibaca migration dengan `SqlKomponen::jalankanBlok()`.
- `10_hak_akses_database.sql` adalah satu-satunya sumber hak akses (role dan GRANT). File ini dijalankan ulang di akhir setiap migration yang menambah atau menghapus objek.
- `18_finalisasi_database.sql` berisi perubahan Finalisasi Tahap 1–8, dengan pasangan `18_finalisasi_database_rollback.sql`.
- `19_log_append_only.sql` membuat `tbl_log_aktivitas` append-only: trigger `BEFORE UPDATE`/`BEFORE DELETE` menolak perubahan log dengan `SIGNAL 45000`, sedangkan INSERT tetap normal. Pasangannya `19_log_append_only_rollback.sql`. Tidak ada event purge log; bila log lama perlu dibuang, pakai `ALTER TABLE tbl_log_aktivitas TRUNCATE PARTITION p<tahun>` (DDL partisi tidak memicu trigger). Trigger ini juga sudah ada di `sql/final/05_trigger.sql`.
- `20_perbaikan_jadwal_event_sertifikat.sql` mengembalikan jam mulai `ev_update_status_sertifikat` ke 00:05 (ekspor final sempat tercatat 06:05). Pasangannya `20_perbaikan_jadwal_event_sertifikat_rollback.sql`. Event ini kemudian dihapus oleh file 23.
- `21_arus_kas_dari_transaksi.sql` membuat `v_arus_kas_bulanan` dan `profit_bulan_berjalan` di `v_dashboard_eksekutif` menghitung pemasukan dari invoice `Lunas` (`tgl_pembayaran`) dan pengeluaran dari payroll `Sudah Lunas` (`total_gaji` pada `tgl_bayar`), ditambah baris lama `tbl_mutasi_keuangan`. Pasangannya `21_arus_kas_dari_transaksi_rollback.sql`.
- `22_backup_shift_integritas.sql` mengubah roker shift menjadi **backup shift satu arah** (`tbl_jadwal_kerja.id_karyawan_pengganti`, diisi hanya lewat pengajuan Disetujui), membuat `tbl_koreksi_check_out` (lupa check-out), dan menghapus `trg_cegah_absen_ganda` karena absensi ganda sudah dijaga `UNIQUE uq_absensi_karyawan_tgl`. Pasangannya `22_backup_shift_integritas_rollback.sql`.
- `23_sertifikat_ketidakhadiran_integritas.sql` (migration `2026_11_27_212431`), pasangannya `23_sertifikat_ketidakhadiran_integritas_rollback.sql`:
  - **Sertifikat.** Kolom `tbl_sertifikat.status` dan event `ev_update_status_sertifikat` dihapus. Status dan sisa hari dihitung view `v_sertifikat_karyawan` dari `tgl_kadaluarsa`, sehingga tidak pernah basi dan tidak bergantung pada `event_scheduler`. Ada `CHECK chk_sertifikat_tanggal (tgl_kadaluarsa > tgl_terbit)`. Berkas dipindah dari disk `public` ke disk privat `local` dan hanya bisa dibuka pemilik atau HRD.
  - **Pengajuan Izin/Sakit/Cuti oleh karyawan.** Tabel `tbl_pengajuan_ketidakhadiran` dijaga CHECK dan trigger (tanggal tidak boleh bertabrakan, isi pengajuan tidak bisa diubah). Persetujuan **hanya** lewat `sp_setujui_pengajuan_ketidakhadiran`, yang mencatat satu baris `tbl_absensi` per hari kerja. Hari Libur di roster dilewati, dan persetujuan ditolak bila ada tanggal yang sudah punya absensi atau payroll periodenya sudah dibuat. `status_kehadiran` mendapat nilai `Cuti` (cuti berbayar): dihitung hari masuk di payroll Lapangan dan tidak memotong gaji Internal.
  - **Koreksi check-out.** `sp_cek_koreksi_check_out` (dipanggil `trg_koreksi_check_out_insert`/`_update`) menolak jam keluar yang kurang dari 30 menit setelah check-in, atau lebih dari 4 jam setelah jadwal pulang (16 jam setelah check-in bila tanpa jadwal). Tanpa batas ini, check-in 08:00 dengan koreksi 07:00 akan dihitung hampir 23 jam kerja oleh putaran 24 jam di `trg_auto_hitung_lembur`.
  - **Konsistensi `id_karyawan`.** `tbl_laporan_aktivitas.id_karyawan` dan `tbl_p2h.id_karyawan` sebenarnya bisa diturunkan dari `id_absensi`. Kolom ini sengaja dipertahankan untuk indeks riwayat per karyawan (denormalisasi terkendali), lalu dikunci dengan FOREIGN KEY komposit `(id_absensi, id_karyawan) → tbl_absensi (id, id_karyawan)`, sehingga nilainya tidak mungkin berbeda dari pemilik absensi.
- `24_alpha_otomatis.sql` (migration `2026_11_27_220209`), pasangannya `24_alpha_otomatis_rollback.sql`:
  - **Alpha otomatis.** Event `ev_tandai_alpha_otomatis` berjalan setiap 15 menit dan memanggil `sp_tandai_alpha_otomatis(NOW())`. Procedure ini mencatat satu baris `tbl_absensi` berstatus `Alpha` (tanpa jam masuk) untuk setiap pekerja yang punya jadwal **Kerja** di roster dan tidak check-in sampai shift berakhir. Pekerja adalah pemilik jadwal, atau rekan pengganti bila shift di-backup. Bila ada dua shift pada tanggal yang sama, Alpha baru dicatat setelah shift terakhir berakhir.
  - Yang dilewati: karyawan Nonaktif/Cuti, periode yang payroll-nya sudah dibuat, dan shift yang berakhir lebih dari 7 hari lalu (data lama tidak diisi mundur).
  - Alpha tanpa check-in tetap bisa diperbaiki. `sp_setujui_pengajuan_ketidakhadiran` dan `sp_input_ketidakhadiran` **mengganti** baris Alpha itu dengan Izin/Sakit/Cuti, bukan menolaknya karena "sudah ada absensi".
  - Butuh `event_scheduler=ON`. Migration memberi peringatan bila scheduler mati.
- **Ada dua file bernomor 14** (`14_bpjs_klien.sql` dan `14_komponen_gaji.sql`). Keduanya sudah dipanggil migration dengan nama itu. Ini riwayat dan **tidak perlu di-rename**, karena mengganti nama akan memutus migration yang merujuknya.
- File lama tidak boleh diubah. Perubahan baru selalu ditambahkan sebagai file atau blok baru.

## `final/` — kondisi akhir

| File | Isi |
|---|---|
| `01_tabel.sql` | Struktur 38 tabel (termasuk tabel internal Laravel), lengkap dengan PRIMARY/FOREIGN KEY, indeks, FULLTEXT, CHECK constraint, kolom generated, dan partisi `tbl_log_aktivitas` |
| `02_view.sql` | 11 view (tidak ada view yang memakai view lain) |
| `03_function.sql` | Function |
| `04_procedure.sql` | Stored procedure |
| `05_trigger.sql` | Trigger, per tabel sesuai urutan eksekusinya (`BEFORE` lalu `AFTER`, mengikuti urutan `FOLLOWS`) |
| `06_event.sql` | Event `ev_tandai_alpha_otomatis` (setiap 15 menit), dibuat dengan `time_zone '+07:00'` supaya `NOW()` di dalamnya WIB seperti aplikasi |
| `07_hak_akses.sql` | Salinan `Komponen/10_hak_akses_database.sql` (role per bagian + `sji_role_app`) |
| `08_data_awal.sql` | Data wajib: `tbl_roles`, `tbl_jenis_layanan`, dan isi tabel `migrations` |

Semua file diekspor dari database setelah migration terakhir (`sql/Komponen/24`) dengan `SHOW CREATE ...`. Klausa `DEFINER=` dan nilai `AUTO_INCREMENT=` dihapus supaya bisa diimpor oleh user MySQL mana pun di komputer lain. Teks `SQL SECURITY DEFINER` pada view dan routine tetap ada. Itu mode keamanan, bukan nama user. Setiap file diawali `SET NAMES utf8mb4`, karena klien `mysql` di Windows memakai charset `cp850` bila tidak diatur.

> **Setiap kali ada migration baru, ekspor ulang `final/`.** Ekspor sebelumnya sempat tertinggal dari database (masih berisi `trg_cegah_absen_ganda`, belum berisi `tbl_koreksi_check_out`, kolom backup shift, dan `fn_gaji_pokok_berlaku`), sehingga instalasi lewat Cara A menghasilkan skema lama.

> **Perhatian untuk `07_hak_akses.sql`:** role MySQL berlaku untuk seluruh server, dan file ini diawali `REVOKE ALL PRIVILEGES FROM` setiap role. Jalankan file ini **hanya pada database yang dipakai aplikasi**. Kalau dijalankan pada database lain di server yang sama, misalnya database uji, hak role atas `db_sji` ikut tercabut. Untuk memulihkannya, jalankan ulang file ini pada `db_sji`, atau `php artisan tinker --execute 'App\Support\SqlKomponen::pasangHakAkses();'`.

`08_data_awal.sql` sengaja menyertakan isi tabel `migrations`. Dengan begitu `php artisan migrate` setelah impor tidak menjalankan ulang migration yang perubahannya sudah ada di `final/`.

## Instalasi baru

### Cara A — dari `final/` (cepat)

**Perhatikan urutannya:** `03_function` harus dijalankan **sebelum** `02_view`, karena view memanggil function seperti `fn_hitung_jam_kerja` dan MySQL memeriksa function itu saat view dibuat.

Opsi `--comments` membuat komentar di dalam body procedure, function, dan trigger ikut tersimpan. Tanpa opsi ini klien `mysql` membuang komentar tersebut, meskipun logikanya tetap sama.

```bash
mysql -u root -e "CREATE DATABASE db_sji CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root --comments db_sji < sql/final/01_tabel.sql
mysql -u root --comments db_sji < sql/final/03_function.sql
mysql -u root --comments db_sji < sql/final/02_view.sql
mysql -u root --comments db_sji < sql/final/04_procedure.sql
mysql -u root --comments db_sji < sql/final/05_trigger.sql
mysql -u root --comments db_sji < sql/final/06_event.sql
mysql -u root --comments db_sji < sql/final/07_hak_akses.sql
mysql -u root --comments db_sji < sql/final/08_data_awal.sql
```

Kalau memakai phpMyAdmin, impor file satu per satu dengan urutan yang sama.

Langkah berikutnya:

1. Salin `.env`, isi `DB_DATABASE=db_sji`, lalu jalankan `php artisan key:generate`.
2. Jalankan `php artisan migrate`. Hasilnya harus *Nothing to migrate*, atau hanya migration yang lebih baru dari ekspor ini.
3. Buat akun login pertama lewat seeder atau perintah aplikasi.
4. Opsional, user MySQL per bagian: `php artisan sji:buat-user-db`. User aplikasi dengan hak terbatas: `php artisan sji:buat-user-db app`.

Aktifkan event scheduler supaya Alpha otomatis tercatat: `SET GLOBAL event_scheduler = ON;`, atau tambahkan `event_scheduler=ON` di `my.ini` agar tetap aktif setelah restart. Status sertifikat tidak butuh event karena dihitung saat dibaca oleh view `v_sertifikat_karyawan`.

Cara ini sudah diuji pada 27-11-2026. File 01–06 dan 08 diimpor ke database kosong dengan urutan di atas (07 dilewati, lihat peringatan di atas). Hasilnya, `mysqldump --no-data --routines --triggers --events` dari database itu **identik** dengan db_sji: 38 tabel, 11 view, 37 routine, 29 trigger, 1 event, 17 CHECK, 58 FK, 136 indeks, dan 5 partisi. Semua view bisa dijalankan, dan `sp_generate_payroll_bulanan` serta `sp_tandai_alpha_otomatis` berjalan tanpa error.

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

# Optimasi Database db_sji — Indeks Komposit

Dokumen ini adalah bukti optimasi Tahap 5 finalisasi database. Isinya: query yang dianalisis, hasil `EXPLAIN` sebelum dan sesudah indeks ditambahkan, serta penjelasannya.

- **Server:** MySQL 8.0.30
- **Objek database:** `sql/Komponen/18_finalisasi_database.sql` blok `[TAHAP 5]`
- **Migration:** `2026_11_23_060544_finalisasi_tahap5_indeks_optimasi`

## 1. Indeks yang ditambahkan

| Indeks | Tabel (kolom) | Untuk query |
|---|---|---|
| `idx_penempatan_karyawan_status` | `tbl_penempatan (id_karyawan, status)` | Mencari penempatan aktif seorang karyawan, dan payroll per karyawan |
| `idx_penempatan_klien_status_mulai` | `tbl_penempatan (id_klien, status, tgl_mulai)` | Monitoring sebaran per klien dan invoice per klien |
| `idx_sertifikat_kadaluarsa_status` | `tbl_sertifikat (tgl_kadaluarsa, status)` | Event harian `ev_update_status_sertifikat` (diganti `idx_sertifikat_kadaluarsa (tgl_kadaluarsa)` di `sql/Komponen/23`, lihat Q4) |

Sebelumnya sudah diperiksa bahwa belum ada indeks dengan kolom yang sama. Indeks yang ada saat itu hanya `PRIMARY`, `idx_penempatan_status (status)`, dan indeks FK satu kolom.

## 2. Cara pengujian

Data asli `db_sji` terlalu kecil: 3 penempatan, 0 sertifikat, dan 5 absensi. Dengan data sekecil itu, optimizer hampir selalu memilih full scan, sehingga `EXPLAIN` tidak bisa menunjukkan perbedaan. Karena itu pengujian dijalankan di database terpisah, `db_sji_uji_optimasi`:

1. Strukturnya disalin dari `db_sji` dengan `mysqldump --no-data --routines --skip-triggers`.
2. Database itu diisi data buatan:

| Tabel | Jumlah baris |
|---|---|
| `tbl_klien` | 300 |
| `tbl_karyawan` | 20.000 |
| `tbl_penempatan` | 60.000 (tiap karyawan punya 2 riwayat dan 1 penempatan Aktif) |
| `tbl_kontrak` | 60.000 |
| `tbl_sertifikat` | 40.000 |
| `tbl_absensi` | 500.000 (20.000 karyawan × 25 hari) |

3. Statistik diperbarui dengan `ANALYZE TABLE`.
4. `EXPLAIN` dan `EXPLAIN ANALYZE` dijalankan sebelum indeks ditambahkan.
5. Indeks ditambahkan, `ANALYZE TABLE` dijalankan lagi, lalu `EXPLAIN` diulang.

Data asli `db_sji` tidak diubah. Ke `db_sji` hanya ditambahkan indeks lewat migration. Database uji dihapus lagi setelah pengujian.

## 3. Hasil per query

Kolom yang dibandingkan:
- `type`: cara tabel dibaca. Dari yang paling baik: `const`, `eq_ref`, `ref`, `range`, `index`, `ALL`.
- `key`: indeks yang benar-benar dipakai.
- `rows`: perkiraan jumlah baris yang dibaca.
- `filtered`: persentase baris yang lolos kondisi WHERE.

### Q1 — Payroll: kontrak penempatan satu karyawan dalam satu bulan

Ini query di `sp_generate_payroll_bulanan` untuk mengambil gaji dan hari kerja dari kontrak terbaru.

```sql
SELECT c.gaji_pokok, c.hari_kerja_per_bulan, p.id_klien
FROM tbl_kontrak c JOIN tbl_penempatan p ON c.id_penempatan = p.id
WHERE p.id_karyawan = 12345 AND p.tgl_mulai <= '2026-11-30'
  AND (p.tgl_selesai IS NULL OR p.tgl_selesai >= '2026-11-01')
ORDER BY p.tgl_mulai DESC, p.id DESC, c.id DESC LIMIT 1;
```

| | tabel | type | key | rows | filtered |
|---|---|---|---|---|---|
| Sebelum | p | ref | fk_pen_karyawan | 3 | 13,33 |
| Sebelum | c | eq_ref | uq_kontrak_penempatan | 1 | 100 |
| Sesudah | p | ref | fk_pen_karyawan | 3 | 13,33 |
| Sesudah | c | eq_ref | uq_kontrak_penempatan | 1 | 100 |

**Hasil: tidak berubah, dan memang tidak perlu.** Pencarian per karyawan sudah memakai indeks FK `id_karyawan`, dan tiap karyawan hanya punya sedikit penempatan. Query ini tidak memfilter `status`, sehingga kolom kedua indeks baru tidak membantu. Absensi di payroll juga sudah memakai indeks unik `uq_absensi_karyawan_tgl (id_karyawan, tgl)`.

### Q2 — Monitoring sebaran per klien

Ini query halaman Operasional → Monitoring Sebaran, lewat view `v_monitoring_sebaran_operasional` dengan filter klien.

```sql
SELECT id_penempatan, nip, nama, nama_perusahaan, tgl_mulai, sisa_hari_kontrak
FROM v_monitoring_sebaran_operasional WHERE id_klien = 151 ORDER BY sisa_hari_kontrak;
```

| | tabel p: type | key | rows | Extra | waktu nyata |
|---|---|---|---|---|---|
| Sebelum | index_merge | intersect(fk_pen_klien, idx_penempatan_status) | 100 | Using intersect | ±14,0 ms |
| Sesudah (dipilih optimizer) | index_merge | intersect(fk_pen_klien, idx_penempatan_status) | 100 | Using intersect | ±14,5 ms |
| Sesudah, `FORCE INDEX (idx_penempatan_klien_status_mulai)` | ref | idx_penempatan_klien_status_mulai | 200 | — | **±1,9 ms** |

**Hasil: indeks baru sekitar 7× lebih cepat, tetapi optimizer belum memilihnya sendiri.** Penyebabnya, indeks lama `idx_penempatan_status` (hanya kolom `status`) membuat optimizer memakai *index merge intersect*. Cara ini membaca sekitar 19.950 entri berstatus Aktif lalu mengirisnya dengan 200 entri milik klien. Indeks komposit langsung menuju 200 baris (`id_klien = ? AND status = 'Aktif'`).

Bukti tambahan: ketika `idx_penempatan_status` dijadikan `INVISIBLE`, waktu query turun ke ±2,7 ms.

**Rekomendasi:** hapus atau jadikan INVISIBLE `idx_penempatan_status` setelah dipastikan tidak ada query yang hanya memfilter `status`. Langkah ini belum dilakukan karena berada di luar cakupan tahap ini.

### Q3 — Penempatan aktif seorang karyawan

```sql
SELECT id, id_klien, tgl_mulai FROM tbl_penempatan WHERE id_karyawan = 12345 AND status = 'Aktif';
```

| | type | key | ref | rows | filtered | Extra | waktu nyata |
|---|---|---|---|---|---|---|---|
| Sebelum | ref | fk_pen_karyawan | const | 3 | 50,00 | Using where | 0,053 ms |
| Sesudah | ref | **idx_penempatan_karyawan_status** | const,const | **1** | **100,00** | — | **0,032 ms** |

**Hasil: membaik.** Sebelumnya MySQL membaca semua penempatan milik karyawan itu, lalu menyaring `status` satu per satu (`Using where`, filtered 50%). Sekarang kedua kondisi dijawab langsung oleh indeks (`ref const,const`), dan yang dibaca hanya 1 baris yang memang dicari. Perbedaannya makin terasa kalau seorang karyawan punya banyak riwayat penempatan.

### Q4 — Event harian status sertifikat

> **Catatan sesudah `sql/Komponen/23`:** kolom `tbl_sertifikat.status` dan event ini sudah dihapus. Status kini dihitung view `v_sertifikat_karyawan` dari `tgl_kadaluarsa`, jadi tidak ada lagi `UPDATE` harian. Indeks diganti `idx_sertifikat_kadaluarsa (tgl_kadaluarsa)` untuk filter "sisa < 30 hari". Pengukuran di bawah dipertahankan sebagai riwayat.

Ini isi event `ev_update_status_sertifikat`.

```sql
UPDATE tbl_sertifikat SET status = 'Kadaluarsa'
WHERE status <> 'Kadaluarsa' AND tgl_kadaluarsa < CURDATE();
```

| | type | possible_keys | key | rows | waktu nyata (versi SELECT COUNT) |
|---|---|---|---|---|---|
| Sebelum | index (scan PRIMARY) | — | PRIMARY | 40.194 | ±24,1 ms (table scan) |
| Sesudah | index (scan PRIMARY) | idx_sertifikat_kadaluarsa_status | PRIMARY | 40.194 | ±18,4 ms (*covering index range scan*) |

**Hasil: sebagian.** Versi `SELECT` dari kondisi yang sama sekarang memakai `idx_sertifikat_kadaluarsa_status` sebagai *covering index range scan* dan tidak lagi membaca seluruh tabel. Untuk `UPDATE`, optimizer tetap memilih scan PRIMARY. Di data uji, sekitar 70% sertifikat (28.482 dari 40.000) sudah melewati tanggal kadaluarsa, sehingga rentang `tgl_kadaluarsa < hari ini` terlalu lebar dan scan biasa dianggap lebih murah.

Di data nyata, event ini berjalan setiap hari, jadi sertifikat yang perlu diubah setiap kali hanya sedikit. Namun kondisi `tgl_kadaluarsa < CURDATE()` tetap mencakup semua sertifikat lama. Urutan kolom alternatif `(status, tgl_kadaluarsa)` juga sudah diuji, dan rencana `UPDATE`-nya sama.

## 4. Catatan teknis

- Saat `idx_penempatan_karyawan_status` dan `idx_penempatan_klien_status_mulai` dibuat, MySQL membuang indeks FK otomatis `fk_pen_karyawan` dan `fk_pen_klien`, karena indeks komposit sudah diawali kolom yang sama dan bisa dipakai FK.
- Karena itu `down()` membuat ulang kedua indeks FK sebelum menghapus indeks komposit. Tanpa itu muncul error 1553: *needed in a foreign key constraint*.
- Setelah siklus rollback lalu migrate, `fk_pen_karyawan` dan `fk_pen_klien` tetap ada sebagai indeks biasa. Keduanya tumpang tindih dengan indeks komposit tetapi tidak mengganggu.

## 5. Kesimpulan

| Query | Dampak indeks baru |
|---|---|
| Q1 payroll per karyawan | Tidak berubah; sudah optimal dengan indeks FK |
| Q2 monitoring per klien | Sekitar 7× lebih cepat bila indeks dipakai; optimizer masih memilih index merge karena `idx_penempatan_status` |
| Q3 penempatan aktif karyawan | Membaik: `ref const,const`, 1 baris, filtered 100%, tanpa `Using where` |
| Q4 event sertifikat | SELECT memakai covering index range scan (24→18 ms); UPDATE tetap scan karena rentangnya lebar |

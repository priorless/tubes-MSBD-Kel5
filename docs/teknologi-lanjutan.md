# Teknologi Basis Data Lanjutan di db_sji

Dokumen ini menjelaskan tiga fitur MySQL 8 yang dipakai di Tahap 7 finalisasi database: penyimpanan dokumen JSON, partisi tabel, dan FULLTEXT index. Untuk masing-masing dijelaskan apa itu, mengapa dipakai, contoh query, dan hasil pengujiannya.

- **Objek database:** `sql/Komponen/18_finalisasi_database.sql` blok `[TAHAP 7]`
- **Migration:** `2026_11_23_062404_finalisasi_tahap7_teknologi_lanjutan`
- **Tes otomatis:** `tests/Feature/TeknologiLanjutanDatabaseTest.php`

---

## 1. JSON — model dokumen di dalam MySQL

### Apa

MySQL 8 punya tipe kolom `JSON` yang menyimpan dokumen (pasangan kunci-nilai bersarang) dan memvalidasi isinya. Isi dokumen bisa dibaca dengan `JSON_EXTRACT()` / `JSON_UNQUOTE()`, atau dengan operator singkat `->>`.

Di db_sji ada dua tempat yang menyimpan dokumen JSON:

| Tabel | Kolom JSON | Isi |
|---|---|---|
| `tbl_jadwal_kerja_riwayat` | `data_sebelum`, `data_sesudah` | Keadaan jadwal sebelum dan sesudah diubah: `status`, `jam_masuk`, `jam_pulang`, `durasi_menit`, `id_template_shift`, `nama_shift_snapshot` |
| `tbl_pertukaran_shift` | `snapshot_pengaju`, `snapshot_rekan` | Jadwal kedua karyawan saat tukar shift diajukan |

### Mengapa

- **Riwayat harus membeku.** Jadwal bisa terus berubah, tetapi riwayat harus menyimpan keadaan persis pada saat itu, termasuk nama shift yang mungkin kemudian diganti. Satu dokumen per perubahan lebih sederhana daripada membuat tabel riwayat dengan kolom yang harus selalu mengikuti tabel jadwal.
- **Struktur bisa bertambah.** Kalau tabel jadwal mendapat kolom baru, riwayat lama tetap sah tanpa perlu migrasi.

Kelemahannya, dokumen JSON sulit dibaca langsung dan sulit dicari cepat. Dua hal berikut menutupi kelemahan itu.

### 1a. View yang mengubah dokumen menjadi kolom

`v_riwayat_jadwal_kerja` menampilkan riwayat sebagai kolom biasa, lengkap dengan nama karyawan, klien, tanggal kerja, dan nama pengubah. View ini sudah ditampilkan di halaman **Operasional → Jadwal Kerja** sebagai kartu "Riwayat Perubahan Jadwal".

```sql
SELECT r.id, j.tanggal_kerja, r.aksi,
       NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.data_sebelum, '$.status')), 'null')    AS status_sebelum,
       NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.data_sebelum, '$.jam_masuk')), 'null') AS jam_masuk_sebelum,
       r.status_sesudah,
       NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.data_sesudah, '$.jam_masuk')), 'null') AS jam_masuk_sesudah
FROM tbl_jadwal_kerja_riwayat r JOIN tbl_jadwal_kerja j ON j.id = r.id_jadwal_kerja;
```

`NULLIF(..., 'null')` diperlukan karena `JSON_UNQUOTE` mengubah nilai JSON `null` menjadi teks `"null"`. Dengan `NULLIF`, nilai itu dikembalikan menjadi SQL `NULL`.

`v_pertukaran_shift_snapshot` melakukan hal yang sama untuk snapshot tukar shift. View ini memakai operator singkat `->>`, yang setara dengan `JSON_UNQUOTE(JSON_EXTRACT(...))`:

```sql
SELECT ps.id, kp.nama AS nama_pengaju,
       ps.snapshot_pengaju ->> '$.jam_masuk' AS jam_masuk_pengaju,
       ps.snapshot_rekan   ->> '$.jam_masuk' AS jam_masuk_rekan
FROM tbl_pertukaran_shift ps JOIN tbl_karyawan kp ON kp.id = ps.id_karyawan_pengaju;
```

### 1b. Kolom generated STORED + indeks

Fungsi JSON di dalam `WHERE` tidak bisa memakai indeks biasa. Karena itu status sesudah perubahan dijadikan kolom generated yang ikut tersimpan (STORED) dan diberi indeks:

```sql
ALTER TABLE tbl_jadwal_kerja_riwayat
  ADD COLUMN status_sesudah VARCHAR(5)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data_sesudah, '$.status'))) STORED;
ALTER TABLE tbl_jadwal_kerja_riwayat ADD INDEX idx_riwayat_status_sesudah (status_sesudah, created_at);
```

Kolom ini diisi otomatis oleh MySQL setiap kali baris riwayat ditulis, sehingga aplikasi tidak perlu diubah. Kolom yang dipilih adalah `status_sesudah`, karena pertanyaan yang sering muncul adalah "perubahan mana yang menjadikan hari Libur/Kerja".

**Hasil:**

```text
EXPLAIN SELECT id FROM tbl_jadwal_kerja_riwayat WHERE status_sesudah = 'Libur';
-> type = ref, key = idx_riwayat_status_sesudah
```

Tes `test_view_riwayat_jadwal_membaca_json_sebelum_dan_sesudah_serta_kolom_generated` memeriksa tiga hal:
- view mengembalikan `Kerja / 08:00:00 / 16:00:00 / Pagi` → `Libur / NULL / NULL`;
- nilai JSON `null` terbaca sebagai `NULL`;
- query memakai indeks kolom generated.

---

## 2. Partisi tabel — `tbl_log_aktivitas` per tahun

### Apa

Partisi membagi satu tabel menjadi beberapa bagian fisik berdasarkan nilai suatu kolom. Bagi aplikasi tabelnya tetap satu, tetapi MySQL hanya membaca partisi yang relevan (*partition pruning*). Satu partisi juga bisa dibuang sekaligus, misalnya untuk mengarsipkan log satu tahun.

### Mengapa

`tbl_log_aktivitas` diisi trigger pada setiap perubahan penting (karyawan, penempatan, payroll, invoice, akun, klien). Tabel ini terus bertambah dan hampir selalu dibaca per rentang waktu, misalnya di halaman CEO → Log Aktivitas yang punya filter tanggal. Data seperti ini paling cocok dipartisi per waktu.

### Rancangan

```sql
ALTER TABLE tbl_log_aktivitas DROP PRIMARY KEY, ADD PRIMARY KEY (id, waktu);
ALTER TABLE tbl_log_aktivitas PARTITION BY RANGE (UNIX_TIMESTAMP(waktu)) (
  PARTITION p2025 VALUES LESS THAN (UNIX_TIMESTAMP('2026-01-01 00:00:00')),
  PARTITION p2026 VALUES LESS THAN (UNIX_TIMESTAMP('2027-01-01 00:00:00')),
  PARTITION p2027 VALUES LESS THAN (UNIX_TIMESTAMP('2028-01-01 00:00:00')),
  PARTITION p2028 VALUES LESS THAN (UNIX_TIMESTAMP('2029-01-01 00:00:00')),
  PARTITION pmax  VALUES LESS THAN MAXVALUE
);
```

Hal-hal yang perlu diperhatikan:

1. **`UNIX_TIMESTAMP(waktu)`, bukan `YEAR(waktu)`.** Kolom `waktu` bertipe `TIMESTAMP`. MySQL tidak mengizinkan `YEAR()` sebagai fungsi partisi untuk TIMESTAMP (error 1486), karena nilainya bergantung zona waktu. Satu-satunya fungsi yang diizinkan untuk TIMESTAMP adalah `UNIX_TIMESTAMP()`. Dengan batas tiap 1 Januari, hasilnya tetap satu partisi per tahun, dan tipe kolom tidak perlu diubah.
2. **PRIMARY KEY menjadi `(id, waktu)`.** Setiap PRIMARY/UNIQUE KEY pada tabel berpartisi wajib memuat kolom partisi. `id` tetap AUTO_INCREMENT dan tetap unik.
3. **Tanpa FOREIGN KEY.** MySQL tidak mendukung FK pada tabel berpartisi. Tidak ada tabel yang merujuk `tbl_log_aktivitas`. `id_user` juga sengaja tanpa FK (lihat Tahap 4) supaya log tetap ada walaupun akun dihapus.
4. **Trigger log tetap jalan.** Trigger hanya melakukan `INSERT`, dan MySQL otomatis menaruh baris ke partisi yang sesuai.
5. **`pmax`** menampung log tahun 2029 ke atas. Menjelang 2029, partisi bisa dipecah dengan `ALTER TABLE ... REORGANIZE PARTITION pmax INTO (...)`.
6. **`down()`** menjalankan `REMOVE PARTITIONING` dan mengembalikan `PRIMARY KEY (id)`.

### Hasil

```text
SELECT PARTITION_NAME FROM information_schema.PARTITIONS WHERE TABLE_NAME = 'tbl_log_aktivitas';
-> p2025, p2026, p2027, p2028, pmax

EXPLAIN SELECT COUNT(*) FROM tbl_log_aktivitas WHERE waktu >= '2026-01-01' AND waktu < '2027-01-01';
-> partitions = p2026   (empat partisi lain tidak dibaca)
```

Tes `test_log_aktivitas_berpartisi_per_tahun_dan_trigger_log_tetap_menulis` memeriksa tiga hal:
- klien baru tetap tercatat oleh trigger;
- barisnya berada di partisi tahun yang benar (`SELECT ... FROM tbl_log_aktivitas PARTITION (p2026)`);
- `EXPLAIN` hanya menyebut partisi itu.

---

## 3. FULLTEXT index — pencarian nama

### Apa

FULLTEXT index memecah teks menjadi kata-kata dan mengindeksnya. Pencarian dilakukan dengan `MATCH (kolom) AGAINST (...)`. Berbeda dengan `LIKE '%kata%'`, pencarian ini memakai indeks walaupun polanya dimulai dengan wildcard.

```sql
ALTER TABLE tbl_karyawan ADD FULLTEXT INDEX ft_karyawan_nama (nama);
ALTER TABLE tbl_klien    ADD FULLTEXT INDEX ft_klien_nama_perusahaan (nama_perusahaan);
```

### Mengapa

`LIKE '%kata%'` selalu memindai seluruh tabel, karena B-Tree index tidak bisa dipakai kalau pola diawali `%`. Pencarian nama karyawan (HRD → Data Karyawan) dan nama klien (Data Klien di Operasional dan HRD) adalah pencarian yang paling sering diketik pengguna.

### Penerapan di aplikasi

Aturan pencarian ada di `App\Support\PencarianTeks` dan dipakai oleh `Hrd\KaryawanController` dan `Operasional\KlienController`:

| Kondisi | Query |
|---|---|
| MySQL dan setiap kata ≥ 3 huruf | `MATCH (nama) AGAINST ('+kata1* +kata2*' IN BOOLEAN MODE)`, artinya semua kata wajib ada dan dicocokkan dari awal kata |
| Ada kata < 3 huruf, atau bukan MySQL (SQLite saat tes) | `nama LIKE '%kata%'`, seperti sebelumnya |
| Selalu | NIP / kode klien tetap dicari dengan `LIKE`, karena berupa kode, bukan teks bebas |

Contoh query yang dibuat untuk input `"fulltext zulkar"`:

```sql
SELECT ... FROM tbl_karyawan k
WHERE (MATCH (k.nama) AGAINST ('+fulltext* +zulkar*' IN BOOLEAN MODE) OR k.nip LIKE '%fulltext zulkar%');
```

Beberapa catatan:
- **Batas 3 huruf** mengikuti `innodb_ft_min_token_size = 3`. Kata yang lebih pendek tidak diindeks InnoDB, sehingga untuk kata seperti itu tetap dipakai LIKE supaya hasilnya tidak hilang.
- **Operator boolean dari pengguna dibuang.** Karakter selain huruf, angka, dan spasi dihapus sebelum disusun menjadi `+kata*`. Nilainya juga dikirim lewat binding `?`.
- **Perubahan perilaku yang disengaja.** FULLTEXT mencocokkan dari **awal kata**: "zulkar" dan "fulltext" menemukan "Zulkarnain Fulltextuji", tetapi potongan di tengah kata seperti "karnain" tidak. Hal ini diuji secara eksplisit. Bagi pencarian nama orang dan perusahaan, perilaku ini wajar, karena pengguna mengetik awal nama.
- **Data baru langsung bisa dicari setelah di-commit.** Di aplikasi setiap simpan langsung di-commit, jadi tidak terasa. Di tes, data harus di-commit dulu, karena FULLTEXT InnoDB tidak melihat baris yang belum di-commit.

### Hasil

Tes `test_pencarian_karyawan_dan_klien_memakai_fulltext_dari_awal_kata` memeriksa empat kasus:
- `q=fulltext zulkar` menemukan karyawan;
- `q=karnain` (tengah kata) tidak menemukan karyawan;
- `q=samudra fulltextuji` menemukan klien;
- `q=PT` (2 huruf) tetap menemukan klien lewat LIKE.

---

## Ringkasan objek

| Objek | Jenis |
|---|---|
| `tbl_jadwal_kerja_riwayat.status_sesudah` + `idx_riwayat_status_sesudah` | Kolom generated STORED + indeks |
| `v_riwayat_jadwal_kerja` | View (JSON_EXTRACT/JSON_UNQUOTE). GRANT SELECT ke `sji_role_operasional` |
| `v_pertukaran_shift_snapshot` | View (operator `->>`). GRANT SELECT ke `sji_role_operasional` |
| `tbl_log_aktivitas` | Dipartisi RANGE per tahun (p2025–p2028, pmax). PRIMARY KEY (id, waktu) |
| `ft_karyawan_nama`, `ft_klien_nama_perusahaan` | FULLTEXT index |

# Keamanan Database db_sji

Dokumen ini menjelaskan lapisan-lapisan pengaman database aplikasi PT Satu Jaya Indonesia, hasil audit query mentah di kode aplikasi, dan hasil uji SQL injection.

- **Objek database:** `sql/Komponen/10_hak_akses_database.sql`, serta `sql/Komponen/18_finalisasi_database.sql` blok `[TAHAP 8]`
- **Tes otomatis:** `tests/Feature/Keamanan/SqlInjectionTest.php`, dijalankan terhadap MySQL `db_sji`

## 1. Lapisan keamanan

Pengamanan dibuat berlapis. Kalau satu lapis lolos, lapis berikutnya tetap membatasi kerusakan.

### Lapis 1 — Validasi input

- **Login** (`App\Http\Requests\Auth\LoginRequest`): username wajib, maksimal 50 karakter, dan harus cocok dengan `^[A-Za-z0-9._-]+$`. Kalau tidak cocok, muncul pesan *"Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung."*
  - Pola ini sama dengan aturan saat akun dibuat (`StoreUserRequest`, `UpdateUserRequest`, `StoreKaryawanRequest`), jadi tidak ada username sah yang tertolak.
  - Saat diterapkan, seluruh 9 username dan seluruh NIP di db_sji sudah dicek dan semuanya cocok dengan pola.
  - Karakter khas injeksi (`'`, `"`, `;`, spasi, `#`, `=`) sudah ditolak sebelum query dijalankan.
- **Form lain** memakai validasi Laravel: `integer`, `date`, `in:`, `exists:`, `Rule::in`, `max:` dan sejenisnya, sesuai jenis datanya.
- **Pembatasan percobaan login:** 5 kali per username dan alamat IP (`RateLimiter`), untuk menahan tebak password.

### Lapis 2 — Prepared statement (parameter binding)

Semua nilai dari pengguna dikirim ke MySQL sebagai parameter `?`, tidak pernah disambung ke dalam teks SQL. MySQL menerimanya sebagai data, bukan sebagai perintah.

- **Query Builder dan Eloquent** (`where('nama', 'like', $pola)`, `whereFullText(...)`) otomatis memakai binding.
- **Query mentah** juga memakai binding, misalnya:
  - `DB::statement('CALL sp_generate_payroll_bulanan(?, ?)', [$bulan, $tahun])`
  - `whereRaw('fn_sisa_hari_dokumen(s.tgl_kadaluarsa) < ?', [$batasHari])`
- **Pencarian nama** (karyawan, klien, absensi) memakai `LIKE` dengan pola `'%'.$kata.'%'` yang dikirim sebagai binding, sehingga tanda kutip dan karakter lain di input hanya menjadi bagian teks yang dicari.

### Lapis 3 — Hak akses minimum (ROLE + GRANT)

Ada satu role MySQL per bagian dan satu role untuk aplikasi (`10_hak_akses_database.sql`):

| Role | Hak |
|---|---|
| `sji_role_hrd`, `sji_role_operasional`, `sji_role_keuangan`, `sji_role_ceo` | Hanya tabel, kolom, view, dan routine yang dibutuhkan bagiannya. Data pribadi hanya untuk HRD (GRANT per kolom) |
| **`sji_role_app`** (baru) | `SELECT, INSERT, UPDATE, DELETE, EXECUTE` pada database aplikasi. **Tanpa** `CREATE`, `DROP`, `ALTER`, `INDEX`, `TRIGGER`, `EVENT`, `GRANT` |

User aplikasi `sji_app` dibuat dengan `php artisan sji:buat-user-db app`. Password-nya acak dan hanya ditampilkan sekali. Setelah itu, di `.env` server isi:

```dotenv
DB_USERNAME=sji_app
DB_PASSWORD=<password dari perintah di atas>
```

`php artisan migrate` tetap dijalankan dengan user pemilik skema (root/admin), karena migration butuh DDL. Default `.env` di laptop pengembang tetap `root`.

Hak `sji_role_app` sudah diuji langsung dengan user sementara:

```text
SELECT COUNT(*) FROM v_absensi_harian;               -> 5   (boleh)
SELECT fn_hitung_jam_kerja('08:00:00','16:00:00');    -> 8   (boleh)
UPDATE tbl_klien ... (dalam transaksi, di-rollback)   -> OK  (boleh)
CREATE TABLE x (id INT);                              -> ERROR 1142 (ditolak)
DROP TABLE tbl_users;                                 -> ERROR 1142 (ditolak)
ALTER TABLE tbl_users ADD COLUMN x INT;               -> ERROR 1142 (ditolak)
GRANT SELECT ON db_sji.* TO ...;                      -> ERROR 1044 (ditolak)
```

Artinya, seandainya ada celah injeksi yang lolos, penyerang yang memakai koneksi aplikasi tetap **tidak bisa** menghapus tabel, mengubah struktur, atau memberi dirinya hak tambahan.

### Lapis 4 — SQL SECURITY DEFINER

Semua procedure, function, dan view db_sji memakai `SQL SECURITY DEFINER`. Role bagian cukup diberi `EXECUTE`/`SELECT` pada routine dan view, tanpa hak langsung ke tabel dasarnya. Contoh: Keuangan tidak punya `INSERT/UPDATE` ke `tbl_payroll`. Payroll hanya bisa dibuat lewat `sp_generate_payroll_bulanan`, yang menjalankan seluruh validasinya sendiri.

Aturan bisnis penting juga dijaga trigger, misalnya `trg_cek_jadwal_kerja_*`, `trg_auto_hitung_lembur`, dan CHECK constraint. Jadi data tetap konsisten walaupun ada yang menulis langsung ke database tanpa lewat aplikasi.

### Lapis 5 — Log aktivitas

Trigger mencatat perubahan penting ke `tbl_log_aktivitas`: tabel, aksi, id data, keterangan, dan **id user aplikasi** (dari `@app_user_id`, diisi middleware `SetAppUserIdDatabase`). CEO bisa membacanya di halaman Log Aktivitas. Tabel ini sengaja tanpa FK ke `tbl_users` supaya jejak tetap ada walaupun akunnya dihapus, dan tabelnya dipartisi per tahun.

## 2. Audit query mentah di `app/`

Semua pemanggilan `whereRaw`, `selectRaw`, `orderByRaw`, `havingRaw`, `DB::raw`, `DB::select(One)`, `DB::statement`, dan `DB::unprepared` di folder `app/` (60 tempat, di luar perintah konsol) sudah diperiksa.

| Kelompok | Contoh lokasi | Hasil |
|---|---|---|
| Pemanggilan procedure/function dengan `?` | `DatabaseService` (seluruh `CALL sp_*` dan `SELECT fn_*`), `KeuanganKomponenGajiController:182,195`, `TenagaKerjaService:202` | Aman, semua nilai lewat binding |
| `whereRaw` dengan `?` | `Hrd\DashboardController:19`, `Hrd\SertifikatController:31`, `TenagaKerjaService:291` | Aman |
| SQL tetap tanpa input | `selectRaw('status, COUNT(*) as jumlah')`, `orderByRaw("CASE WHEN status = 'Aktif' ...")`, `DB::raw(1)`, `DB::selectOne('SELECT CURDATE() ...')`, `JamServer` (`SELECT NOW()`), ringkasan `AbsensiKaryawanController:81,181` | Aman, tidak ada input pengguna |
| `KeuanganController::jalankanProsedur($sql, $bindings)` | dipanggil di baris 73, 97, 216, 373 | Aman, `$sql` selalu literal `'CALL sp_...(?, ?)'` di kode, nilainya lewat `$bindings` |
| Nama kolom dinamis | `TenagaKerjaService:181,291,416` (`DATE(\`kolom\`)`, `SUM(\`kolom\`)`), `orderByDesc($kolom)` di baris 182, 379–380, 458–460 | Aman: nama kolom hanya dipilih dari daftar tetap `config('tenaga_kerja.kolom')` **dan** harus benar-benar ada di tabel (`kolomTabel()`), bukan dari request |
| `SqlKomponen::jalankanBlok` / `pasangHakAkses` | `DB::unprepared` | Aman, isinya dari file `sql/Komponen/*.sql` di repo, hanya dipakai migration |

**Kesimpulan audit:** tidak ditemukan input pengguna yang disambung ke teks SQL. Tidak ada nama kolom atau arah urut yang diambil langsung dari request. Kolom dinamis yang ada memakai whitelist dari konfigurasi dan dicocokkan dengan skema.

## 3. Hasil uji SQL injection

Payload yang diuji:

```text
' OR 1=1 #
admin'--
' OR '1'='1
" OR ""="
'; DROP TABLE tbl_users; --
```

| Uji | Hasil |
|---|---|
| Login dengan setiap payload sebagai username dan password (sudah ada user `admin`) | Tidak login (`assertGuest`), kembali ke halaman login dengan error, `tbl_users` tetap ada dan jumlah barisnya tidak berubah. **5/5 lolos** |
| Pesan validasi login dalam bahasa Indonesia | *"Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung."* **Lolos** |
| NIP dan username sah (`budi.santoso`, `hrd_01`, `SJI-2026-001`, NIP karyawan) | Lolos validasi pola dan sampai ke pengecekan password (*"Username atau password salah."*). **Lolos** |
| Pencarian karyawan (HRD), klien (HRD & Operasional), absensi harian, dan endpoint cari karyawan (JSON) dengan setiap payload | Semua HTTP 200 (tidak ada error 500). Data rahasia uji (`Rahasia Injeksi Karyawan`, `PT Rahasia Injeksi`) **tidak** ikut tampil, endpoint JSON mengembalikan `[]`, `tbl_users` tetap ada. **5/5 lolos** |

Total **12 tes, 12 lolos**, dijalankan terhadap MySQL 8 `db_sji` (bukan SQLite), sehingga perilaku parser MySQL (komentar `#`, `-- `, dan stacked query) ikut teruji. Payload diperlakukan sebagai teks biasa karena dikirim lewat binding. PDO MySQL juga tidak menjalankan beberapa pernyataan sekaligus dalam satu prepared statement, sehingga `; DROP TABLE ...` tidak pernah dieksekusi.

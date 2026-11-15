<?php

/*
|--------------------------------------------------------------------------
| Konfigurasi Modul Tenaga Kerja (Employee Self-Service)
|--------------------------------------------------------------------------
|
| File ini HANYA berisi nama tabel/view dan daftar KANDIDAT nama kolom.
| Saat aplikasi berjalan, modul mengecek kolom yang BENAR-BENAR ada di
| database (information_schema) lalu memakai kandidat pertama yang cocok.
| Jadi tidak ada nama kolom yang "dikarang": jika tidak ada kandidat yang
| cocok, halaman menampilkan pesan yang jelas, bukan error mentah.
|
| Jika nama kolom di db_sji ternyata berbeda, cukup tambahkan namanya
| ke daftar kandidat di bawah ini. Tidak perlu mengubah controller/view.
|
*/

return [

    /*
    | Identitas karyawan yang sedang login.
    |
    | Urutan pencarian id_karyawan (lihat App\Support\KaryawanAktif):
    |   1. Jika user SUDAH LOGIN: HANYA kolom id_karyawan pada akunnya.
    |      Jika kosong, akses ditolak — tidak ada fallback ke sumber lain.
    |   2. Jika BELUM login: session('id_karyawan').
    |   3. Jika BELUM login dan APP_ENV=local: TK_DEV_ID_KARYAWAN di file .env.
    |      Ini hanya untuk uji coba, otomatis mati di production.
    */
    'kolom_user_id_karyawan' => 'id_karyawan',
    'session_key' => 'id_karyawan',
    'dev_id_karyawan' => env('TK_DEV_ID_KARYAWAN'),

    'tabel' => [
        'karyawan' => 'tbl_karyawan',
        'absensi' => 'tbl_absensi',
        'rekap_absensi' => 'v_rekap_absensi_pribadi',
        'slip_gaji' => 'v_slip_gaji_detail',

        // sql/Komponen/07_komponen_orang4_absensi_foto.sql + 08_komponen_orang4_jadwal_penempatan.sql
        'penugasan_aktif' => 'v_penugasan_aktif',
        'absensi_harian' => 'v_absensi_harian',
    ],

    'kolom' => [
        // Primary key tbl_karyawan — HANYA dipakai untuk tbl_karyawan.
        'pk_karyawan' => ['id'],

        // Foreign key ke karyawan di tbl_absensi & view.
        // JANGAN tambahkan 'id' di sini: v_slip_gaji_detail.id adalah id PAYROLL,
        // sehingga filter akan menampilkan slip milik karyawan lain.
        'id_karyawan' => ['id_karyawan'],

        // Primary key tbl_absensi — hanya dibaca dari tbl_absensi (untuk ORDER BY).
        'id_absensi' => ['id_absensi', 'id'],

        // tbl_karyawan & v_slip_gaji_detail — identitas untuk filter slip gaji
        'nip' => ['nip'],

        // tbl_karyawan
        'nama' => ['nama_lengkap', 'nama_karyawan', 'nama'],
        'kategori' => ['kategori', 'kategori_karyawan', 'jenis_karyawan'],
        'jabatan' => ['jabatan', 'posisi'],
        'foto' => ['foto', 'foto_profil'],

        // tbl_absensi
        'tanggal' => ['tanggal', 'tgl_absensi', 'tanggal_absensi', 'tgl'],
        'jam_masuk' => ['jam_masuk', 'waktu_masuk', 'check_in'],
        'jam_keluar' => ['jam_keluar', 'waktu_keluar', 'check_out'],
        'status' => ['status_kehadiran', 'status', 'status_absensi'],
        'menit_terlambat' => ['menit_terlambat'],

        // tbl_absensi — path foto unggahan (disk privat)
        'foto_masuk' => ['foto_masuk'],
        'foto_keluar' => ['foto_keluar'],

        // v_rekap_absensi_pribadi (rekap per bulan) — untuk kartu ringkasan
        'rekap_hadir' => ['hari_hadir'],
        'rekap_terlambat' => ['hari_terlambat'],
        'rekap_tidak_hadir' => ['hari_tidak_hadir'],
        'rekap_lembur' => ['total_jam_lembur'],

        // v_slip_gaji_detail & v_rekap_absensi_pribadi (periode)
        'periode_bulan' => ['bulan', 'periode_bulan'],
        'periode_tahun' => ['tahun', 'periode_tahun'],
        'periode' => ['periode', 'periode_gaji'],
        'id_payroll' => ['id_payroll', 'id_slip'],
        'total_gaji' => ['total_gaji', 'gaji_bersih', 'take_home_pay', 'total_diterima', 'total'],
    ],

    // Kolom yang tidak boleh ditampilkan di halaman mana pun
    'kolom_tersembunyi' => ['id', 'password', 'remember_token', 'created_at', 'updated_at', 'deleted_at'],

    'per_halaman_riwayat' => 15,

    /*
    | Foto absensi (check-in & check-out) diunggah karyawan dari galeri/kamera perangkatnya.
    | Keterangan tanggal, jam, dan lokasi pada foto dibuat aplikasi kamera karyawan, bukan sistem.
    | Disimpan di disk privat dan hanya dapat dibuka lewat route absensi.foto (pemilik atau HRD).
    |   ukuran_maks_kb : batas file unggahan (foto ponsel umumnya 2–8 MB)
    |   sisi_maksimal  : sisi terpanjang setelah disimpan; cukup besar agar keterangan pada foto terbaca
    |   piksel_maks    : batas lebar/tinggi file asli agar pemrosesan tidak kehabisan memori
    */
    'foto' => [
        'disk' => 'local',
        'folder' => 'absensi',
        'ukuran_maks_kb' => 15360,
        'sisi_maksimal' => 1920,
        'piksel_maks' => 8000,
    ],
];

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
    |   1. Kolom id_karyawan pada user yang login (setelah fitur login dibuat).
    |   2. session('id_karyawan') — bisa diisi oleh fitur login teman Anda.
    |   3. KHUSUS APP_ENV=local: nilai TK_DEV_ID_KARYAWAN di file .env.
    |      Ini hanya untuk uji coba sebelum login selesai, otomatis mati di
    |      production.
    */
    'kolom_user_id_karyawan' => 'id_karyawan',
    'session_key'            => 'id_karyawan',
    'dev_id_karyawan'        => env('TK_DEV_ID_KARYAWAN'),

    'tabel' => [
        'karyawan'      => 'tbl_karyawan',
        'absensi'       => 'tbl_absensi',
        'rekap_absensi' => 'v_rekap_absensi_pribadi',
        'slip_gaji'     => 'v_slip_gaji_detail',
    ],

    'kolom' => [
        // Kolom penghubung ke karyawan (dipakai di tbl_karyawan, tbl_absensi, dan kedua view)
        'id_karyawan'   => ['id_karyawan'],
        'id_absensi'    => ['id_absensi'],

        // tbl_karyawan
        'nama'          => ['nama_lengkap', 'nama_karyawan', 'nama'],
        'kategori'      => ['kategori', 'kategori_karyawan', 'jenis_karyawan'],
        'jabatan'       => ['jabatan', 'posisi'],
        'foto'          => ['foto', 'foto_profil'],

        // tbl_absensi
        'tanggal'       => ['tanggal', 'tgl_absensi', 'tanggal_absensi', 'tgl'],
        'jam_masuk'     => ['jam_masuk', 'waktu_masuk', 'check_in'],
        'jam_keluar'    => ['jam_keluar', 'waktu_keluar', 'check_out'],
        'status'        => ['status', 'status_absensi', 'keterangan'],

        // v_slip_gaji_detail
        'periode_bulan' => ['bulan', 'periode_bulan'],
        'periode_tahun' => ['tahun', 'periode_tahun'],
        'periode'       => ['periode', 'periode_gaji'],
        'id_payroll'    => ['id_payroll', 'id_slip'],
        'total_gaji'    => ['total_gaji', 'gaji_bersih', 'take_home_pay', 'total_diterima', 'total'],
    ],

    // Kolom yang tidak boleh ditampilkan di halaman mana pun
    'kolom_tersembunyi' => ['password', 'remember_token', 'created_at', 'updated_at', 'deleted_at'],

    'per_halaman_riwayat' => 15,
];

<?php

use App\Http\Controllers\BerandaController;
use App\Http\Controllers\CeoController;
use App\Http\Controllers\TenagaKerjaController;
use App\Http\Controllers\UserManagementController;
use App\Http\Middleware\PastikanKaryawan;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

/*
|--------------------------------------------------------------------------
| Area setelah login
|--------------------------------------------------------------------------
| Semua route di sini wajib login dan diblokir jika user belum mengganti password (must_change_password).
| Batasi akses per modul dengan middleware role:<nama_role>,... (nama_role dari tbl_roles).
*/
Route::middleware(['auth', 'password.changed'])->group(function () {
    Route::get('/beranda', BerandaController::class)->name('beranda');

    /* =====================================================================
     * Manajemen User (CEO & HRD)
     * ===================================================================== */
    Route::middleware('role:'.Role::Ceo.','.Role::Hrd)->group(function () {
        Route::resource('manajemen-user', UserManagementController::class)
            ->parameters(['manajemen-user' => 'user'])
            ->names('users')
            ->except('show');
    });

    /* =====================================================================
     * CEO
     * ===================================================================== */
    Route::middleware('role:'.Role::Ceo)->prefix('ceo')->name('ceo.')->group(function () {
        Route::get('/dashboard', [CeoController::class, 'dashboard'])->name('dashboard');
        Route::get('/profit-klien', [CeoController::class, 'profitKlien'])->name('profit-klien');
    });

    /* =====================================================================
     * Tenaga Kerja (Karyawan Lapangan) — Modul Orang 4
     * role:Karyawan Lapangan  → hanya role ini, dan akun Nonaktif dikeluarkan.
     * PastikanKaryawan        → akun wajib terhubung ke tbl_karyawan (id_karyawan).
     * ===================================================================== */
    Route::middleware(['role:'.Role::KaryawanLapangan, PastikanKaryawan::class])
        ->prefix('tenaga-kerja')->name('tenaga-kerja.')
        ->controller(TenagaKerjaController::class)
        ->group(function () {
            Route::redirect('/', '/tenaga-kerja/absensi')->name('index');

            Route::get('/absensi', 'absensi')->name('absensi');
            Route::post('/absensi/check-in', 'checkIn')->middleware('throttle:10,1')->name('absensi.check-in');
            Route::post('/absensi/check-out', 'checkOut')->middleware('throttle:10,1')->name('absensi.check-out');

            Route::get('/riwayat-absensi', 'riwayat')->name('riwayat');
            Route::get('/riwayat-absensi/unduh', 'riwayatCsv')->name('riwayat.unduh');

            Route::get('/slip-gaji', 'slipGaji')->name('slip-gaji');

            Route::get('/profil', 'profil')->name('profil');
        });
});

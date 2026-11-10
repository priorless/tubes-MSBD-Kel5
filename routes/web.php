<?php

use App\Http\Controllers\BerandaController;
use App\Http\Controllers\CeoController;
use App\Http\Controllers\TenagaKerjaController;
use App\Http\Controllers\UserManagementController;
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
     * Tenaga Kerja (Karyawan Lapangan)
     * ===================================================================== */
    Route::middleware('role:'.Role::KaryawanLapangan)->prefix('tenaga-kerja')->name('tenaga-kerja.')->group(function () {
        Route::get('/absensi', [TenagaKerjaController::class, 'absensi'])->name('absensi');
        Route::post('/absensi/check-in', [TenagaKerjaController::class, 'checkIn'])->name('absensi.check-in');
        Route::post('/absensi/check-out', [TenagaKerjaController::class, 'checkOut'])->name('absensi.check-out');
        Route::get('/riwayat-absensi', [TenagaKerjaController::class, 'riwayatAbsensi'])->name('riwayat-absensi');
        Route::get('/slip-gaji', [TenagaKerjaController::class, 'slipGaji'])->name('slip-gaji');
        Route::get('/profil', [TenagaKerjaController::class, 'profil'])->name('profil');
    });
});

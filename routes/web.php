<?php

use App\Http\Controllers\BerandaController;
use App\Http\Controllers\CeoController;
use App\Http\Controllers\Hrd\DashboardController as HrdDashboardController;
use App\Http\Controllers\Hrd\KaryawanController;
use App\Http\Controllers\Hrd\PoolKaryawanController;
use App\Http\Controllers\Hrd\SertifikatController;
use App\Http\Controllers\KeuanganController;
use App\Http\Controllers\Operasional\KlienController;
use App\Http\Controllers\OperasionalController;
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
| Route login, logout, dan ganti password ada di routes/auth.php.
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
     * HRD
     * ===================================================================== */
    Route::middleware('role:'.Role::Hrd)->prefix('hrd')->name('hrd.')->group(function () {
        Route::get('/', HrdDashboardController::class)->name('dashboard');
        Route::resource('karyawan', KaryawanController::class)->except('show');
        Route::get('pool-karyawan', PoolKaryawanController::class)->name('pool');
        Route::resource('sertifikat', SertifikatController::class)->only(['index', 'create', 'store', 'destroy']);
    });

    /* =====================================================================
     * Operasional
     * ===================================================================== */
    Route::middleware('role:'.Role::Operasional)->prefix('operasional')->name('operasional.')->group(function () {
        Route::resource('klien', KlienController::class)->except('show');

        Route::get('penempatan', [OperasionalController::class, 'createPenempatan'])->name('penempatan.create');
        Route::get('penempatan/create', [OperasionalController::class, 'createPenempatan']);
        Route::post('penempatan', [OperasionalController::class, 'storePenempatan'])->name('penempatan.store');

        Route::get('rotasi', [OperasionalController::class, 'createRotasi'])->name('rotasi.create');
        Route::get('rotasi/create', [OperasionalController::class, 'createRotasi']);
        Route::post('rotasi', [OperasionalController::class, 'storeRotasi'])->name('rotasi.store');

        Route::get('monitoring', [OperasionalController::class, 'monitoring'])->name('monitoring');
    });

    /* =====================================================================
     * Keuangan
     * ===================================================================== */
    Route::middleware('role:'.Role::Keuangan)->prefix('keuangan')->name('keuangan.')->controller(KeuanganController::class)->group(function () {
        Route::get('payroll', 'payroll')->name('payroll.index');
        Route::post('payroll/generate', 'generatePayroll')->name('payroll.generate');
        Route::patch('payroll/{id}/cair', 'tandaiPayrollCair')->whereNumber('id')->name('payroll.cair');

        Route::get('invoice', 'invoice')->name('invoice.index');
        Route::post('invoice', 'generateInvoice')->name('invoice.generate');
        Route::patch('invoice/{id}/lunas', 'tandaiInvoiceLunas')->whereNumber('id')->name('invoice.lunas');
        Route::get('invoice/{id}/struk', 'strukInvoice')->whereNumber('id')->name('invoice.struk');

        Route::get('mutasi', 'mutasi')->name('mutasi.index');
    });

    /* =====================================================================
     * Tenaga Kerja (Employee Self-Service untuk Karyawan Lapangan)
     * ===================================================================== */
    Route::middleware(['role:'.Role::KaryawanLapangan, PastikanKaryawan::class])
        ->prefix('tenaga-kerja')
        ->name('tenaga-kerja.')
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

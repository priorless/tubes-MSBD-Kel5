<?php

use App\Http\Controllers\Operasional\KlienController;
use App\Http\Controllers\OperasionalController;
use App\Http\Controllers\KeuanganController;
use App\Http\Controllers\Hrd\DashboardController;
use App\Http\Controllers\Hrd\KaryawanController;
use App\Http\Controllers\Hrd\PoolKaryawanController;
use App\Http\Controllers\Hrd\SertifikatController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CeoController;
use App\Http\Controllers\TenagaKerjaController;
use App\Http\Middleware\PastikanKaryawan;

Route::get('/', function () {
    return view('welcome');
});

/* =========================================================================
     * CEO
     * ========================================================================= */
Route::prefix('ceo')->name('ceo.')->group(function () {
    Route::get('/dashboard', [CeoController::class, 'dashboard'])->name('dashboard');
    Route::get('/profit-klien', [CeoController::class, 'profitKlien'])->name('profit-klien');
});

/* =========================================================================
- TENAGA KERJA (Employee Self-Service)
- Setelah fitur login tersedia, ubah middleware menjadi:
-     ->middleware(['auth', PastikanKaryawan::class])
- ========================================================================= */
Route::prefix('tenaga-kerja')->name('tenaga-kerja.')
    ->middleware(PastikanKaryawan::class)
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
Route::prefix('operasional')->name('operasional.')->group(function () {
    Route::resource('klien', KlienController::class)->except('show');

    Route::get('penempatan', [OperasionalController::class, 'createPenempatan'])->name('penempatan.create');
    Route::get('penempatan/create', [OperasionalController::class, 'createPenempatan']);
    Route::post('penempatan', [OperasionalController::class, 'storePenempatan'])->name('penempatan.store');

    Route::get('rotasi', [OperasionalController::class, 'createRotasi'])->name('rotasi.create');
    Route::get('rotasi/create', [OperasionalController::class, 'createRotasi']);
    Route::post('rotasi', [OperasionalController::class, 'storeRotasi'])->name('rotasi.store');

    Route::get('monitoring', [OperasionalController::class, 'monitoring'])->name('monitoring');
Route::prefix('keuangan')->name('keuangan.')->controller(KeuanganController::class)->group(function () {
    Route::get('payroll', 'payroll')->name('payroll.index');
    Route::post('payroll/generate', 'generatePayroll')->name('payroll.generate');
    Route::patch('payroll/{id}/cair', 'tandaiPayrollCair')->whereNumber('id')->name('payroll.cair');

    Route::get('invoice', 'invoice')->name('invoice.index');
    Route::post('invoice', 'generateInvoice')->name('invoice.generate');
    Route::patch('invoice/{id}/lunas', 'tandaiInvoiceLunas')->whereNumber('id')->name('invoice.lunas');

    Route::get('mutasi', 'mutasi')->name('mutasi.index');
Route::prefix('hrd')->name('hrd.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::resource('karyawan', KaryawanController::class)->except('show');
    Route::get('pool-karyawan', PoolKaryawanController::class)->name('pool');
    Route::resource('sertifikat', SertifikatController::class)->only(['index', 'create', 'store', 'destroy']);
});

<?php

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
<?php

use App\Http\Controllers\BerandaController;
use App\Http\Controllers\Ceo\LogAktivitasController;
use App\Http\Controllers\CeoController;
use App\Http\Controllers\FotoAbsensiController;
use App\Http\Controllers\Hrd\AbsensiKaryawanController;
use App\Http\Controllers\Hrd\DashboardController as HrdDashboardController;
use App\Http\Controllers\Hrd\KaryawanController;
use App\Http\Controllers\Hrd\KetidakhadiranController;
use App\Http\Controllers\Hrd\PersetujuanLemburController;
use App\Http\Controllers\Hrd\PoolKaryawanController;
use App\Http\Controllers\Hrd\SertifikatController;
use App\Http\Controllers\KeuanganController;
use App\Http\Controllers\Operasional\AkhiriPenempatanController;
use App\Http\Controllers\Operasional\BerandaOperasionalController;
use App\Http\Controllers\Operasional\JadwalKerjaController;
use App\Http\Controllers\Operasional\KlienController;
use App\Http\Controllers\Operasional\RekapOperasionalController;
use App\Http\Controllers\Operasional\TemplateJadwalController;
use App\Http\Controllers\Operasional\TemplateShiftController;
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

    // Foto absensi unggahan (disk privat): hanya karyawan pemilik atau HRD — dicek di controller.
    Route::get('/absensi/foto/{absensi}/{jenis}', FotoAbsensiController::class)
        ->whereNumber('absensi')
        ->whereIn('jenis', ['masuk', 'keluar'])
        ->name('absensi.foto');

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
        Route::get('/log-aktivitas', LogAktivitasController::class)->name('log-aktivitas');
    });

    /* =====================================================================
     * HRD
     * ===================================================================== */
    Route::middleware('role:'.Role::Hrd)->prefix('hrd')->name('hrd.')->group(function () {
        Route::get('/', HrdDashboardController::class)->name('dashboard');
        Route::resource('karyawan', KaryawanController::class)->except('show');
        Route::get('pool-karyawan', PoolKaryawanController::class)->name('pool');
        Route::get('absensi-karyawan/karyawan/cari', [AbsensiKaryawanController::class, 'cariKaryawan'])->name('absensi.karyawan.cari');
        Route::get('absensi-karyawan/rekap/pdf', [AbsensiKaryawanController::class, 'exportPdf'])->name('absensi.rekap.pdf');
        Route::get('absensi-karyawan', AbsensiKaryawanController::class)->name('absensi');
        Route::post('absensi-karyawan/ketidakhadiran', KetidakhadiranController::class)->name('absensi.ketidakhadiran');
        Route::post('absensi-karyawan/lembur/{absensi}', PersetujuanLemburController::class)->whereNumber('absensi')->name('absensi.lembur');
        Route::resource('sertifikat', SertifikatController::class)->only(['index', 'create', 'store', 'destroy']);
    });

    /* =====================================================================
     * Operasional
     * ===================================================================== */
    Route::middleware('role:'.Role::Operasional)->prefix('operasional')->name('operasional.')->group(function () {
        Route::get('beranda', BerandaOperasionalController::class)->name('beranda');
        Route::get('rekap', [RekapOperasionalController::class, 'index'])->name('rekap.index');
        Route::get('rekap/ekspor', [RekapOperasionalController::class, 'export'])->name('rekap.export');
        Route::resource('klien', KlienController::class);
        Route::patch('klien/{klien}/status', [KlienController::class, 'updateStatus'])->whereNumber('klien')->name('klien.status');
        Route::get('jadwal-kerja', JadwalKerjaController::class)->name('jadwal-kerja');
        Route::get('jadwal-kerja/template-shift', [TemplateShiftController::class, 'index'])
            ->name('jadwal-kerja.template-shift.index');
        Route::post('jadwal-kerja/template-shift', [TemplateShiftController::class, 'store'])
            ->name('jadwal-kerja.template-shift.store');
        Route::put('jadwal-kerja/template-shift/{templateShift}', [TemplateShiftController::class, 'update'])
            ->whereNumber('templateShift')
            ->name('jadwal-kerja.template-shift.update');
        Route::patch('jadwal-kerja/template-shift/{templateShift}/nonaktifkan', [TemplateShiftController::class, 'deactivate'])
            ->whereNumber('templateShift')
            ->name('jadwal-kerja.template-shift.deactivate');
        Route::post('jadwal-kerja/template-shift/standar', [TemplateShiftController::class, 'storeStandar'])
            ->name('jadwal-kerja.template-shift.standar');
        Route::controller(TemplateJadwalController::class)
            ->prefix('jadwal-kerja/template-jadwal')
            ->name('jadwal-kerja.template-jadwal.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('buat', 'create')->name('create');
                Route::get('{templateJadwal}', 'show')->whereNumber('templateJadwal')->name('show');
                Route::get('{templateJadwal}/edit', 'edit')->whereNumber('templateJadwal')->name('edit');
                Route::get('{templateJadwal}/terapkan', 'applyPreview')->whereNumber('templateJadwal')->name('apply.preview');
                Route::post('/', 'store')->name('store');
                Route::post('dari-jadwal', 'storeFromSchedule')->name('store-from-schedule');
                Route::put('{templateJadwal}', 'update')->whereNumber('templateJadwal')->name('update');
                Route::patch('{templateJadwal}/nonaktifkan', 'deactivate')->whereNumber('templateJadwal')->name('deactivate');
                Route::post('{templateJadwal}/terapkan', 'apply')->whereNumber('templateJadwal')->name('apply');
            });
        Route::post('jadwal-kerja/atur', [JadwalKerjaController::class, 'atur'])->name('jadwal-kerja.atur');
        Route::post('jadwal-kerja', [JadwalKerjaController::class, 'store'])->name('jadwal-kerja.store');
        Route::put('jadwal-kerja/{jadwal}', [JadwalKerjaController::class, 'update'])
            ->whereNumber('jadwal')
            ->name('jadwal-kerja.update');
        Route::get('jadwal-kerja/salin/pratinjau', [JadwalKerjaController::class, 'previewCopy'])
            ->name('jadwal-kerja.copy.preview');
        Route::post('jadwal-kerja/salin', [JadwalKerjaController::class, 'copy'])
            ->name('jadwal-kerja.copy');

        Route::get('penempatan', [OperasionalController::class, 'indexPenempatan'])->name('penempatan.index');
        Route::get('penempatan/create', [OperasionalController::class, 'createPenempatan'])->name('penempatan.create');
        Route::get('penempatan/{penempatan}', [OperasionalController::class, 'showPenempatan'])->whereNumber('penempatan')->name('penempatan.show');
        Route::post('penempatan', [OperasionalController::class, 'storePenempatan'])->name('penempatan.store');
        Route::post('penempatan/{penempatan}/akhiri', AkhiriPenempatanController::class)->whereNumber('penempatan')->name('penempatan.akhiri');

        Route::get('rotasi', [OperasionalController::class, 'createRotasi'])->name('rotasi.create');
        Route::permanentRedirect('rotasi/create', '/operasional/rotasi')->name('rotasi.create-alias');
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
        Route::get('payroll/{id}/struk', 'strukPayroll')->whereNumber('id')->name('payroll.struk');

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

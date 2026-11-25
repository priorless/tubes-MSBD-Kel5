<?php

namespace App\Providers;

use App\Models\Role;
use App\Services\PertukaranShiftService;
use App\Support\JamServer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Satu titik waktu server per request (dapat dibekukan dalam pengujian).
        $this->app->scoped(JamServer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Badge sidebar: pengajuan backup shift yang masih menunggu tanggapan atau keputusan.
        View::composer('layouts.app', function (\Illuminate\View\View $view): void {
            $pengguna = auth()->user();
            $siap = $pengguna !== null && ! $pengguna->must_change_password;
            $badge = [];
            $judul = [];

            if ($siap && $pengguna->hasRole(Role::Operasional)) {
                $badge['operasional.jadwal-kerja'] = app(PertukaranShiftService::class)->jumlahMenunggu();
                $judul['operasional.jadwal-kerja'] = 'pengajuan backup shift menunggu persetujuan';
            }

            if ($siap && $pengguna->hasRole(Role::KaryawanLapangan) && $pengguna->id_karyawan !== null) {
                $badge['tenaga-kerja.tukar-shift.index'] = app(PertukaranShiftService::class)->jumlahPermintaanMasuk((int) $pengguna->id_karyawan);
                $judul['tenaga-kerja.tukar-shift.index'] = 'permintaan backup shift menunggu tanggapan Anda';
            }

            $view->with('badgeMenu', $badge)->with('judulBadgeMenu', $judul);
        });
    }
}

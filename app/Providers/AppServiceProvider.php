<?php

namespace App\Providers;

use App\Models\Role;
use App\Services\PertukaranShiftService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Badge sidebar "Jadwal Kerja": jumlah pengajuan tukar shift yang belum diproses (hanya untuk Operasional).
        View::composer('layouts.app', function (\Illuminate\View\View $view): void {
            $pengguna = auth()->user();

            $view->with('badgeMenu', [
                'operasional.jadwal-kerja' => $pengguna?->hasRole(Role::Operasional) && ! $pengguna->must_change_password
                    ? app(PertukaranShiftService::class)->jumlahMenunggu()
                    : 0,
            ]);
        });
    }
}

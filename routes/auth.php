<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ChangePasswordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Autentikasi
|--------------------------------------------------------------------------
| Tidak ada registrasi publik: akun staff dibuat oleh CEO/HRD lewat Manajemen User,
| akun karyawan lapangan dibuat otomatis oleh trigger trg_auto_akun_karyawan.
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Sengaja tanpa middleware password.changed agar halaman ini tetap bisa diakses saat wajib ganti password.
    Route::get('ganti-password', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::put('ganti-password', [ChangePasswordController::class, 'update'])->name('password.change.update');
});

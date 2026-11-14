<?php

use App\Http\Controllers\Hrd\DashboardController;
use App\Http\Controllers\Hrd\KaryawanController;
use App\Http\Controllers\Hrd\PoolKaryawanController;
use App\Http\Controllers\Hrd\SertifikatController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('hrd')->name('hrd.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::resource('karyawan', KaryawanController::class)->except('show');
    Route::get('pool-karyawan', PoolKaryawanController::class)->name('pool');
    Route::resource('sertifikat', SertifikatController::class)->only(['index', 'create', 'store', 'destroy']);
});

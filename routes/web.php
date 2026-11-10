<?php

use App\Http\Controllers\Operasional\KlienController;
use App\Http\Controllers\OperasionalController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
});

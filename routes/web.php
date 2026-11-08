<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CeoController;

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
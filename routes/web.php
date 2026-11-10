<?php

use App\Http\Controllers\KeuanganController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('keuangan')->name('keuangan.')->controller(KeuanganController::class)->group(function () {
    Route::get('payroll', 'payroll')->name('payroll.index');
    Route::post('payroll/generate', 'generatePayroll')->name('payroll.generate');
    Route::patch('payroll/{id}/cair', 'tandaiPayrollCair')->whereNumber('id')->name('payroll.cair');

    Route::get('invoice', 'invoice')->name('invoice.index');
    Route::post('invoice', 'generateInvoice')->name('invoice.generate');
    Route::patch('invoice/{id}/lunas', 'tandaiInvoiceLunas')->whereNumber('id')->name('invoice.lunas');

    Route::get('mutasi', 'mutasi')->name('mutasi.index');
});

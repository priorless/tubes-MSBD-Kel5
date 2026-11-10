<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman awal umum setelah login, dipakai jika modul role user belum memiliki dashboard sendiri.
 */
class BerandaController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('beranda', [
            'user' => $request->user()->load('role'),
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\KaryawanAktif;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Melindungi semua halaman modul Tenaga Kerja.
 * Jika identitas karyawan tidak diketahui, halaman tidak dibuka sama sekali.
 *
 * Setelah fitur login tersedia, route group cukup ditambah middleware 'auth'
 * di depan middleware ini.
 */
class PastikanKaryawan
{
    public function handle(Request $request, Closure $next): Response
    {
        if (KaryawanAktif::id() === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok'    => false,
                    'pesan' => 'Sesi Anda tidak dikenali. Silakan masuk kembali.',
                ], 401);
            }

            return response()->view('tenaga-kerja.belum-masuk', [], 401);
        }

        return $next($request);
    }
}

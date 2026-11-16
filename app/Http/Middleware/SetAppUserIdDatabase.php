<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengisi variabel sesi MySQL @app_user_id dengan id user yang login, agar trigger log aktivitas
 * (tbl_log_aktivitas) tahu siapa pelaku perubahan. Untuk tamu nilainya NULL.
 */
class SetAppUserIdDatabase
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $koneksi = DB::connection();

        if ($koneksi->getDriverName() === 'mysql') {
            $koneksi->statement('SET @app_user_id = ?', [$request->user()?->getAuthIdentifier()]);
        }

        return $next($request);
    }
}

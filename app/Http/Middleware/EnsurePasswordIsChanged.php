<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga semua route setelah login (beranda, foto, berkas, dan seluruh modul):
 * - akun Nonaktif langsung di-logout, termasuk sesi yang masih tersimpan setelah akunnya dinonaktifkan;
 * - semua menu diblokir sampai user mengganti password jika must_change_password = true.
 * Route logout dan ganti password (routes/auth.php) sengaja tidak memakai middleware ini.
 */
class EnsurePasswordIsChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && ! $user->isAktif()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('error', 'Akun Anda sudah dinonaktifkan. Hubungi HRD.');
        }

        if ($user?->must_change_password) {
            return redirect()->route('password.change')
                ->with('warning', 'Demi keamanan, silakan ganti password Anda terlebih dahulu.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memblokir semua menu sampai user mengganti password jika must_change_password = true.
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

        if ($user?->must_change_password) {
            return redirect()->route('password.change')
                ->with('warning', 'Demi keamanan, silakan ganti password Anda terlebih dahulu.');
        }

        return $next($request);
    }
}

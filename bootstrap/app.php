<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Models\User;
use App\Services\DatabaseService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/web.php', __DIR__.'/../routes/auth.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class,
            'password.changed' => EnsurePasswordIsChanged::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(function (Request $request): string {
            /** @var User|null $user */
            $user = $request->user();

            return $user?->homeUrl() ?? route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // SIGNAL SQLSTATE '45000' adalah pesan aturan bisnis dari database, bukan bug: jangan dilaporkan.
        $exceptions->dontReportWhen(
            fn (Throwable $exception) => $exception instanceof QueryException && DatabaseService::isPesanBisnis($exception),
        );

        // Tampilkan sebagai flash message yang ramah, bukan halaman error.
        $exceptions->render(function (QueryException $exception, Request $request) {
            if (! DatabaseService::isPesanBisnis($exception)) {
                return null;
            }

            $pesan = DatabaseService::pesanError($exception);

            if ($request->expectsJson()) {
                return response()->json(['message' => $pesan], 422);
            }

            $previousUrl = url()->previous();
            $tujuan = $previousUrl !== $request->fullUrl()
                ? $previousUrl
                : ($request->user()?->homeUrl() ?? route('login'));

            return redirect()->to($tujuan)
                ->withInput($request->except(['password', 'password_confirmation', 'current_password']))
                ->with('error', $pesan);
        });
    })->create();

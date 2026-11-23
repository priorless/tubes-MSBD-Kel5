<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ auth()->check() ? 'Akun Belum Terhubung' : 'Sesi Tidak Dikenali' }} - SJI</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo-sji.png') }}">
    @include('layouts.partials.font-tema')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="tema-operasional flex min-h-screen items-center justify-center bg-gray-50 px-4 font-sans text-gray-800 antialiased">
    <section class="card w-full max-w-md p-8 text-center">
        <img src="{{ asset('images/logo-sji.png') }}" alt="Logo PT Satu Jaya Indonesia" class="mx-auto w-24">
        @auth
            {{-- Sudah login, tetapi akun tidak memiliki id_karyawan (mis. akun staf/CEO) --}}
            <h1 class="mt-6 text-lg font-semibold">Akun Anda belum terhubung dengan data karyawan</h1>
            <p class="mt-2 text-sm text-gray-500">Portal Karyawan hanya dapat dibuka oleh akun yang terhubung ke data karyawan. Hubungi bagian HRD.</p>
            <div class="mt-6 flex justify-center gap-3">
                <a href="{{ auth()->user()->homeUrl() }}" class="btn-secondary">Kembali</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn-outline-danger">Keluar</button>
                </form>
            </div>
        @else
            <h1 class="mt-6 text-lg font-semibold">Sesi Anda tidak dikenali</h1>
            <p class="mt-2 text-sm text-gray-500">Halaman Portal Karyawan hanya dapat dibuka oleh karyawan yang sudah masuk (login).</p>
            <a href="{{ route('login') }}" class="btn-primary mt-6">Masuk</a>
        @endauth
    </section>
</body>
</html>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Masuk') - SJI</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo-sji.png') }}">
    @include('layouts.partials.font-tema')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="tema-operasional min-h-screen bg-gray-50 font-sans text-gray-800 antialiased">
    <div class="flex min-h-screen">
        {{-- Panel merek (layar lebar) --}}
        <div class="relative hidden w-1/2 flex-col items-center justify-center overflow-hidden bg-sji-biru-tua p-12 text-white lg:flex">
            <div class="absolute inset-y-0 left-0 w-2 bg-sji-merah"></div>
            <img src="{{ asset('images/logo-sji.png') }}" alt="Logo PT Satu Jaya Indonesia" class="w-56 drop-shadow-xl">
            <h2 class="mt-8 text-2xl font-bold tracking-wide">PT SATU JAYA INDONESIA</h2>
            <p class="mt-2 max-w-sm text-center text-sm text-blue-200">Sistem Informasi Perusahaan Penyedia Jasa Tenaga Kerja</p>
        </div>

        <div class="flex w-full items-center justify-center px-4 py-12 lg:w-1/2">
            <div class="w-full max-w-sm">
                <div class="mb-8 flex flex-col items-center text-center lg:hidden">
                    <img src="{{ asset('images/logo-sji.png') }}" alt="Logo PT Satu Jaya Indonesia" class="w-28">
                    <p class="mt-3 text-lg font-bold tracking-wide text-sji-biru-tua">PT SATU JAYA INDONESIA</p>
                </div>

                @include('layouts.partials.flash', ['tanpaRingkasanValidasi' => true])

                @yield('content')
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-flash-close]').forEach((el) => el.addEventListener('click', () => el.closest('[role="alert"]').remove()));
    </script>
</body>
</html>

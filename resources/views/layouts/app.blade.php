<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Beranda') - SJI</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo-sji.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-800 antialiased">
@php
    use App\Models\Role;

    /** @var \App\Models\User|null $penggunaAktif */
    $penggunaAktif = auth()->user();
    $menu = [];

    if ($penggunaAktif?->must_change_password) {
        $menu['Akun'] = [['Ganti Password', 'password.change', 'password.change']];
    } elseif ($penggunaAktif !== null) {
        if ($penggunaAktif->hasRole(Role::Ceo)) {
            $menu['Eksekutif'] = [
                ['Dashboard Eksekutif', 'ceo.dashboard', 'ceo.dashboard'],
                ['Profit Klien', 'ceo.profit-klien', 'ceo.profit-klien'],
            ];
        }

        if ($penggunaAktif->hasRole(Role::Hrd)) {
            $menu['HRD'] = [
                ['Dashboard HRD', 'hrd.dashboard', 'hrd.dashboard'],
                ['Data Karyawan', 'hrd.karyawan.index', 'hrd.karyawan.*'],
                ['Pool Karyawan', 'hrd.pool', 'hrd.pool'],
                ['Dokumen Sertifikat', 'hrd.sertifikat.index', 'hrd.sertifikat.*'],
            ];
        }

        if ($penggunaAktif->hasRole(Role::Operasional)) {
            $menu['Operasional'] = [
                ['Monitoring Sebaran', 'operasional.monitoring', 'operasional.monitoring'],
                ['Data Klien', 'operasional.klien.index', 'operasional.klien.*'],
                ['Penempatan', 'operasional.penempatan.create', 'operasional.penempatan.*'],
                ['Rotasi Karyawan', 'operasional.rotasi.create', 'operasional.rotasi.*'],
            ];
        }

        if ($penggunaAktif->hasRole(Role::Keuangan)) {
            $menu['Keuangan'] = [
                ['Payroll', 'keuangan.payroll.index', 'keuangan.payroll.*'],
                ['Invoice Klien', 'keuangan.invoice.index', 'keuangan.invoice.*'],
                ['Mutasi Kas', 'keuangan.mutasi.index', 'keuangan.mutasi.*'],
            ];
        }

        if ($penggunaAktif->hasRole(Role::KaryawanLapangan)) {
            $menu['Portal Karyawan'] = [
                ['Absensi', 'tenaga-kerja.absensi', 'tenaga-kerja.absensi*'],
                ['Riwayat Absensi', 'tenaga-kerja.riwayat', 'tenaga-kerja.riwayat*'],
                ['Slip Gaji', 'tenaga-kerja.slip-gaji', 'tenaga-kerja.slip-gaji'],
                ['Profil Saya', 'tenaga-kerja.profil', 'tenaga-kerja.profil'],
            ];
        }

        if ($penggunaAktif->can('viewAny', \App\Models\User::class)) {
            $menu['Administrasi'] = [['Manajemen User', 'users.index', 'users.*']];
        }
    }
@endphp

<div class="flex min-h-screen">
    {{-- Latar gelap saat sidebar dibuka di layar kecil --}}
    <div id="sidebarBackdrop" class="fixed inset-0 z-30 hidden bg-gray-900/50 md:hidden" data-sidebar-close></div>

    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-sji-biru-tua text-blue-100 transition-transform md:sticky md:top-0 md:h-screen md:translate-x-0">
        <a href="{{ $penggunaAktif?->homeUrl() ?? url('/') }}" class="flex items-center gap-3 border-b border-white/10 px-5 py-4">
            <img src="{{ asset('images/logo-sji.png') }}" alt="Logo PT Satu Jaya Indonesia" class="size-10 object-contain">
            <span class="leading-tight">
                <span class="block text-sm font-bold tracking-wide text-white">PT SATU JAYA</span>
                <span class="block text-xs text-blue-200">INDONESIA</span>
            </span>
        </a>

        <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-6" aria-label="Menu utama">
            @foreach ($menu as $grup => $itemMenu)
                <div>
                    <p class="px-3 text-xs font-semibold tracking-wider text-blue-300/70 uppercase">{{ $grup }}</p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($itemMenu as [$label, $namaRoute, $polaAktif])
                            @php $sedangAktif = request()->routeIs($polaAktif); @endphp
                            <li>
                                <a href="{{ route($namaRoute) }}"
                                   @class([
                                       'flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                                       'bg-white/10 text-white' => $sedangAktif,
                                       'hover:bg-white/5 hover:text-white' => ! $sedangAktif,
                                   ])
                                   @if ($sedangAktif) aria-current="page" @endif>
                                    <span @class(['h-4 w-1 rounded-full', 'bg-sji-emas' => $sedangAktif, 'bg-transparent' => ! $sedangAktif])></span>
                                    {{ $label }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="border-t border-white/10 px-5 py-4 text-xs text-blue-300/70">
            &copy; {{ now()->year }} PT Satu Jaya Indonesia
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-gray-200 bg-white/95 px-4 backdrop-blur sm:px-6">
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" class="rounded-md p-2 text-gray-500 hover:bg-gray-100 md:hidden" data-sidebar-open aria-label="Buka menu">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <h1 class="truncate text-lg font-semibold text-gray-800">
                    @hasSection('page-title')
                        @yield('page-title')
                    @else
                        @yield('title')
                    @endif
                </h1>
            </div>

            @if ($penggunaAktif)
                <div class="flex items-center gap-3 sm:gap-4">
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-medium text-gray-800">{{ $penggunaAktif->namaTampilan() }}</p>
                        <p class="text-xs text-gray-500">{{ $penggunaAktif->role?->nama_role }}</p>
                    </div>
                    <a href="{{ route('password.change') }}" class="hidden text-sm text-gray-500 hover:text-gray-800 sm:inline">Ganti Password</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-outline-danger btn-sm">Keluar</button>
                    </form>
                </div>
            @endif
        </header>

        <main class="flex-1 p-4 sm:p-6">
            @include('layouts.partials.flash')

            @yield('content')
        </main>
    </div>
</div>

<div id="tkToastWrap" aria-live="polite"></div>

<script>
    (() => {
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const setTerbuka = (terbuka) => {
            sidebar.classList.toggle('-translate-x-full', !terbuka);
            backdrop.classList.toggle('hidden', !terbuka);
        };
        document.querySelectorAll('[data-sidebar-open]').forEach((el) => el.addEventListener('click', () => setTerbuka(true)));
        document.querySelectorAll('[data-sidebar-close]').forEach((el) => el.addEventListener('click', () => setTerbuka(false)));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setTerbuka(false); });
        document.querySelectorAll('[data-flash-close]').forEach((el) => el.addEventListener('click', () => el.closest('[role="alert"]').remove()));
    })();
</script>
@stack('scripts')
</body>
</html>

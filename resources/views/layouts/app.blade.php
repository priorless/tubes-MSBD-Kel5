<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Beranda') - SJI</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo-sji.png') }}">
    @php
        // Tema redesign hanya untuk halaman role Operasional; modul lain tetap memakai tampilan lama.
        $temaOperasional = request()->routeIs('operasional.*');
    @endphp
    @if ($temaOperasional)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body @class(['min-h-screen bg-gray-50 font-sans text-gray-800 antialiased', 'tema-operasional' => $temaOperasional])>
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
                ['Log Aktivitas', 'ceo.log-aktivitas', 'ceo.log-aktivitas'],
            ];
        }

        if ($penggunaAktif->hasRole(Role::Hrd)) {
            $menu['HRD'] = [
                ['Dashboard HRD', 'hrd.dashboard', 'hrd.dashboard'],
                ['Data Karyawan', 'hrd.karyawan.index', 'hrd.karyawan.*'],
                ['Pool Karyawan', 'hrd.pool', 'hrd.pool'],
                ['Absensi Karyawan', 'hrd.absensi', 'hrd.absensi'],
                ['Dokumen Sertifikat', 'hrd.sertifikat.index', 'hrd.sertifikat.*'],
                ['Data Klien', 'hrd.klien.index', 'hrd.klien.*'],
            ];
        }

        if ($penggunaAktif->hasRole(Role::Operasional)) {
            $menu['Operasional'] = [
                ['Beranda', 'operasional.beranda', 'operasional.beranda'],
            ];
            $menu['Data Utama'] = [
                ['Data Klien', 'operasional.klien.index', 'operasional.klien.*'],
                ['Penempatan', 'operasional.penempatan.index', 'operasional.penempatan.*'],
            ];
            $menu['Kegiatan Operasional'] = [
                ['Rotasi Karyawan', 'operasional.rotasi.create', 'operasional.rotasi.*'],
                ['Jadwal Kerja', 'operasional.jadwal-kerja', 'operasional.jadwal-kerja*'],
                ['Monitoring Sebaran', 'operasional.monitoring', 'operasional.monitoring'],
            ];
            $menu['Laporan'] = [
                ['Rekap & Laporan', 'operasional.rekap.index', 'operasional.rekap.*'],
            ];
        }

        if ($penggunaAktif->hasRole(Role::Keuangan)) {
            $menu['Keuangan'] = [
                ['Komponen Gaji', 'keuangan.komponen-gaji.index', 'keuangan.komponen-gaji.*'],
                ['Payroll', 'keuangan.payroll.index', 'keuangan.payroll.*'],
                ['Invoice Klien', 'keuangan.invoice.index', 'keuangan.invoice.*'],
            ];
        }

        if ($penggunaAktif->hasRole(Role::KaryawanLapangan)) {
            $menu['Portal Karyawan'] = [
                ['Absensi', 'tenaga-kerja.absensi', 'tenaga-kerja.absensi*'],
                ['Tukar Shift', 'tenaga-kerja.tukar-shift.index', 'tenaga-kerja.tukar-shift.*'],
                ['Riwayat Absensi', 'tenaga-kerja.riwayat', 'tenaga-kerja.riwayat*'],
                ['Slip Gaji', 'tenaga-kerja.slip-gaji', 'tenaga-kerja.slip-gaji'],
                ['Profil Saya', 'tenaga-kerja.profil', 'tenaga-kerja.profil'],
            ];
        }

        if ($penggunaAktif->can('viewAny', \App\Models\User::class)) {
            $menu['Administrasi'] = [['Manajemen User', 'users.index', 'users.*']];
        }
    }

    // Ikon garis untuk menu Operasional (dipakai oleh tema Operasional).
    $ikonMenu = [
        'operasional.beranda' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
        'operasional.klien.index' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1"/><path d="M10 21v-3h4v3"/>',
        'operasional.penempatan.index' => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-3.9 3.1-7 7-7s7 3.1 7 7"/><path d="m16 11 2 2 4-4"/>',
        'operasional.rotasi.create' => '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
        'operasional.jadwal-kerja' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'operasional.monitoring' => '<path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2z"/><path d="M9 4v14M15 6v14"/>',
        'operasional.rekap.index' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
    ];

    // Inisial nama pengguna untuk avatar di header tema Operasional.
    $inisialPengguna = collect(explode(' ', (string) $penggunaAktif?->namaTampilan()))
        ->filter()
        ->take(2)
        ->map(fn (string $kata): string => mb_strtoupper(mb_substr($kata, 0, 1)))
        ->implode('');

    // Grup menu dari halaman yang sedang dibuka, untuk breadcrumb di header tema Operasional.
    $grupAktif = null;
    foreach ($menu as $grup => $itemMenu) {
        foreach ($itemMenu as [, , $polaAktif]) {
            if (request()->routeIs($polaAktif)) {
                $grupAktif = $grup;
            }
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
                                       'nav-link flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                                       'bg-white/10 text-white' => $sedangAktif,
                                       'hover:bg-white/5 hover:text-white' => ! $sedangAktif,
                                   ])
                                   @if ($sedangAktif) aria-current="page" @endif>
                                    <span @class(['nav-indikator h-4 w-1 rounded-full', 'bg-sji-emas' => $sedangAktif, 'bg-transparent' => ! $sedangAktif])></span>
                                    @if ($temaOperasional && isset($ikonMenu[$namaRoute]))
                                        <svg class="size-[19px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $ikonMenu[$namaRoute] !!}</svg>
                                    @endif
                                    {{ $label }}
                                    @if (($badgeMenu[$namaRoute] ?? 0) > 0)
                                        <span class="ml-auto rounded-full bg-sji-emas px-2 py-0.5 text-xs font-bold text-sji-biru-tua"
                                              title="{{ $badgeMenu[$namaRoute] }} pengajuan tukar shift menunggu persetujuan"
                                              data-badge-menu="{{ $namaRoute }}">{{ $badgeMenu[$namaRoute] }}</span>
                                    @endif
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
        <header class="app-header sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-gray-200 bg-white/95 px-4 backdrop-blur sm:px-6">
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" class="rounded-md p-2 text-gray-500 hover:bg-gray-100 md:hidden" data-sidebar-open aria-label="Buka menu">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <div class="min-w-0">
                    @if ($temaOperasional && $grupAktif)
                        <p class="op-crumb">{{ $grupAktif }}</p>
                    @endif
                    <h1 class="truncate text-lg font-semibold text-gray-800">
                        @hasSection('page-title')
                            @yield('page-title')
                        @else
                            @yield('title')
                        @endif
                    </h1>
                </div>
            </div>

            @if ($penggunaAktif)
                <div @class(['flex items-center gap-3 sm:gap-4', 'op-pengguna' => $temaOperasional])>
                    @if ($temaOperasional)
                        <span class="op-avatar" aria-hidden="true">{{ $inisialPengguna }}</span>
                    @endif
                    <div @class(['hidden sm:block', 'text-right' => ! $temaOperasional])>
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
@if ($temaOperasional)
    <script src="{{ asset('js/operasional.js') }}?v=1"></script>
@endif
@stack('scripts')
</body>
</html>

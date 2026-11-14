<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Tenaga Kerja') - SJI</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <span class="nav-link font-weight-bold">SJI - Tenaga Kerja</span>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <span class="nav-link">{{ $karyawan->nama }}</span>
            </li>
            <li class="nav-item">
                <a href="{{ route('password.change') }}" class="nav-link" title="Ganti Password"><i class="fas fa-key"></i></a>
            </li>
            <li class="nav-item">
                <form method="POST" action="{{ route('logout') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-link nav-link text-danger" title="Keluar"><i class="fas fa-sign-out-alt"></i> Keluar</button>
                </form>
            </li>
        </ul>
    </nav>

    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="{{ route('tenaga-kerja.absensi') }}" class="brand-link">
            <span class="brand-text font-weight-light ml-3">SJI</span>
        </a>
        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" role="menu">
                    <li class="nav-item">
                        <a href="{{ route('tenaga-kerja.absensi') }}" class="nav-link @if(request()->routeIs('tenaga-kerja.absensi')) active @endif">
                            <i class="nav-icon fas fa-clock"></i>
                            <p>Absensi</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('tenaga-kerja.riwayat-absensi') }}" class="nav-link @if(request()->routeIs('tenaga-kerja.riwayat-absensi')) active @endif">
                            <i class="nav-icon fas fa-calendar-alt"></i>
                            <p>Riwayat Absensi</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('tenaga-kerja.slip-gaji') }}" class="nav-link @if(request()->routeIs('tenaga-kerja.slip-gaji')) active @endif">
                            <i class="nav-icon fas fa-file-invoice-dollar"></i>
                            <p>Slip Gaji</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('tenaga-kerja.profil') }}" class="nav-link @if(request()->routeIs('tenaga-kerja.profil')) active @endif">
                            <i class="nav-icon fas fa-user"></i>
                            <p>Profil Saya</p>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </aside>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <h1>@yield('heading')</h1>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                @if (session('success'))
                    <div class="alert alert-success alert-dismissible">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Tutup">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        {{ session('success') }}
                    </div>
                @endif
                @if (session('error'))
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Tutup">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        {{ session('error') }}
                    </div>
                @endif

                @yield('content')
            </div>
        </section>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/js/adminlte.min.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Portal Karyawan') · SJI</title>

    {{-- Bootstrap 5.3.2 (sama dengan layout utama proyek) + Bootstrap Icons --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">

    <link href="{{ asset('css/tenaga-kerja.css') }}?v=1" rel="stylesheet">
    @stack('css')
</head>
<body class="tk-body">

    <div class="tk-progress" id="tkProgress"></div>
    <div class="tk-backdrop" id="tkBackdrop"></div>

    {{-- ============================ SIDEBAR ============================ --}}
    <aside class="tk-sidebar" id="tkSidebar">
        <div class="tk-brand">
            <div class="tk-brand-logo">SJI</div>
            <div>
                <div class="tk-brand-nama">Portal Karyawan</div>
                <div class="tk-brand-sub">Layanan Mandiri Tenaga Kerja</div>
            </div>
        </div>

        <div class="tk-nav-label">Menu Utama</div>
        <nav class="tk-nav">
            @php
                $menu = [
                    ['route' => 'tenaga-kerja.absensi',   'ikon' => 'bi-fingerprint',        'label' => 'Absensi'],
                    ['route' => 'tenaga-kerja.riwayat',   'ikon' => 'bi-calendar2-week',     'label' => 'Riwayat Absensi'],
                    ['route' => 'tenaga-kerja.slip-gaji', 'ikon' => 'bi-receipt-cutoff',     'label' => 'Slip Gaji'],
                    ['route' => 'tenaga-kerja.profil',    'ikon' => 'bi-person-badge',       'label' => 'Profil Saya'],
                ];
            @endphp
            @foreach ($menu as $m)
                <a href="{{ route($m['route']) }}"
                   class="tk-nav-link {{ request()->routeIs($m['route'].'*') ? 'aktif' : '' }}"
                   @if (request()->routeIs($m['route'].'*')) aria-current="page" @endif>
                    <i class="bi {{ $m['ikon'] }}"></i>
                    <span>{{ $m['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="tk-sidebar-footer">
            <div class="tk-sidebar-user">
                <div class="tk-avatar tk-avatar-sm">{{ $inisial ?? 'K' }}</div>
                <div class="overflow-hidden">
                    <div class="tk-sidebar-user-nama text-truncate">{{ $namaKaryawan ?? 'Karyawan' }}</div>
                    <div class="tk-sidebar-user-sub text-truncate">{{ $jabatan ?? (!empty($kategori) ? 'Karyawan '.$kategori : 'Karyawan') }}</div>
                </div>
            </div>
        </div>
    </aside>

    {{-- ============================ KONTEN ============================ --}}
    <div class="tk-main">
        <header class="tk-topbar">
            <button class="tk-icon-btn d-lg-none" id="tkMenuBtn" type="button" aria-label="Buka menu">
                <i class="bi bi-list"></i>
            </button>

            <div class="tk-topbar-judul">
                <div class="tk-breadcrumb">Tenaga Kerja <i class="bi bi-chevron-right"></i> @yield('title', 'Portal Karyawan')</div>
                <h1 class="tk-page-title">@yield('heading', View::getSection('title'))</h1>
            </div>

            <div class="tk-topbar-kanan">
                <div class="tk-chip d-none d-md-inline-flex">
                    <i class="bi bi-calendar3"></i>
                    <span>{{ now()->locale('id')->translatedFormat('l, d F Y') }}</span>
                </div>
                <a href="{{ route('tenaga-kerja.profil') }}" class="tk-user-chip">
                    <div class="tk-avatar tk-avatar-sm">{{ $inisial ?? 'K' }}</div>
                    <div class="d-none d-sm-block text-start">
                        <div class="tk-user-nama">{{ $namaKaryawan ?? 'Karyawan' }}</div>
                        @if (!empty($kategori))
                            <div class="tk-user-sub">{{ $kategori }}</div>
                        @endif
                    </div>
                </a>
            </div>
        </header>

        @if (!empty($modeUjiCoba))
            <div class="tk-banner-uji">
                <i class="bi bi-cone-striped"></i>
                <span><strong>Mode uji coba:</strong> identitas karyawan diambil dari <code>TK_DEV_ID_KARYAWAN</code> di file .env karena Anda belum login. Nilai ini otomatis diabaikan setelah login.</span>
            </div>
        @endif

        <main class="tk-content">
            @yield('content')
        </main>

        <footer class="tk-footer">
            &copy; {{ date('Y') }} PT SJI · Portal Layanan Mandiri Karyawan
        </footer>
    </div>

    {{-- ============================ NOTIFIKASI ============================ --}}
    <div class="tk-toast-wrap" id="tkToastWrap" aria-live="polite"></div>
    @php
        $tkFlash = [];
        if (session('sukses')) { $tkFlash[] = ['tipe' => 'sukses', 'pesan' => session('sukses')]; }
        if (session('gagal'))  { $tkFlash[] = ['tipe' => 'gagal',  'pesan' => session('gagal')]; }
        foreach ($errors->all() as $pesanError) { $tkFlash[] = ['tipe' => 'gagal', 'pesan' => $pesanError]; }
    @endphp
    <script>window.TK_FLASH = @json($tkFlash);</script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/tenaga-kerja.js') }}?v=1"></script>
    @stack('js')
</body>
</html>

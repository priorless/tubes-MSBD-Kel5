<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Beranda') - SJI</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    @stack('css')
</head>
<body class="hold-transition sidebar-mini layout-fixed">
@php
    /** @var \App\Models\User $penggunaAktif */
    $penggunaAktif = auth()->user();
    $roleAktif = $penggunaAktif->role?->nama_role;
@endphp
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button" aria-label="Buka/tutup menu"><i class="fas fa-bars"></i></a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <span class="nav-link font-weight-bold">PT SJI &middot; {{ $roleAktif }}</span>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" data-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false">
                    <i class="fas fa-user-circle mr-1"></i> {{ $penggunaAktif->namaTampilan() }}
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <span class="dropdown-item-text text-muted small">Login sebagai <strong>{{ $penggunaAktif->username }}</strong></span>
                    <div class="dropdown-divider"></div>
                    <a href="{{ route('password.change') }}" class="dropdown-item">
                        <i class="fas fa-key mr-2"></i> Ganti Password
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="fas fa-sign-out-alt mr-2"></i> Keluar
                        </button>
                    </form>
                </div>
            </li>
        </ul>
    </nav>

    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="{{ $penggunaAktif->homeUrl() }}" class="brand-link">
            <i class="fas fa-building brand-image mt-1 ml-3"></i>
            <span class="brand-text font-weight-bold">SJI Outsourcing</span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    @if ($penggunaAktif->must_change_password)
                        <li class="nav-item">
                            <a href="{{ route('password.change') }}" class="nav-link active">
                                <i class="nav-icon fas fa-key"></i>
                                <p>Ganti Password</p>
                            </a>
                        </li>
                    @else
                        @if ($penggunaAktif->hasRole(\App\Models\Role::Ceo))
                            <li class="nav-header">EKSEKUTIF</li>
                            <li class="nav-item">
                                <a href="{{ route('ceo.dashboard') }}" class="nav-link {{ request()->routeIs('ceo.dashboard') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-tachometer-alt"></i>
                                    <p>Dashboard Eksekutif</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('ceo.profit-klien') }}" class="nav-link {{ request()->routeIs('ceo.profit-klien') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-chart-pie"></i>
                                    <p>Profit Klien</p>
                                </a>
                            </li>
                        @endif

                        @can('viewAny', \App\Models\User::class)
                            <li class="nav-header">ADMINISTRASI</li>
                            <li class="nav-item">
                                <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-users-cog"></i>
                                    <p>Manajemen User</p>
                                </a>
                            </li>
                        @endcan

                        {{-- Menu modul lain (HRD, Operasional, Keuangan) ditambahkan oleh pemilik modul masing-masing. --}}
                        @stack('sidebar')
                    @endif
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
                @include('layouts.partials.flash')

                @yield('content')
            </div>
        </section>
    </div>

    <footer class="main-footer small">
        <strong>&copy; {{ now()->year }} PT SJI.</strong> Sistem Informasi Outsourcing.
    </footer>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/js/adminlte.min.js"></script>
@stack('js')
</body>
</html>

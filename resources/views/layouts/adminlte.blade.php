<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SJI') | SJI</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">
    @stack('styles')
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button" aria-label="Buka atau tutup menu">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <span class="nav-link">@yield('modul', 'SJI')</span>
            </li>
        </ul>
    </nav>

    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="{{ url('/') }}" class="brand-link">
            <span class="brand-text font-weight-light ml-3">SJI Outsourcing</span>
        </a>
        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    @if (Route::has('hrd.dashboard'))
                        <li class="nav-header">HRD</li>
                        <li class="nav-item">
                            <a href="{{ route('hrd.dashboard') }}" class="nav-link {{ request()->routeIs('hrd.dashboard') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-tachometer-alt"></i>
                                <p>Dashboard HRD</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('hrd.karyawan.index') }}" class="nav-link {{ request()->routeIs('hrd.karyawan.*') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-users"></i>
                                <p>Data Karyawan</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('hrd.pool') }}" class="nav-link {{ request()->routeIs('hrd.pool') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-user-check"></i>
                                <p>Pool Karyawan</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('hrd.sertifikat.index') }}" class="nav-link {{ request()->routeIs('hrd.sertifikat.*') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-id-card"></i>
                                <p>Dokumen Sertifikat</p>
                            </a>
                        </li>
                    @endif

                    @if (Route::has('operasional.monitoring'))
                        <li class="nav-header">OPERASIONAL</li>
                        <li class="nav-item">
                            <a href="{{ route('operasional.monitoring') }}" class="nav-link {{ request()->routeIs('operasional.monitoring') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map-marked-alt"></i>
                                <p>Monitoring Sebaran</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('operasional.klien.index') }}" class="nav-link {{ request()->routeIs('operasional.klien.*') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-building"></i>
                                <p>Data Klien</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('operasional.penempatan.create') }}" class="nav-link {{ request()->routeIs('operasional.penempatan.*') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-user-plus"></i>
                                <p>Penempatan</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('operasional.rotasi.create') }}" class="nav-link {{ request()->routeIs('operasional.rotasi.*') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-sync-alt"></i>
                                <p>Rotasi Karyawan</p>
                            </a>
                        </li>
                    @endif

                    @if (Route::has('keuangan.payroll.index'))
                        <li class="nav-header">KEUANGAN</li>
                        <li class="nav-item">
                            <a href="{{ route('keuangan.payroll.index') }}" class="nav-link {{ request()->routeIs('keuangan.payroll.*') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-money-check-alt"></i>
                                <p>Payroll</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('keuangan.invoice.index') }}" class="nav-link {{ request()->routeIs('keuangan.invoice.*') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-file-invoice-dollar"></i>
                                <p>Invoice Klien</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('keuangan.mutasi.index') }}" class="nav-link {{ request()->routeIs('keuangan.mutasi.*') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-exchange-alt"></i>
                                <p>Mutasi Kas</p>
                            </a>
                        </li>
                    @endif
                </ul>
            </nav>
        </div>
    </aside>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-8">
                        <h1>@yield('heading', 'SJI')</h1>
                    </div>
                    <div class="col-sm-4">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item">@yield('modul', 'SJI')</li>
                            <li class="breadcrumb-item active">@yield('breadcrumb', 'Ringkasan')</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                @if (session('success'))
                    <div class="alert alert-success alert-dismissible" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                        {{ session('success') }}
                    </div>
                @endif
                @if (session('error'))
                    <div class="alert alert-danger alert-dismissible" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                        {{ session('error') }}
                    </div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-warning" role="alert">
                        <p class="mb-1">Periksa kembali isian berikut:</p>
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @yield('content')
            </div>
        </section>
    </div>

    <footer class="main-footer">
        <strong>SJI</strong> &mdash; Sistem Informasi Perusahaan Outsourcing.
    </footer>
</div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/js/adminlte.min.js"></script>
@stack('scripts')
</body>
</html>

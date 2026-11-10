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
</body>
</html>

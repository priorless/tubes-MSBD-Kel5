@extends('layouts.tenaga-kerja')

@section('title', 'Absensi')

@use('App\Support\FormatKolom')

@php
    $jamMasuk  = $hariIni && $kolomMasuk  ? FormatKolom::jam($hariIni->{$kolomMasuk})  : null;
    $jamKeluar = $hariIni && $kolomKeluar ? FormatKolom::jam($hariIni->{$kolomKeluar}) : null;
    $statusDb  = $hariIni && $kolomStatus ? ($hariIni->{$kolomStatus} ?? null) : null;
    $label = ['belum' => 'Belum Check-in', 'masuk' => 'Sedang Bekerja', 'selesai' => 'Selesai'];
@endphp

@section('content')
<div class="row g-4">

    {{-- ===================== HERO: sapaan + jam digital ===================== --}}
    <div class="col-12 col-xl-7 tk-reveal">
        <section class="tk-hero">
            <div class="tk-hero-pola"></div>
            <div class="tk-hero-isi">
                <div class="tk-hero-sapa"><span id="tkSapaan">Halo</span>, {{ $namaKaryawan }} <span class="tk-wave">👋</span></div>
                <div class="tk-hero-tanggal" id="tkTanggal">{{ now()->locale('id')->translatedFormat('l, d F Y') }}</div>

                <div class="tk-clock" id="tkClock" data-server="{{ $waktuServer }}" aria-label="Jam saat ini">
                    <span class="tk-digit" data-i="0">0</span><span class="tk-digit" data-i="1">0</span>
                    <span class="tk-clock-sep">:</span>
                    <span class="tk-digit" data-i="2">0</span><span class="tk-digit" data-i="3">0</span>
                    <span class="tk-clock-sep">:</span>
                    <span class="tk-digit tk-digit-kecil" data-i="4">0</span><span class="tk-digit tk-digit-kecil" data-i="5">0</span>
                </div>
                <div class="tk-hero-catatan">
                    <i class="bi bi-broadcast"></i> Waktu disinkronkan dengan server · WIB
                </div>
            </div>
        </section>
    </div>

    {{-- ===================== STATUS ABSENSI HARI INI ===================== --}}
    <div class="col-12 col-xl-5 tk-reveal">
        <section class="tk-card tk-status-card h-100" id="tkStatusCard" data-status="{{ $status }}" data-masuk="{{ $masukIso }}">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="tk-card-title mb-0"><i class="bi bi-activity"></i> Status Hari Ini</h2>
                <span class="tk-badge tk-badge-status" id="tkStatusBadge">
                    <span class="tk-dot"></span><span id="tkStatusLabel">{{ $label[$status] }}</span>
                </span>
            </div>

            {{-- Panel: belum check-in --}}
            <div class="tk-panel" data-panel="belum">
                <div class="tk-panel-ikon tk-ikon-netral"><i class="bi bi-door-open"></i></div>
                <p class="tk-panel-judul">Anda belum check-in hari ini</p>
                <p class="tk-panel-sub">Tekan tombol di bawah saat Anda mulai bekerja.</p>
                <form method="POST" action="{{ route('tenaga-kerja.absensi.check-in') }}" class="tk-form-absen">
                    @csrf
                    <button type="submit" class="tk-btn tk-btn-utama tk-btn-besar w-100">
                        <span class="tk-btn-teks"><i class="bi bi-box-arrow-in-right"></i> Check-in Sekarang</span>
                        <span class="tk-btn-loading"><span class="tk-spinner"></span> Memproses…</span>
                    </button>
                </form>
            </div>

            {{-- Panel: sudah check-in, belum check-out --}}
            <div class="tk-panel" data-panel="masuk">
                <div class="tk-panel-ikon tk-ikon-aktif"><i class="bi bi-briefcase"></i><span class="tk-ping"></span></div>
                <p class="tk-panel-judul">Anda sedang bekerja</p>
                <p class="tk-panel-sub">Durasi sejak check-in</p>
                <div class="tk-durasi" id="tkDurasi">00:00:00</div>
                <form method="POST" action="{{ route('tenaga-kerja.absensi.check-out') }}" class="tk-form-absen" data-konfirmasi="Yakin ingin check-out sekarang?">
                    @csrf
                    <button type="submit" class="tk-btn tk-btn-sekunder tk-btn-besar w-100">
                        <span class="tk-btn-teks"><i class="bi bi-box-arrow-right"></i> Check-out</span>
                        <span class="tk-btn-loading"><span class="tk-spinner"></span> Memproses…</span>
                    </button>
                </form>
            </div>

            {{-- Panel: selesai --}}
            <div class="tk-panel" data-panel="selesai">
                <div class="tk-panel-ikon tk-ikon-sukses">
                    <svg class="tk-centang" viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-15"/></svg>
                </div>
                <p class="tk-panel-judul">Absensi hari ini selesai</p>
                <p class="tk-panel-sub">Terima kasih, sampai jumpa besok!</p>
            </div>

            {{-- Linimasa hari ini --}}
            <ol class="tk-timeline">
                <li class="tk-tl-item" data-tl="masuk">
                    <span class="tk-tl-titik"><i class="bi bi-box-arrow-in-right"></i></span>
                    <span class="tk-tl-label">Check-in</span>
                    <span class="tk-tl-jam" id="tkJamMasuk">{{ $jamMasuk ?? '—' }}</span>
                </li>
                <li class="tk-tl-garis"></li>
                <li class="tk-tl-item" data-tl="keluar">
                    <span class="tk-tl-titik"><i class="bi bi-box-arrow-right"></i></span>
                    <span class="tk-tl-label">Check-out</span>
                    <span class="tk-tl-jam" id="tkJamKeluar">{{ $jamKeluar ?? '—' }}</span>
                </li>
            </ol>
            @if ($statusDb)
                <div class="tk-status-db">Keterangan sistem: <span class="tk-badge tk-badge-{{ FormatKolom::warnaStatus($statusDb) }}">{{ $statusDb }}</span></div>
            @endif
        </section>
    </div>

    {{-- ===================== AKSES CEPAT ===================== --}}
    <div class="col-12">
        <div class="row g-4">
            @foreach ([
                ['route' => 'tenaga-kerja.riwayat',   'ikon' => 'bi-calendar2-week', 'judul' => 'Riwayat Absensi', 'sub' => 'Lihat catatan kehadiran Anda', 'warna' => 'teal'],
                ['route' => 'tenaga-kerja.slip-gaji', 'ikon' => 'bi-receipt-cutoff', 'judul' => 'Slip Gaji',       'sub' => 'Rincian penghasilan per periode', 'warna' => 'petrol'],
                ['route' => 'tenaga-kerja.profil',    'ikon' => 'bi-person-badge',   'judul' => 'Profil Saya',     'sub' => 'Data diri & informasi pekerjaan', 'warna' => 'arang'],
            ] as $q)
                <div class="col-12 col-md-4 tk-reveal">
                    <a href="{{ route($q['route']) }}" class="tk-card tk-quick tk-quick-{{ $q['warna'] }}">
                        <div class="tk-quick-ikon"><i class="bi {{ $q['ikon'] }}"></i></div>
                        <div>
                            <div class="tk-quick-judul">{{ $q['judul'] }}</div>
                            <div class="tk-quick-sub">{{ $q['sub'] }}</div>
                        </div>
                        <i class="bi bi-arrow-right tk-quick-panah"></i>
                    </a>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ===================== PANDUAN ===================== --}}
    <div class="col-12 tk-reveal">
        <section class="tk-card tk-panduan">
            <h2 class="tk-card-title"><i class="bi bi-lightbulb"></i> Panduan Absensi</h2>
            <div class="row g-3">
                <div class="col-md-4"><div class="tk-langkah"><span>1</span> Tekan <b>Check-in</b> saat mulai bekerja.</div></div>
                <div class="col-md-4"><div class="tk-langkah"><span>2</span> Tekan <b>Check-out</b> saat selesai bekerja.</div></div>
                <div class="col-md-4"><div class="tk-langkah"><span>3</span> Absensi hanya dapat dilakukan <b>satu kali</b> per hari.</div></div>
            </div>
        </section>
    </div>
</div>
@endsection

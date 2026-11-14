@extends('layouts.tenaga-kerja')

@section('title', 'Absensi')

@use('App\Support\FormatKolom')

@php
    $jamMasuk  = $hariIni && $kolomMasuk  ? FormatKolom::jam($hariIni->{$kolomMasuk})  : null;
    $jamKeluar = $hariIni && $kolomKeluar ? FormatKolom::jam($hariIni->{$kolomKeluar}) : null;
    $statusDb  = $hariIni && $kolomStatus ? ($hariIni->{$kolomStatus} ?? null) : null;
    $label = ['belum' => 'Belum Check-in', 'masuk' => 'Sedang Bekerja', 'selesai' => 'Selesai'];
    $menitTerlambat = (int) ($hariIni->menit_terlambat ?? 0);
    $adaTitik = $penugasan && filled($penugasan->latitude ?? null) && filled($penugasan->longitude ?? null);
    $modeBergerak = ($penugasan->mode_lokasi ?? 'Tetap') === 'Bergerak';
    $radius = (int) ($penugasan->radius_meter ?? 0);
@endphp

@section('content')
<div class="grid grid-cols-1 gap-6 xl:grid-cols-12">

    {{-- ===================== Sapaan + jam digital (waktu server) ===================== --}}
    <section class="tk-reveal relative overflow-hidden rounded-lg bg-sji-biru-tua p-6 text-white shadow-sm sm:p-8 xl:col-span-7">
        <div class="absolute inset-y-0 left-0 w-1.5 bg-sji-merah"></div>
        <img src="{{ asset('images/logo-sji.png') }}" alt="" class="pointer-events-none absolute -right-6 -bottom-8 w-48 opacity-10">

        <p class="text-lg font-semibold"><span id="tkSapaan">Halo</span>, {{ $namaKaryawan }}</p>
        <p class="mt-1 text-sm text-blue-200" id="tkTanggal">{{ FormatKolom::tanggal($waktuServer, 'l, d F Y') }}</p>

        <div class="mt-6 flex items-end gap-1 font-mono text-5xl font-bold tabular-nums sm:text-6xl" id="tkClock" data-server="{{ $waktuServer }}" aria-label="Jam saat ini">
            <span class="tk-digit" data-i="0">0</span><span class="tk-digit" data-i="1">0</span>
            <span class="text-blue-300">:</span>
            <span class="tk-digit" data-i="2">0</span><span class="tk-digit" data-i="3">0</span>
            <span class="text-blue-300">:</span>
            <span class="tk-digit text-3xl text-blue-200 sm:text-4xl" data-i="4">0</span><span class="tk-digit text-3xl text-blue-200 sm:text-4xl" data-i="5">0</span>
        </div>
        <p class="mt-3 text-xs text-blue-200">Waktu disinkronkan dengan server &middot; WIB</p>
    </section>

    {{-- ===================== Status absensi hari ini ===================== --}}
    <section class="tk-reveal card p-6 xl:col-span-5" id="tkStatusCard" data-status="{{ $status }}" data-masuk="{{ $masukIso }}" data-akurasi-peringatan="{{ $akurasiPeringatan }}">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="card-title">Status Hari Ini</h2>
            <span class="badge" id="tkStatusBadge"><span id="tkStatusLabel">{{ $label[$status] }}</span></span>
        </div>

        {{-- Panel: belum check-in --}}
        <div data-panel="belum" class="text-center">
            <p class="font-semibold text-gray-800">Anda belum check-in hari ini</p>
            <p class="mt-1 text-sm text-gray-500">Tekan tombol di bawah saat Anda mulai bekerja.</p>
            <form method="POST" action="{{ route('tenaga-kerja.absensi.check-in') }}" class="tk-form-absen mt-4">
                @csrf
                <button type="submit" class="tk-btn btn-primary w-full py-3 text-base">
                    <span class="tk-btn-teks">Check-in Sekarang</span>
                    <span class="tk-btn-loading"><span class="tk-spinner"></span> Memproses…</span>
                </button>
            </form>
        </div>

        {{-- Panel: sudah check-in, belum check-out --}}
        <div data-panel="masuk" class="text-center">
            <p class="font-semibold text-gray-800">Anda sedang bekerja</p>
            <p class="mt-1 text-sm text-gray-500">Durasi sejak check-in</p>
            <p class="mt-2 font-mono text-3xl font-bold text-sji-biru tabular-nums" id="tkDurasi">00:00:00</p>
            <form method="POST" action="{{ route('tenaga-kerja.absensi.check-out') }}" class="tk-form-absen mt-4" data-konfirmasi="Yakin ingin check-out sekarang?">
                @csrf
                <button type="submit" class="tk-btn btn-danger w-full py-3 text-base">
                    <span class="tk-btn-teks">Check-out</span>
                    <span class="tk-btn-loading"><span class="tk-spinner"></span> Memproses…</span>
                </button>
            </form>
        </div>

        {{-- Panel: selesai --}}
        <div data-panel="selesai" class="text-center">
            <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-emerald-100 text-2xl text-emerald-600">&#10003;</div>
            <p class="mt-3 font-semibold text-gray-800">Absensi hari ini selesai</p>
            <p class="mt-1 text-sm text-gray-500">Terima kasih, sampai jumpa besok!</p>
        </div>

        {{-- Linimasa hari ini --}}
        <dl class="mt-6 grid grid-cols-2 gap-4 border-t border-gray-100 pt-4 text-center">
            @foreach (['masuk' => ['Check-in', 'tkJamMasuk', $jamMasuk], 'keluar' => ['Check-out', 'tkJamKeluar', $jamKeluar]] as $jenis => [$labelJam, $idJam, $nilaiJam])
                <div>
                    <dt class="text-xs text-gray-500">{{ $labelJam }}</dt>
                    <dd class="mt-1 font-mono text-lg font-semibold text-gray-800" id="{{ $idJam }}">{{ $nilaiJam ?? '—' }}</dd>
                    <dd class="mt-1 text-xs text-gray-500" data-lokasi="{{ $jenis }}" data-lokasi-awal='@json($lokasi[$jenis] ?? null)'></dd>
                </div>
            @endforeach
        </dl>

        <p class="mt-4 rounded-md bg-gray-50 px-3 py-2 text-center text-xs text-gray-500" id="tkInfoLokasi">
            Lokasi (GPS) Anda dicatat saat check-in dan check-out, lalu dicocokkan dengan titik lokasi kerja.
        </p>
        <p @class(['mt-4 text-center text-xs text-gray-500', 'hidden' => ! $statusDb]) id="tkKeteranganStatus">
            Keterangan sistem: <span class="tk-badge-{{ FormatKolom::warnaStatus($statusDb) }}" id="tkStatusDb">{{ $statusDb }}</span>
            @if ($menitTerlambat > 0)
                <span class="ml-1">({{ $menitTerlambat }} menit)</span>
            @endif
        </p>
    </section>

    {{-- ===================== Penugasan, jadwal shift, & aturan lokasi ===================== --}}
    <section class="tk-reveal card p-6 xl:col-span-12">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 class="card-title">Penugasan &amp; Jadwal Hari Ini</h2>
            @if ($penugasan?->nama_layanan)
                <span class="badge">{{ $penugasan->nama_layanan }}</span>
            @endif
        </div>

        @if (! $penugasan)
            <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <p class="font-semibold">Anda belum memiliki penempatan aktif.</p>
                <p class="mt-1">Absensi dapat dilakukan setelah bagian Operasional menempatkan Anda di lokasi klien. Hubungi HRD jika ini keliru.</p>
            </div>
        @else
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-xs text-gray-500">Lokasi Kerja</dt>
                    <dd class="mt-1 font-semibold text-gray-800">{{ $penugasan->nama_klien }}</dd>
                    <dd class="text-gray-600">{{ $penugasan->lokasi_kerja }}</dd>
                    @if ($adaTitik)
                        <dd class="mt-1">
                            <a href="https://www.google.com/maps?q={{ $penugasan->latitude }},{{ $penugasan->longitude }}" target="_blank" rel="noopener"
                               class="font-medium text-sji-biru hover:underline" data-no-transisi>Lihat titik di peta</a>
                        </dd>
                    @endif
                </div>
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-xs text-gray-500">Jadwal Shift</dt>
                    <dd class="mt-1 font-mono text-lg font-semibold text-gray-800">
                        {{ FormatKolom::jam($penugasan->jam_masuk_shift) }}&ndash;{{ FormatKolom::jam($penugasan->jam_keluar_shift) }}
                    </dd>
                    <dd class="text-gray-600">Toleransi terlambat {{ (int) $penugasan->toleransi_terlambat_menit }} menit</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-xs text-gray-500">Aturan Lokasi</dt>
                    <dd class="mt-1 font-semibold text-gray-800">{{ $modeBergerak ? 'Bergerak' : 'Tetap' }}</dd>
                    <dd class="text-gray-600">
                        @if ($modeBergerak)
                            Check-in di titik kumpul/pool klien. Check-out boleh di mana saja, lokasinya tetap dicatat.
                        @else
                            Check-in dan check-out wajib di area lokasi kerja.
                        @endif
                    </dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-xs text-gray-500">Radius Area Kerja</dt>
                    @if ($adaTitik)
                        <dd class="mt-1 font-mono text-lg font-semibold text-gray-800">{{ number_format($radius, 0, ',', '.') }} m</dd>
                        <dd class="text-gray-600">Di luar radius, absensi ditolak sistem.</dd>
                    @else
                        <dd class="mt-1 font-semibold text-gray-800">Belum diatur</dd>
                        <dd class="text-gray-600">Titik lokasi klien belum diisi Operasional. Lokasi Anda tetap dicatat.</dd>
                    @endif
                </div>
            </dl>
        @endif
    </section>

    {{-- ===================== Akses cepat ===================== --}}
    <div class="grid grid-cols-1 gap-6 md:grid-cols-3 xl:col-span-12">
        @foreach ([
            ['route' => 'tenaga-kerja.riwayat',   'judul' => 'Riwayat Absensi', 'sub' => 'Lihat catatan kehadiran Anda'],
            ['route' => 'tenaga-kerja.slip-gaji', 'judul' => 'Slip Gaji',       'sub' => 'Rincian penghasilan per periode'],
            ['route' => 'tenaga-kerja.profil',    'judul' => 'Profil Saya',     'sub' => 'Data diri & informasi pekerjaan'],
        ] as $tautan)
            <a href="{{ route($tautan['route']) }}" class="tk-reveal card group flex items-center justify-between p-5 transition hover:border-sji-biru hover:shadow-md">
                <div>
                    <p class="font-semibold text-gray-800">{{ $tautan['judul'] }}</p>
                    <p class="text-sm text-gray-500">{{ $tautan['sub'] }}</p>
                </div>
                <span class="text-gray-400 transition group-hover:translate-x-1 group-hover:text-sji-biru">&rarr;</span>
            </a>
        @endforeach
    </div>

    {{-- ===================== Panduan ===================== --}}
    <section class="tk-reveal card p-6 xl:col-span-12">
        <h2 class="card-title">Panduan Absensi</h2>
        <ol class="mt-4 grid grid-cols-1 gap-3 text-sm text-gray-600 md:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                'Tekan <b>Check-in</b> saat mulai bekerja.',
                'Tekan <b>Check-out</b> saat selesai bekerja.',
                'Pilih <b>Izinkan</b> saat browser meminta akses lokasi. Absensi hanya diterima di <b>area lokasi kerja</b>.',
                'Absensi <b>satu kali</b> per hari, check-out minimal <b>30 menit</b> setelah check-in. Shift malam boleh check-out keesokan harinya.',
            ] as $nomor => $langkah)
                <li class="flex items-start gap-3 rounded-md bg-gray-50 p-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">{{ $nomor + 1 }}</span>
                    <span>{!! $langkah !!}</span>
                </li>
            @endforeach
        </ol>
    </section>
</div>
@endsection

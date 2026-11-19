@extends('layouts.tenaga-kerja')

@section('title', 'Absensi')

@use('App\Support\FormatKolom')

@php
    $jamMasuk  = $hariIni && $kolomMasuk  ? FormatKolom::jam($hariIni->{$kolomMasuk})  : null;
    $jamKeluar = $hariIni && $kolomKeluar ? FormatKolom::jam($hariIni->{$kolomKeluar}) : null;
    $statusDb  = $hariIni && $kolomStatus ? ($hariIni->{$kolomStatus} ?? null) : null;
    $label = ['belum' => 'Belum Check-in', 'masuk' => 'Sedang Bekerja', 'selesai' => 'Selesai'];
    $menitTerlambat = (int) ($hariIni->menit_terlambat ?? 0);
    $adaJadwal = $penugasan && (filled($penugasan->jadwal_datang ?? null) || filled($penugasan->jadwal_pulang ?? null));
    $jenisMonitoring = $monitoring['jenis'];
    $driver = $jenisMonitoring === \App\Services\LaporanAktivitasService::JENIS_PERJALANAN;
    $maksUpload = \App\Services\LaporanAktivitasService::maksUpload();
    $shiftAktif = $monitoring['shift'];
    $checkInTertutup = $status === 'belum' && $monitoring['pesanJadwal'] !== null;
@endphp

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<style>
    .tk-heading, .card-title { font-family: 'Plus Jakarta Sans', sans-serif; letter-spacing: -0.02em; }
</style>

<div class="space-y-6">

    {{-- ===================== HEADER: Sapaan + Jam Digital Ringkas ===================== --}}
    <header class="tk-reveal flex flex-col justify-between gap-4 border-b border-gray-200 pb-6 sm:flex-row sm:items-end">
        <div>
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-sji-merah"></span>
                <span class="text-xs font-bold uppercase tracking-wider text-sji-biru">Portal Tenaga Kerja</span>
                @if ($layanan)
                    <span class="rounded-full bg-sji-biru/10 px-2.5 py-0.5 text-xs font-semibold text-sji-biru">{{ $layanan }}</span>
                @endif
                @if ($kategori)
                    <span class="rounded-full border border-gray-200 bg-white px-2.5 py-0.5 text-xs text-gray-600">Karyawan {{ $kategori }}</span>
                @endif
            </div>
            <h1 class="tk-heading text-2xl font-extrabold text-gray-900 sm:text-3xl">
                <span id="tkSapaan">Halo</span>, {{ $namaKaryawan }}
            </h1>
            <p class="mt-1 text-sm text-gray-500">
                Catat kehadiran, pantau aktivitas kerja, dan kelola jadwal shift Anda.
            </p>
        </div>

        {{-- Kotak Jam Server Ringkas di Kanan Atas --}}
        <div class="rounded-xl border border-gray-200 bg-white px-5 py-3.5 shadow-sm sm:text-right">
            <p class="text-xs font-medium text-gray-500">Waktu Server &middot; WIB</p>
            <div class="mt-0.5 flex items-baseline gap-0.5 font-mono text-2xl font-bold text-sji-biru-tua tabular-nums sm:justify-end"
                 id="tkClock" data-server="{{ $waktuServer }}" aria-label="Jam saat ini">
                <span class="tk-digit" data-i="0">0</span><span class="tk-digit" data-i="1">0</span>
                <span class="text-sji-biru">:</span>
                <span class="tk-digit" data-i="2">0</span><span class="tk-digit" data-i="3">0</span>
                <span class="text-sji-biru">:</span>
                <span class="tk-digit text-lg text-gray-500" data-i="4">0</span><span class="tk-digit text-lg text-gray-500" data-i="5">0</span>
            </div>
            <p class="mt-0.5 text-xs text-gray-500" id="tkTanggal">{{ FormatKolom::tanggal($waktuServer, 'l, d F Y') }}</p>
        </div>
    </header>

    {{-- ===================== GRID UTAMA: Kiri (Aksi & Monitoring) | Kanan (Jadwal & Ringkasan) ===================== --}}
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">

        {{-- KOLOM KIRI (8 Kolom): Aksi Kehadiran + Monitoring Aktivitas --}}
        <div class="space-y-6 xl:col-span-8">

            {{-- 1. Kartu Aksi Kehadiran --}}
            <section class="tk-reveal card group p-6" id="tkStatusCard" data-status="{{ $status }}" data-masuk="{{ $masukIso }}"
                     @if ($jenisMonitoring && $monitoring['tersedia']) data-muat-ulang="1" @endif
                     @if ($checkInTertutup && $monitoring['muatUlangPada']) data-tk-muat-ulang-pada="{{ $monitoring['muatUlangPada'] }}" @endif>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2.5">
                        <h2 class="card-title text-lg font-bold text-gray-900">Aksi Kehadiran</h2>
                        <span class="text-[11px] text-gray-400 transition-opacity group-hover:opacity-0">&middot; Arahkan kursor untuk aturan</span>
                    </div>
                    <span class="badge" id="tkStatusBadge"><span id="tkStatusLabel">{{ $label[$status] }}</span></span>
                </div>

                {{-- Panel: belum check-in --}}
                <div data-panel="belum">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                        <div>
                            <p class="tk-heading text-base font-bold text-gray-800">Anda belum check-in hari ini</p>
                            @if ($shiftAktif && ! $checkInTertutup)
                                <p class="mt-1 text-sm text-gray-500">
                                    Check-in dibuka sejak <b>{{ $shiftAktif->bukaCheckIn()->format('H:i') }} WIB</b>.
                                </p>
                            @else
                                <p class="mt-1 text-sm text-gray-500">
                                    Unggah foto selfie berlokasi saat Anda mulai bekerja.
                                </p>
                            @endif
                        </div>

                        <form method="POST" action="{{ route('tenaga-kerja.absensi.check-in') }}" class="tk-form-absen shrink-0" data-judul="Foto Diri (Check-in)" id="tkFormCheckIn" enctype="multipart/form-data">
                            @csrf
                            <button type="submit" class="tk-btn btn-primary w-full px-6 py-3 text-sm font-semibold sm:w-auto" @disabled($checkInTertutup)>
                                <span class="tk-btn-teks">Unggah Foto &amp; Check-in</span>
                                <span class="tk-btn-loading"><span class="tk-spinner"></span> Memproses…</span>
                            </button>
                        </form>
                    </div>

                    @if ($checkInTertutup)
                        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" id="tkPesanJadwal">
                            {{ $monitoring['pesanJadwal'] }}
                        </p>
                    @elseif ($driver)
                        <p class="mt-4 rounded-xl bg-blue-50 px-4 py-3 text-sm text-sji-biru">
                            Lengkapi <a href="#p2h" data-no-transisi class="font-semibold underline">P2H kendaraan</a> dan foto kendaraan di bawah sebelum check-in.
                        </p>
                    @endif
                </div>

                {{-- Panel: sudah check-in, belum check-out --}}
                <div data-panel="masuk">
                    <div class="flex flex-col justify-between gap-4 rounded-xl bg-blue-50/80 p-4 sm:flex-row sm:items-center">
                        <div>
                            <p class="tk-heading text-sm font-bold text-sji-biru">Anda sedang bekerja</p>
                            <p class="mt-0.5 text-xs text-gray-500">Kirim laporan aktivitas berkala sebelum melakukan check-out.</p>
                        </div>

                        <form method="POST" action="{{ route('tenaga-kerja.absensi.check-out') }}" class="tk-form-absen shrink-0" data-judul="Foto Diri (Check-out)" data-konfirmasi="Yakin ingin check-out sekarang? Setelah check-out, monitoring aktivitas berhenti." id="tkFormCheckOut" enctype="multipart/form-data">
                            @csrf
                            <button type="submit" class="tk-btn btn-danger w-full px-6 py-3 text-sm font-semibold sm:w-auto">
                                <span class="tk-btn-teks">Unggah Foto &amp; Check-out</span>
                                <span class="tk-btn-loading"><span class="tk-spinner"></span> Memproses…</span>
                            </button>
                        </form>
                    </div>

                    @if ($driver)
                        <p class="mt-3 rounded-xl bg-blue-50 px-4 py-2.5 text-xs text-sji-biru">
                            Isi <a href="#kendaraan-akhir" data-no-transisi class="font-semibold underline">kondisi akhir kendaraan</a> sebelum check-out.
                        </p>
                    @endif
                </div>

                {{-- Panel: selesai --}}
                <div data-panel="selesai">
                    <div class="flex items-center gap-4 rounded-xl bg-emerald-50/70 p-4">
                        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xl font-bold text-emerald-600">&#10003;</div>
                        <div>
                            <p class="tk-heading text-sm font-bold text-gray-800">Absensi hari ini selesai</p>
                            <p class="mt-0.5 text-xs text-gray-500">Terima kasih atas kerja keras Anda hari ini, sampai jumpa besok!</p>
                        </div>
                    </div>
                </div>

                {{-- Keterangan Panjang: Sembunyi secara default, muncul saat hover ke kartu --}}
                <div class="grid grid-rows-[0fr] opacity-0 transition-all duration-300 ease-out group-hover:mt-4 group-hover:grid-rows-[1fr] group-hover:opacity-100">
                    <div class="overflow-hidden">
                        <p class="rounded-lg bg-gray-50 px-3.5 py-2.5 text-xs leading-relaxed text-gray-500" id="tkInfoLokasi">
                            Check-in dibuka {{ (int) config('tenaga_kerja.absensi.buka_check_in_menit', 30) }} menit sebelum shift dimulai dan hanya untuk shift Anda yang dijadwalkan Operasional. Wajib mengunggah foto wajah disertai keterangan tanggal, jam, dan lokasi.
                        </p>
                    </div>
                </div>
            </section>

            {{-- 2. Dokumentasi & Monitoring Aktivitas per Jam / Perjalanan --}}
            @if ($jenisMonitoring && $monitoring['tersedia'])
                @if ($driver && $status === 'belum' && ! $checkInTertutup)
                    @include('tenaga-kerja._p2h-check-in')
                @endif
                @if ($driver && $status === 'masuk')
                    @include('tenaga-kerja._kendaraan-check-out')
                @endif
                @include($driver ? 'tenaga-kerja._monitoring-perjalanan' : 'tenaga-kerja._monitoring-berkala')
            @endif
        </div>

        {{-- KOLOM KANAN / SIDEBAR (4 Kolom): Jadwal + Lokasi & Ringkasan Kehadiran --}}
        <aside class="space-y-6 xl:col-span-4">

            {{-- 1. Kartu Jadwal Kerja + Lokasi Penempatan --}}
            <section class="tk-reveal relative overflow-hidden rounded-xl bg-sji-biru-tua p-6 text-white shadow-sm">
                <div class="absolute inset-y-0 left-0 w-1.5 bg-sji-merah"></div>
                <img src="{{ asset('images/logo-sji.png') }}" alt="" class="pointer-events-none absolute -right-6 -bottom-6 w-36 opacity-10">

                <p class="text-xs font-bold uppercase tracking-wider text-blue-200">Jadwal Kerja Hari Ini</p>

                @if (! $penugasan)
                    <h2 class="tk-heading mt-1.5 text-lg font-bold text-white">Belum Ada Penempatan</h2>
                    <div class="my-4 border-t border-white/15"></div>
                    <p class="text-xs leading-relaxed text-blue-100">
                        Anda belum memiliki penempatan aktif. Hubungi Operasional atau HRD.
                    </p>
                @else
                    @if ($shiftAktif)
                        <h2 class="tk-heading mt-1.5 text-xl font-bold text-white">
                            {{ $shiftAktif->namaShift ?: 'Shift Terjadwal' }}
                        </h2>
                        <p class="mt-1.5 font-mono text-2xl font-bold tracking-tight text-white tabular-nums">
                            {{ $shiftAktif->mulai->format('H:i') }} &ndash; {{ $shiftAktif->selesai->format('H:i') }} WIB
                        </p>
                        <p class="mt-1 text-xs text-blue-200">
                            {{ $shiftAktif->sumber === 'Jadwal Harian' ? 'Jadwal harian' : 'Dari penempatan' }}
                            @if ($shiftAktif->tanggal !== substr($waktuServer, 0, 10))
                                &middot; tanggal {{ \Carbon\CarbonImmutable::parse($shiftAktif->tanggal)->format('d/m/Y') }}
                            @endif
                            &middot; Buka {{ $shiftAktif->bukaCheckIn()->format('H:i') }}
                        </p>
                    @elseif ($adaJadwal)
                        <h2 class="tk-heading mt-1.5 text-xl font-bold text-white">Shift Reguler</h2>
                        <p class="mt-1.5 font-mono text-2xl font-bold tracking-tight text-white tabular-nums">
                            {{ FormatKolom::jam($penugasan->jadwal_datang) }} &ndash; {{ FormatKolom::jam($penugasan->jadwal_pulang) }} WIB
                        </p>
                        <p class="mt-1 text-xs text-blue-200">
                            {{ ($penugasan->sumber_jadwal ?? null) === 'Jadwal Harian' ? 'Jadwal harian' : 'Dari penempatan' }} &middot; toleransi 30 menit
                        </p>
                    @elseif (($penugasan->sumber_jadwal ?? null) === 'Libur')
                        <h2 class="tk-heading mt-1.5 text-xl font-bold text-white">Hari Ini Libur</h2>
                        <p class="mt-1 text-xs text-blue-200">Sesuai jadwal harian Operasional.</p>
                    @else
                        <h2 class="tk-heading mt-1.5 text-xl font-bold text-white">Jadwal Belum Ditentukan</h2>
                        <p class="mt-1 text-xs text-blue-200">Menunggu pengaturan jadwal dari Operasional.</p>
                    @endif

                    {{-- Lokasi Penempatan --}}
                    <div class="my-4 border-t border-white/15"></div>
                    <div class="relative z-10">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-blue-200">Lokasi Penempatan</p>
                        <p class="tk-heading mt-1 text-sm font-bold text-white">{{ $penugasan->nama_klien }}</p>
                        <p class="text-xs text-blue-100">{{ $penugasan->lokasi_kerja }}</p>
                    </div>
                @endif
            </section>

            {{-- 2. Ringkasan Kehadiran Hari Ini (Hover Reveal saat Belum Check-in) --}}
            <section class="tk-reveal card group p-6">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="card-title text-lg font-bold text-gray-900">Ringkasan Kehadiran Hari Ini</h2>
                    @if ($status === 'belum')
                        <span class="text-[11px] text-gray-400 transition-opacity group-hover:opacity-0">Arahkan kursor</span>
                    @endif
                </div>

                @if ($status === 'belum')
                    <div class="mt-3 rounded-xl border border-dashed border-gray-200 bg-gray-50/80 px-4 py-5 text-center transition-all duration-200 group-hover:hidden">
                        <p class="tk-heading text-sm font-bold text-gray-700">Silakan absen terlebih dahulu</p>
                        <p class="mt-1 text-xs text-gray-400">Arahkan kursor untuk melihat detail ringkasan.</p>
                    </div>
                @endif

                <dl @class([
                    'mt-4 grid-cols-2 gap-3',
                    'hidden group-hover:grid' => $status === 'belum',
                    'grid' => $status !== 'belum',
                ])>
                    {{-- Waktu Check-in --}}
                    <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-3.5">
                        <dt class="text-xs text-gray-500">Waktu Check-in</dt>
                        <dd class="mt-1.5 font-mono text-lg font-bold text-gray-900 tabular-nums" id="tkJamMasuk">{{ $jamMasuk ?? '—' }}</dd>
                        <dd class="mt-2">
                            <a href="{{ $foto['masuk'] ?? '#' }}" target="_blank" rel="noopener" data-no-transisi data-foto="masuk"
                               @class(['inline-block' => ! empty($foto['masuk']), 'hidden' => empty($foto['masuk'])]) title="Buka foto check-in">
                                <img src="{{ $foto['masuk'] ?? '' }}" alt="Foto check-in" class="h-12 w-20 rounded-md border border-gray-200 object-cover">
                            </a>
                            @if (empty($foto['masuk']))
                                <span class="text-[11px] text-gray-400">Belum ada foto</span>
                            @endif
                        </dd>
                    </div>

                    {{-- Keterangan Masuk --}}
                    <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-3.5">
                        <dt class="text-xs text-gray-500">Keterangan Masuk</dt>
                        <dd class="mt-1.5 text-sm font-bold text-gray-800" id="tkKeteranganStatus">
                            @if ($statusDb)
                                <span class="tk-badge-{{ FormatKolom::warnaStatus($statusDb) }}" id="tkStatusDb">{{ $statusDb }}</span>
                                @if ($menitTerlambat > 0)
                                    <span class="mt-1 block text-xs font-normal text-amber-700">Terlambat {{ $menitTerlambat }} menit</span>
                                @endif
                            @else
                                <span class="text-gray-400" id="tkStatusDb">—</span>
                            @endif
                        </dd>
                    </div>

                    {{-- Waktu Check-out --}}
                    <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-3.5">
                        <dt class="text-xs text-gray-500">Waktu Check-out</dt>
                        <dd class="mt-1.5 font-mono text-lg font-bold text-gray-900 tabular-nums" id="tkJamKeluar">{{ $jamKeluar ?? '—' }}</dd>
                        <dd class="mt-2">
                            <a href="{{ $foto['keluar'] ?? '#' }}" target="_blank" rel="noopener" data-no-transisi data-foto="keluar"
                               @class(['inline-block' => ! empty($foto['keluar']), 'hidden' => empty($foto['keluar'])]) title="Buka foto check-out">
                                <img src="{{ $foto['keluar'] ?? '' }}" alt="Foto check-out" class="h-12 w-20 rounded-md border border-gray-200 object-cover">
                            </a>
                            @if (empty($foto['keluar']))
                                <span class="text-[11px] text-gray-400">Belum ada foto</span>
                            @endif
                        </dd>
                    </div>

                    {{-- Durasi Kerja --}}
                    <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-3.5">
                        <dt class="text-xs text-gray-500">Durasi Kerja</dt>
                        @if ($status === 'masuk')
                            <dd class="mt-1.5 font-mono text-lg font-bold text-sji-biru tabular-nums" id="tkDurasi">00:00:00</dd>
                        @elseif ($status === 'selesai')
                            <dd class="mt-1.5 text-sm font-bold text-emerald-700">Shift Selesai</dd>
                        @else
                            <dd class="mt-1.5 font-mono text-lg font-bold text-gray-400 tabular-nums" id="tkDurasi">—</dd>
                        @endif
                    </div>
                </dl>
            </section>

        </aside>
    </div>

    {{-- ===================== Dialog unggah foto (check-in / check-out) ===================== --}}
    <dialog id="tkDialogFoto" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-xl p-0 shadow-xl backdrop:bg-black/40"
            data-ukuran-maks-kb="{{ (int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360) }}">
        <div class="p-6">
            <h3 class="tk-heading text-base font-bold text-gray-800" id="tkFotoJudul">Foto Absensi</h3>

            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                <p class="font-semibold">Pastikan foto yang diunggah:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-4">
                    <li>Menampilkan wajah Anda dengan jelas.</li>
                    <li>Memuat keterangan <b>tanggal</b>, <b>jam</b>, dan <b>lokasi</b> (misalnya dari aplikasi kamera bertanda waktu).</li>
                </ul>
                <p class="mt-1">Keterangan tersebut dibuat dari aplikasi kamera Anda, bukan oleh sistem. Foto akan diperiksa HRD.</p>
            </div>

            <label for="tkFotoBerkas" class="form-label mt-4">Pilih foto</label>
            <input type="file" id="tkFotoBerkas" class="form-input" accept="image/jpeg,image/png,image/webp">
            <div class="mt-3 hidden overflow-hidden rounded-md bg-gray-900" id="tkFotoPratinjauWadah">
                <img id="tkFotoPratinjau" alt="Pratinjau foto absensi" class="max-h-80 w-full object-contain">
            </div>
            <p class="mt-2 text-xs text-gray-500" id="tkFotoStatus" role="status">
                JPG, PNG, atau WEBP, maksimal {{ intdiv((int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360), 1024) }} MB.
            </p>

            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" class="btn-secondary" data-foto-aksi="batal">Batal</button>
                <button type="button" class="btn-primary" data-foto-aksi="kirim" disabled>Kirim Absensi</button>
            </div>
        </div>
    </dialog>

    {{-- ===================== Tukar shift: dipindah ke halaman tersendiri ===================== --}}
    <a href="{{ route('tenaga-kerja.tukar-shift.index') }}" class="tk-reveal card group flex items-center justify-between gap-4 p-5 transition hover:border-sji-biru hover:shadow-md">
        <div>
            <p class="tk-heading flex items-center gap-2 font-bold text-gray-800">
                Tukar Shift dengan Rekan
                @if ($tukarShiftMenungguTanggapan > 0)
                    <span class="badge badge-warning">{{ $tukarShiftMenungguTanggapan }} menunggu tanggapan Anda</span>
                @endif
            </p>
            <p class="text-sm text-gray-500">Ajukan, tanggapi, atau batalkan pertukaran shift dengan rekan satu layanan di lokasi yang sama</p>
        </div>
        <span class="text-gray-400 transition group-hover:translate-x-1 group-hover:text-sji-biru">&rarr;</span>
    </a>

    {{-- ===================== Akses cepat ===================== --}}
    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
        @foreach ([
            ['route' => 'tenaga-kerja.riwayat',   'judul' => 'Riwayat Absensi', 'sub' => 'Lihat catatan kehadiran Anda'],
            ['route' => 'tenaga-kerja.slip-gaji', 'judul' => 'Slip Gaji',       'sub' => 'Rincian penghasilan per periode'],
            ['route' => 'tenaga-kerja.profil',    'judul' => 'Profil Saya',     'sub' => 'Data diri & informasi pekerjaan'],
        ] as $tautan)
            <a href="{{ route($tautan['route']) }}" class="tk-reveal card group flex items-center justify-between p-5 transition hover:border-sji-biru hover:shadow-md">
                <div>
                    <p class="tk-heading font-bold text-gray-800">{{ $tautan['judul'] }}</p>
                    <p class="text-sm text-gray-500">{{ $tautan['sub'] }}</p>
                </div>
                <span class="text-gray-400 transition group-hover:translate-x-1 group-hover:text-sji-biru">&rarr;</span>
            </a>
        @endforeach
    </div>

    {{-- ===================== Panduan (Ringkas secara default, detail langkah muncul saat hover) ===================== --}}
    <section class="tk-reveal card group p-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="card-title text-lg font-bold text-gray-900">Panduan Absensi</h2>
            <span class="text-xs text-gray-400 transition-opacity group-hover:opacity-0">Arahkan kursor untuk membaca 4 langkah panduan</span>
        </div>

        <div class="grid grid-rows-[0fr] opacity-0 transition-all duration-300 ease-out group-hover:mt-4 group-hover:grid-rows-[1fr] group-hover:opacity-100">
            <div class="overflow-hidden">
                <ol class="grid grid-cols-1 gap-3 text-sm text-gray-600 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        'Check-in hanya untuk <b>shift Anda</b> yang dijadwalkan Operasional, dibuka <b>30 menit sebelum shift</b> dimulai. Unggah foto diri dari galeri atau kamera ponsel.',
                        'Selama bekerja kirim <b>laporan aktivitas</b>: Satpam &amp; Cleaning Service setiap jam, Driver setiap tahapan perjalanan. Tiap laporan maksimal 2 kali unggah.',
                        'Foto harus menampilkan <b>wajah dengan jelas</b> dan memuat keterangan <b>tanggal, jam, dan lokasi</b> dari aplikasi kamera Anda.',
                        'Absensi <b>satu kali</b> per shift, check-out minimal <b>30 menit</b> setelah check-in. Shift malam boleh check-out keesokan harinya.',
                    ] as $nomor => $langkah)
                        <li class="flex items-start gap-3 rounded-xl bg-gray-50 p-3.5">
                            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">{{ $nomor + 1 }}</span>
                            <span>{!! $langkah !!}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </section>

</div>
@endsection
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
@endphp

@section('content')
<div class="grid grid-cols-1 gap-6 xl:grid-cols-12">

    {{-- ===================== Sapaan + jam digital (waktu server) ===================== --}}
    <section class="tk-reveal relative overflow-hidden rounded-lg bg-sji-biru-tua p-6 text-white shadow-sm sm:p-8 xl:col-span-7">
        <div class="absolute inset-y-0 left-0 w-1.5 bg-sji-merah"></div>
        <img src="{{ asset('images/logo-sji.png') }}" alt="" class="pointer-events-none absolute -right-6 -bottom-8 w-48 opacity-10">

        <p class="text-lg font-semibold"><span id="tkSapaan">Halo</span>, {{ $namaKaryawan }}</p>
        @if ($layanan || $kategori)
            <p class="mt-2 flex flex-wrap gap-2 text-xs">
                @if ($layanan)
                    <span class="rounded-full bg-white/15 px-3 py-0.5 font-semibold text-white">{{ $layanan }}</span>
                @endif
                @if ($kategori)
                    <span class="rounded-full border border-white/30 px-3 py-0.5 text-blue-100">Karyawan {{ $kategori }}</span>
                @endif
            </p>
        @endif
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
    <section class="tk-reveal card p-6 xl:col-span-5" id="tkStatusCard" data-status="{{ $status }}" data-masuk="{{ $masukIso }}">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="card-title">Status Hari Ini</h2>
            <span class="badge" id="tkStatusBadge"><span id="tkStatusLabel">{{ $label[$status] }}</span></span>
        </div>

        {{-- Panel: belum check-in --}}
        <div data-panel="belum" class="text-center">
            <p class="font-semibold text-gray-800">Anda belum check-in hari ini</p>
            <p class="mt-1 text-sm text-gray-500">Tekan tombol di bawah lalu unggah foto saat Anda mulai bekerja.</p>
            <form method="POST" action="{{ route('tenaga-kerja.absensi.check-in') }}" class="tk-form-absen mt-4" data-judul="Foto Check-in">
                @csrf
                <button type="submit" class="tk-btn btn-primary w-full py-3 text-base">
                    <span class="tk-btn-teks">Unggah Foto &amp; Check-in</span>
                    <span class="tk-btn-loading"><span class="tk-spinner"></span> Memproses…</span>
                </button>
            </form>
        </div>

        {{-- Panel: sudah check-in, belum check-out --}}
        <div data-panel="masuk" class="text-center">
            <p class="font-semibold text-gray-800">Anda sedang bekerja</p>
            <p class="mt-1 text-sm text-gray-500">Durasi sejak check-in</p>
            <p class="mt-2 font-mono text-3xl font-bold text-sji-biru tabular-nums" id="tkDurasi">00:00:00</p>
            <form method="POST" action="{{ route('tenaga-kerja.absensi.check-out') }}" class="tk-form-absen mt-4" data-judul="Foto Check-out" data-konfirmasi="Yakin ingin check-out sekarang?">
                @csrf
                <button type="submit" class="tk-btn btn-danger w-full py-3 text-base">
                    <span class="tk-btn-teks">Unggah Foto &amp; Check-out</span>
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
                    <dd class="mt-2 flex justify-center">
                        <a href="{{ $foto[$jenis] ?? '#' }}" target="_blank" rel="noopener" data-no-transisi data-foto="{{ $jenis }}"
                           @class(['block', 'hidden' => empty($foto[$jenis])]) title="Buka foto {{ strtolower($labelJam) }}">
                            <img src="{{ $foto[$jenis] ?? '' }}" alt="Foto {{ strtolower($labelJam) }}" class="h-16 w-24 rounded-md border border-gray-200 object-cover">
                        </a>
                    </dd>
                </div>
            @endforeach
        </dl>

        <p class="mt-4 rounded-md bg-gray-50 px-3 py-2 text-center text-xs text-gray-500" id="tkInfoLokasi">
            Check-in dan check-out wajib mengunggah foto yang menampilkan wajah Anda serta keterangan tanggal, jam, dan lokasi.
        </p>
        <p @class(['mt-4 text-center text-xs text-gray-500', 'hidden' => ! $statusDb]) id="tkKeteranganStatus">
            Keterangan sistem: <span class="tk-badge-{{ FormatKolom::warnaStatus($statusDb) }}" id="tkStatusDb">{{ $statusDb }}</span>
            @if ($menitTerlambat > 0)
                <span class="ml-1">({{ $menitTerlambat }} menit)</span>
            @endif
        </p>
    </section>

    {{-- ===================== Dialog unggah foto (check-in / check-out) ===================== --}}
    <dialog id="tkDialogFoto" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-lg p-0 shadow-xl backdrop:bg-black/40"
            data-ukuran-maks-kb="{{ (int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360) }}">
        <div class="p-6">
            <h3 class="text-base font-semibold text-gray-800" id="tkFotoJudul">Foto Absensi</h3>

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

    {{-- ===================== Penugasan & jadwal kerja ===================== --}}
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
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-xs text-gray-500">Lokasi Penempatan</dt>
                    <dd class="mt-1 font-semibold text-gray-800">{{ $penugasan->nama_klien }}</dd>
                    <dd class="text-gray-600">{{ $penugasan->lokasi_kerja }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-xs text-gray-500">Jadwal Kerja</dt>
                    @if ($adaJadwal)
                        <dd class="mt-1 font-mono text-lg font-semibold text-gray-800">
                            {{ FormatKolom::jam($penugasan->jadwal_datang) }}&ndash;{{ FormatKolom::jam($penugasan->jadwal_pulang) }}
                        </dd>
                        <dd class="text-gray-600">Dari penempatan &middot; toleransi terlambat 30 menit</dd>
                    @else
                        <dd class="mt-1 font-semibold text-gray-800">Jadwal belum ditentukan</dd>
                        <dd class="text-gray-600">Keterlambatan dan indikasi lembur tidak dihitung sampai jadwal penempatan diisi Operasional.</dd>
                    @endif
                </div>
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-xs text-gray-500">Foto Absensi</dt>
                    <dd class="mt-1 font-semibold text-gray-800">Wajib saat check-in &amp; check-out</dd>
                    <dd class="text-gray-600">Wajah terlihat jelas, dengan keterangan tanggal, jam, dan lokasi dari aplikasi kamera Anda.</dd>
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
                'Tekan <b>Check-in</b> saat mulai bekerja, lalu unggah foto diri dari galeri atau kamera ponsel.',
                'Tekan <b>Check-out</b> saat selesai bekerja dan unggah foto kembali.',
                'Foto harus menampilkan <b>wajah dengan jelas</b> dan memuat keterangan <b>tanggal, jam, dan lokasi</b> dari aplikasi kamera Anda.',
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

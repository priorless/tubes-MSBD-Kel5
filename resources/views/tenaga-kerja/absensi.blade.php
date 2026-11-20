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
    <section class="tk-reveal card p-6 xl:col-span-5" id="tkStatusCard" data-status="{{ $status }}" data-masuk="{{ $masukIso }}"
             @if ($jenisMonitoring && $monitoring['tersedia']) data-muat-ulang="1" @endif
             @if ($checkInTertutup && $monitoring['muatUlangPada']) data-tk-muat-ulang-pada="{{ $monitoring['muatUlangPada'] }}" @endif>
        <div class="mb-4 flex items-center justify-between">
            <h2 class="card-title">Status Hari Ini</h2>
            <span class="badge" id="tkStatusBadge"><span id="tkStatusLabel">{{ $label[$status] }}</span></span>
        </div>

        {{-- Panel: belum check-in --}}
        <div data-panel="belum" class="text-center">
            <p class="font-semibold text-gray-800">Anda belum check-in hari ini</p>
            @if ($shiftAktif && ! $checkInTertutup)
                <p class="mt-1 text-sm text-gray-500">Shift <b>{{ $shiftAktif->label() }}</b> &middot; check-in dibuka sejak {{ $shiftAktif->bukaCheckIn()->format('H:i') }}.</p>
            @else
                <p class="mt-1 text-sm text-gray-500">Tekan tombol di bawah lalu unggah foto saat Anda mulai bekerja.</p>
            @endif
            @if ($checkInTertutup)
                <p class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-left text-sm text-amber-800" id="tkPesanJadwal">{{ $monitoring['pesanJadwal'] }}</p>
            @elseif ($driver)
                <p class="mt-3 rounded-md bg-blue-50 px-3 py-2 text-left text-sm text-sji-biru">Lengkapi <a href="#p2h" data-no-transisi class="font-semibold underline">P2H kendaraan</a> dan foto kendaraan di bawah sebelum check-in.</p>
            @endif
            <form method="POST" action="{{ route('tenaga-kerja.absensi.check-in') }}" class="tk-form-absen mt-4" data-judul="Foto Diri (Check-in)" id="tkFormCheckIn" enctype="multipart/form-data">
                @csrf
                <button type="submit" class="tk-btn btn-primary w-full py-3 text-base" @disabled($checkInTertutup)>
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
            @if ($driver)
                <p class="mt-3 rounded-md bg-blue-50 px-3 py-2 text-left text-sm text-sji-biru">Isi <a href="#kendaraan-akhir" data-no-transisi class="font-semibold underline">kondisi akhir kendaraan</a> sebelum check-out.</p>
            @endif
            <form method="POST" action="{{ route('tenaga-kerja.absensi.check-out') }}" class="tk-form-absen mt-4" data-judul="Foto Diri (Check-out)" data-konfirmasi="Yakin ingin check-out sekarang? Setelah check-out, monitoring aktivitas berhenti." id="tkFormCheckOut" enctype="multipart/form-data">
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
            Check-in dibuka {{ (int) config('tenaga_kerja.absensi.buka_check_in_menit', 30) }} menit sebelum shift dimulai dan hanya untuk shift Anda yang dijadwalkan Operasional.
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
                    @if ($shiftAktif)
                        <dd class="mt-1 font-mono text-lg font-semibold text-gray-800">{{ $shiftAktif->mulai->format('H:i') }}&ndash;{{ $shiftAktif->selesai->format('H:i') }}</dd>
                        <dd class="text-gray-600">
                            {{ $shiftAktif->namaShift ? $shiftAktif->namaShift.' · ' : '' }}{{ $shiftAktif->sumber === 'Jadwal Harian' ? 'Jadwal harian' : 'Dari penempatan' }}
                            @if ($shiftAktif->tanggal !== substr($waktuServer, 0, 10))
                                &middot; shift tanggal {{ \Carbon\CarbonImmutable::parse($shiftAktif->tanggal)->format('d/m/Y') }}
                            @endif
                        </dd>
                        <dd class="text-gray-600">Check-in dibuka {{ $shiftAktif->bukaCheckIn()->format('H:i') }} &middot; toleransi terlambat 30 menit</dd>
                    @elseif ($adaJadwal)
                        <dd class="mt-1 font-mono text-lg font-semibold text-gray-800">
                            {{ FormatKolom::jam($penugasan->jadwal_datang) }}&ndash;{{ FormatKolom::jam($penugasan->jadwal_pulang) }}
                        </dd>
                        <dd class="text-gray-600">{{ ($penugasan->sumber_jadwal ?? null) === 'Jadwal Harian' ? 'Jadwal harian' : 'Dari penempatan' }} &middot; toleransi terlambat 30 menit</dd>
                    @elseif (($penugasan->sumber_jadwal ?? null) === 'Libur')
                        <dd class="mt-1 font-semibold text-gray-800">Hari ini Libur</dd>
                        <dd class="text-gray-600">Sesuai jadwal harian dari Operasional. Absensi tidak dapat dilakukan pada hari libur.</dd>
                    @else
                        <dd class="mt-1 font-semibold text-gray-800">Jadwal belum ditentukan</dd>
                        <dd class="text-gray-600">Absensi dapat dilakukan setelah Operasional mengatur jadwal shift Anda.</dd>
                    @endif
                </div>
                <div class="rounded-md bg-gray-50 p-4">
                    <dt class="text-xs text-gray-500">Foto Absensi</dt>
                    <dd class="mt-1 font-semibold text-gray-800">Wajib saat check-in &amp; check-out</dd>
                    <dd class="text-gray-600">Wajah terlihat jelas, dengan keterangan tanggal, jam, dan lokasi dari aplikasi kamera Anda.</dd>
                    @if ($jenisMonitoring === \App\Services\LaporanAktivitasService::JENIS_BERKALA)
                        <dd class="mt-1 text-gray-600">Ditambah laporan foto aktivitas setiap jam selama bekerja.</dd>
                    @elseif ($driver)
                        <dd class="mt-1 text-gray-600">Ditambah P2H &amp; foto kendaraan saat check-in/check-out serta laporan per tahapan perjalanan.</dd>
                    @endif
                </div>
            </dl>
        @endif
    </section>

    {{-- ===================== Dokumentasi sesuai jenis layanan ===================== --}}
    @if ($jenisMonitoring && $monitoring['tersedia'])
        @if ($driver && $status === 'belum' && ! $checkInTertutup)
            @include('tenaga-kerja._p2h-check-in')
        @endif
        @if ($driver && $status === 'masuk')
            @include('tenaga-kerja._kendaraan-check-out')
        @endif
        @include($driver ? 'tenaga-kerja._monitoring-perjalanan' : 'tenaga-kerja._monitoring-berkala')
    @endif

    {{-- ===================== Tukar shift dengan rekan ===================== --}}
    @if ($tukarShift['tersedia'])
        @php
            $layananTukar = $tukarShift['layanan'];
            $idSaya = \App\Support\KaryawanAktif::id();
            $hariId = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
            $labelTanggal = static fn (string $tgl): string => $hariId[\Carbon\CarbonImmutable::parse($tgl)->dayOfWeek].', '.\Carbon\CarbonImmutable::parse($tgl)->format('d/m/Y');
        @endphp
        <section class="tk-reveal card p-6 xl:col-span-12" id="tukar-shift">
            <div class="mb-4">
                <h2 class="card-title">Tukar Shift dengan Rekan</h2>
                <p class="mt-1 text-sm text-gray-500">Tukar salah satu jadwal Anda dengan jadwal rekan di lokasi yang sama; tanggal dan jam shift ikut bertukar. Ajukan paling lambat H-1; rekan menerima atau menolak, lalu Operasional memberi persetujuan akhir. Jadwal baru (beserta hak absensinya) berlaku hanya setelah disetujui Operasional.</p>
            </div>

            @if ($tukarShift['opsi'] === [])
                <x-ui.empty-state title="Belum ada jadwal yang dapat ditukar."
                    message="Tukar shift tersedia untuk jadwal mulai besok yang sudah dijadwalkan Operasional dan memiliki rekan di lokasi yang sama yang jadwalnya dapat ditukar." />
            @else
                <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.store') }}" id="formTukarShift" class="grid grid-cols-1 gap-4 lg:grid-cols-3"
                      onsubmit="return confirm('Kirim pengajuan tukar shift? Pastikan rekan Anda sudah setuju.')">
                    @csrf
                    <div class="rounded-md bg-gray-50 p-4">
                        <label for="id_jadwal_pengaju" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">1</span> Pilih jadwal Anda</label>
                        <select id="id_jadwal_pengaju" name="id_jadwal_pengaju" class="form-input" required>
                            <option value="">Pilih jadwal</option>
                            @foreach ($tukarShift['opsi'] as $opsi)
                                <option value="{{ $opsi['id_jadwal'] }}" @selected((string) old('id_jadwal_pengaju') === (string) $opsi['id_jadwal'])>
                                    {{ $labelTanggal($opsi['tanggal']) }} &mdash; {{ $opsi['shift'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="rounded-md bg-gray-50 p-4">
                        <label for="id_jadwal_rekan" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">2</span> Pilih jadwal rekan</label>
                        <select id="id_jadwal_rekan" name="id_jadwal_rekan" class="form-input" required disabled>
                            <option value="">Pilih jadwal Anda terlebih dahulu</option>
                        </select>
                        <p class="form-hint" id="ringkasanTukar">Hanya jadwal rekan yang dapat ditukar dengan jadwal Anda yang ditampilkan.</p>
                    </div>

                    <div class="rounded-md bg-gray-50 p-4">
                        <label for="alasan" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">3</span> Alasan</label>
                        <textarea id="alasan" name="alasan" rows="2" minlength="10" maxlength="500" required class="form-input"
                                  placeholder="Contoh: ada keperluan keluarga pada pagi hari">{{ old('alasan') }}</textarea>
                        <label class="mt-3 flex items-start gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="konfirmasi_rekan" value="1" class="form-check mt-0.5" required @checked(old('konfirmasi_rekan'))>
                            <span>Rekan yang saya pilih sudah setuju untuk bertukar shift.</span>
                        </label>
                    </div>

                    <div class="flex justify-end lg:col-span-3">
                        <button type="submit" class="btn-primary">Ajukan Tukar Shift</button>
                    </div>
                </form>

                @push('scripts')
                    <script>
                        (() => {
                            const opsi = @js($tukarShift['opsi']);
                            const rekanLama = @js((string) old('id_jadwal_rekan', ''));
                            const pilihJadwal = document.getElementById('id_jadwal_pengaju');
                            const pilihRekan = document.getElementById('id_jadwal_rekan');
                            const ringkasan = document.getElementById('ringkasanTukar');
                            const ringkasanAwal = ringkasan.textContent;
                            const hari = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
                            const labelTanggal = (tanggal) => {
                                const [tahun, bulan, tgl] = tanggal.split('-').map(Number);
                                return `${hari[new Date(tahun, bulan - 1, tgl).getDay()]}, ${String(tgl).padStart(2, '0')}/${String(bulan).padStart(2, '0')}/${tahun}`;
                            };
                            const jadwalTerpilih = () => opsi.find((item) => String(item.id_jadwal) === pilihJadwal.value);

                            const tampilkanRingkasan = () => {
                                const saya = jadwalTerpilih();
                                const rekan = saya?.rekan.find((item) => String(item.id_jadwal) === pilihRekan.value);
                                ringkasan.textContent = saya && rekan
                                    ? `Anda: ${labelTanggal(saya.tanggal)} ${saya.shift} → ${labelTanggal(rekan.tanggal)} ${rekan.shift}. `
                                        + `${rekan.nama}: ${labelTanggal(rekan.tanggal)} ${rekan.shift} → ${labelTanggal(saya.tanggal)} ${saya.shift}.`
                                    : ringkasanAwal;
                            };
                            const isiRekan = (pilihanAwal = '') => {
                                const saya = jadwalTerpilih();
                                pilihRekan.replaceChildren(new Option(saya ? 'Pilih jadwal rekan' : 'Pilih jadwal Anda terlebih dahulu', ''));
                                pilihRekan.disabled = !saya;
                                if (saya) {
                                    saya.rekan.forEach((rekan) => {
                                        const pilihan = new Option(`${rekan.nama} (${rekan.nip}) — ${labelTanggal(rekan.tanggal)} · ${rekan.shift}`, rekan.id_jadwal);
                                        pilihan.selected = String(rekan.id_jadwal) === pilihanAwal;
                                        pilihRekan.add(pilihan);
                                    });
                                }
                                tampilkanRingkasan();
                            };

                            pilihJadwal.addEventListener('change', () => isiRekan());
                            pilihRekan.addEventListener('change', tampilkanRingkasan);
                            isiRekan(rekanLama);
                        })();
                    </script>
                @endpush
            @endif

            @if ($tukarShift['riwayat']->isNotEmpty())
                <h3 class="mb-2 mt-6 text-sm font-semibold text-gray-700">Status Pengajuan Tukar Shift</h3>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Bertukar Dengan</th>
                                <th>Jadwal Anda</th>
                                <th>Menjadi</th>
                                <th>Status</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tukarShift['riwayat'] as $item)
                                @php
                                    $sayaPengaju = (int) $item->id_karyawan_pengaju === $idSaya;
                                    $tanggalPengaju = (string) $item->tanggal_kerja;
                                    $tanggalRekan = $layananTukar->tanggalKerjaRekan($item);
                                @endphp
                                <tr>
                                    <td>
                                        {{ $sayaPengaju ? $item->nama_rekan : $item->nama_pengaju }}
                                        <p class="text-xs text-gray-400">{{ $sayaPengaju ? 'Anda yang mengajukan' : 'Diajukan oleh rekan' }}</p>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        {{ $labelTanggal($sayaPengaju ? $tanggalPengaju : $tanggalRekan) }}
                                        <p class="text-xs text-gray-500">{{ $layananTukar->labelShift($sayaPengaju ? $item->snapshot_pengaju : $item->snapshot_rekan) }}</p>
                                    </td>
                                    <td class="whitespace-nowrap font-medium text-gray-800">
                                        {{ $labelTanggal($sayaPengaju ? $tanggalRekan : $tanggalPengaju) }}
                                        <p class="text-xs font-normal text-gray-500">{{ $layananTukar->labelShift($sayaPengaju ? $item->snapshot_rekan : $item->snapshot_pengaju) }}</p>
                                    </td>
                                    <td>
                                        <span class="badge {{ \App\Services\PertukaranShiftService::kelasBadge($item->status) }}">{{ \App\Services\PertukaranShiftService::labelTahap($item) }}</span>
                                        @if (! $sayaPengaju && $item->status === \App\Services\PertukaranShiftService::STATUS_MENUNGGU && ($item->status_rekan ?? null) === \App\Services\PertukaranShiftService::REKAN_MENUNGGU)
                                            <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.tanggapi', $item->id) }}" class="mt-2 space-y-2">
                                                @csrf
                                                @method('PATCH')
                                                <input type="text" name="catatan_rekan" maxlength="500" class="form-input text-sm" placeholder="Catatan (opsional)" aria-label="Catatan tanggapan">
                                                <div class="flex gap-2">
                                                    <button type="submit" name="tanggapan" value="terima" class="btn-primary px-3 py-1.5 text-xs"
                                                            onclick="return confirm('Terima pertukaran shift ini? Pengajuan akan diteruskan ke Operasional.')">Terima</button>
                                                    <button type="submit" name="tanggapan" value="tolak" class="btn-danger px-3 py-1.5 text-xs"
                                                            onclick="return confirm('Tolak pertukaran shift ini?')">Tolak</button>
                                                </div>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="text-sm text-gray-600">{{ $item->catatan_keputusan ?? ($item->catatan_rekan ?? '-') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

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
                'Check-in hanya untuk <b>shift Anda</b> yang dijadwalkan Operasional, dibuka <b>30 menit sebelum shift</b> dimulai. Unggah foto diri dari galeri atau kamera ponsel.',
                'Selama bekerja kirim <b>laporan aktivitas</b>: Satpam &amp; Cleaning Service setiap jam, Driver setiap tahapan perjalanan. Tiap laporan maksimal 2 kali unggah.',
                'Foto harus menampilkan <b>wajah dengan jelas</b> dan memuat keterangan <b>tanggal, jam, dan lokasi</b> dari aplikasi kamera Anda.',
                'Absensi <b>satu kali</b> per shift, check-out minimal <b>30 menit</b> setelah check-in. Shift malam boleh check-out keesokan harinya.',
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

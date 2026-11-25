@extends('layouts.app')

@section('title', 'Absensi Karyawan')

@use('App\Support\FormatKolom')

@php
    $warnaKehadiran = ['Hadir' => 'success', 'Terlambat' => 'warning', 'Izin' => 'info', 'Sakit' => 'info', 'Alpha' => 'danger'];
@endphp

@section('content')
    <x-ui.page-header title="Absensi Karyawan" description="Pantau absensi harian karyawan lapangan dan rekap per periode." />

    <nav class="mb-6 flex gap-2 border-b border-gray-200" aria-label="Bagian absensi karyawan">
        <a href="{{ route('hrd.absensi', ['tab' => 'harian']) }}" @class(['border-b-2 px-4 py-3 text-sm font-medium', 'border-sji-biru text-sji-biru' => $tab === 'harian', 'border-transparent text-gray-500 hover:text-gray-700' => $tab !== 'harian']) @if ($tab === 'harian') aria-current="page" @endif>Absensi Harian</a>
        <a href="{{ route('hrd.absensi', ['tab' => 'rekap']) }}" @class(['border-b-2 px-4 py-3 text-sm font-medium', 'border-sji-biru text-sji-biru' => $tab === 'rekap', 'border-transparent text-gray-500 hover:text-gray-700' => $tab !== 'rekap']) @if ($tab === 'rekap') aria-current="page" @endif>Rekap Absensi</a>
    </nav>

    @if ($tab === 'rekap')
        @include('hrd.absensi.rekap')
    @else
    @if ($ringkasan)
        <div class="mb-6 grid grid-cols-2 gap-6 xl:grid-cols-4">
            @foreach ([
                ['label' => 'Sudah Absen', 'nilai' => $ringkasan->total, 'warna' => 'text-sji-biru', 'tanda' => null],
                ['label' => 'Terlambat', 'nilai' => $ringkasan->terlambat, 'warna' => 'text-amber-600', 'tanda' => 'terlambat'],
                ['label' => 'Belum Check-out', 'nilai' => $ringkasan->belum_check_out, 'warna' => 'text-gray-700', 'tanda' => null],
                ['label' => 'Ditempatkan, Belum Absen', 'nilai' => $belumAbsen->count(), 'warna' => 'text-red-500', 'tanda' => null],
            ] as $kartu)
                <div class="stat-card flex flex-col">
                    <h3 class="stat-label">{{ $kartu['label'] }}</h3>
                    <p class="stat-value {{ $kartu['warna'] }}">{{ (int) $kartu['nilai'] }}</p>
                    @if ($kartu['tanda'])
                        <a href="{{ route('hrd.absensi', ['tanggal' => $tanggal, 'tanda' => $kartu['tanda']]) }}" class="mt-3 text-sm font-medium text-sji-biru hover:underline">Tinjau &rarr;</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Absensi karyawan lapangan &mdash; {{ FormatKolom::tanggal($tanggal, 'l, d F Y') }}</h2>
        </div>

        <form method="GET" action="{{ route('hrd.absensi') }}" class="grid grid-cols-1 gap-3 border-b border-gray-200 bg-gray-50 px-6 py-4 md:grid-cols-12">
            <input type="date" name="tanggal" value="{{ $tanggal }}" class="form-input md:col-span-2" aria-label="Tanggal absensi">
            <input type="search" name="q" value="{{ request('q') }}" class="form-input md:col-span-4" placeholder="Cari nama atau NIP" aria-label="Cari nama atau NIP">
            <select name="layanan" class="form-input md:col-span-2" aria-label="Filter jenis layanan">
                <option value="">Semua layanan</option>
                @foreach ($daftarLayanan as $layanan)
                    <option value="{{ $layanan }}" @selected(request('layanan') === $layanan)>{{ $layanan }}</option>
                @endforeach
            </select>
            <select name="tanda" class="form-input md:col-span-2" aria-label="Filter tanda">
                <option value="">Semua catatan</option>
                @foreach ($daftarTanda as $nilai => $label)
                    <option value="{{ $nilai }}" @selected(request('tanda') === $nilai)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary md:col-span-2">Terapkan</button>
        </form>

        @if ($absensi === null)
            <p class="table-empty">View v_absensi_harian belum tersedia. Jalankan <code>php artisan migrate</code>.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Karyawan</th>
                            <th>Lokasi Penempatan</th>
                            <th>Jadwal</th>
                            <th>Masuk</th>
                            <th>Keluar</th>
                            <th>Kehadiran</th>
                            <th>Foto Check-in</th>
                            <th>Foto Check-out</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($absensi as $baris)
                            <tr>
                                <td>
                                    <span class="font-medium text-gray-800">{{ $baris->nama }}</span>
                                    <span class="block text-xs text-gray-500">{{ $baris->nip }} &middot; {{ $baris->nama_layanan ?? 'Internal' }}</span>
                                </td>
                                <td>
                                    {{ $baris->nama_klien ?? '—' }}
                                    @if ($baris->lokasi_kerja)
                                        <span class="block text-xs text-gray-500">{{ $baris->lokasi_kerja }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($baris->jadwal_datang || $baris->jadwal_pulang)
                                        <span class="font-mono">{{ FormatKolom::jam($baris->jadwal_datang) }}&ndash;{{ FormatKolom::jam($baris->jadwal_pulang) }}</span>
                                    @elseif (($baris->sumber_jadwal ?? null) === 'Libur')
                                        <span class="badge badge-warning" title="Masuk pada hari yang dijadwalkan Libur">Libur</span>
                                    @else
                                        <span class="badge badge-secondary">Jadwal belum ditentukan</span>
                                    @endif
                                </td>
                                <td class="font-mono">{{ FormatKolom::jam($baris->jam_masuk) }}</td>
                                <td class="font-mono">
                                    @if ($baris->lupa_check_out)
                                        <span class="badge badge-danger">Lupa check-out</span>
                                    @else
                                        {{ FormatKolom::jam($baris->jam_keluar) }}
                                    @endif
                                    @if ((int) $baris->jam_lembur > 0)
                                        <span class="block font-sans text-xs text-gray-500" title="Bekerja melewati jadwal pulang penempatan">Lembur {{ (int) $baris->jam_lembur }} jam</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-{{ $warnaKehadiran[$baris->status_kehadiran] ?? 'secondary' }}">{{ $baris->status_kehadiran }}</span>
                                    @if ((int) $baris->menit_terlambat > 0)
                                        <span class="block text-xs text-gray-500">{{ (int) $baris->menit_terlambat }} menit</span>
                                    @endif
                                </td>
                                @foreach (['masuk' => 'check-in', 'keluar' => 'check-out'] as $jenis => $labelFoto)
                                    <td>
                                        @if ($baris->{'foto_'.$jenis})
                                            @php $urlFoto = route('absensi.foto', ['absensi' => $baris->id_absensi, 'jenis' => $jenis]); @endphp
                                            <a href="{{ $urlFoto }}" target="_blank" rel="noopener" title="Buka foto {{ $labelFoto }}">
                                                <img src="{{ $urlFoto }}" alt="Foto {{ $labelFoto }} {{ $baris->nama }}" loading="lazy"
                                                    class="h-12 w-16 rounded border border-gray-200 object-cover">
                                            </a>
                                        @else
                                            <span class="text-gray-400">&mdash;</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="table-empty">Tidak ada absensi yang cocok pada tanggal ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card-footer">
                <span>Menampilkan {{ $absensi->firstItem() ?? 0 }}&ndash;{{ $absensi->lastItem() ?? 0 }} dari {{ $absensi->total() }} absensi</span>
                @include('partials.pagination', ['paginator' => $absensi])
            </div>
        @endif
    </div>

    @if ($belumAbsen->isNotEmpty())
        <div class="card mt-6">
            <div class="card-header">
                <h2 class="card-title">Ditempatkan tetapi belum absen</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>NIP</th>
                            <th>Nama</th>
                            <th>Layanan</th>
                            <th>Klien</th>
                            <th>Lokasi Kerja</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($belumAbsen as $item)
                            <tr>
                                <td class="font-mono text-gray-600">{{ $item->nip }}</td>
                                <td class="font-medium text-gray-800">{{ $item->nama }}</td>
                                <td>{{ $item->nama_layanan ?? '—' }}</td>
                                <td>{{ $item->nama_perusahaan }}</td>
                                <td>{{ $item->lokasi_kerja }}</td>
                                <td class="text-right">
                                    <a href="{{ route('hrd.absensi', ['tanggal' => $tanggal, 'karyawan' => $item->id]) }}#catat-ketidakhadiran" class="btn-secondary btn-sm">Catat</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Input ketidakhadiran (sp_input_ketidakhadiran) --}}
    <form method="POST" action="{{ route('hrd.absensi.ketidakhadiran') }}" class="card mt-6" id="catat-ketidakhadiran">
        @csrf
        <div class="card-header">
            <div>
                <h2 class="card-title">Catat Ketidakhadiran</h2>
                <p class="text-sm text-gray-500">Untuk karyawan yang Izin, Sakit, atau Alpha (tidak check-in). Tanggal tidak boleh di masa depan dan belum ada absensi pada tanggal itu.</p>
            </div>
        </div>
        <div class="card-body grid grid-cols-1 gap-4 md:grid-cols-12">
            <div class="md:col-span-4">
                <label for="id_karyawan" class="form-label">Karyawan</label>
                <select id="id_karyawan" name="id_karyawan" required @class(['form-input', 'form-input-error' => $errors->has('id_karyawan')])>
                    <option value="">&mdash; Pilih karyawan &mdash;</option>
                    @foreach ($daftarKaryawan as $karyawan)
                        <option value="{{ $karyawan->id }}" @selected((string) old('id_karyawan', request('karyawan')) === (string) $karyawan->id)>
                            {{ $karyawan->nama }} ({{ $karyawan->nip }}, {{ $karyawan->kategori }})
                        </option>
                    @endforeach
                </select>
                @error('id_karyawan') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="md:col-span-2">
                <label for="tgl_ketidakhadiran" class="form-label">Tanggal</label>
                <input id="tgl_ketidakhadiran" name="tgl" type="date" required value="{{ old('tgl', $tanggal) }}"
                    @class(['form-input', 'form-input-error' => $errors->has('tgl')])>
                @error('tgl') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="md:col-span-2">
                <label for="status_kehadiran" class="form-label">Status</label>
                <select id="status_kehadiran" name="status_kehadiran" required @class(['form-input', 'form-input-error' => $errors->has('status_kehadiran')])>
                    @foreach (\App\Http\Requests\Hrd\StoreKetidakhadiranRequest::STATUS as $status)
                        <option value="{{ $status }}" @selected(old('status_kehadiran', 'Izin') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
                @error('status_kehadiran') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="md:col-span-4">
                <label for="keterangan" class="form-label">Keterangan</label>
                <input id="keterangan" name="keterangan" type="text" maxlength="255" value="{{ old('keterangan') }}"
                    placeholder="mis. surat dokter, keperluan keluarga"
                    @class(['form-input', 'form-input-error' => $errors->has('keterangan')])>
                @error('keterangan') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="card-footer justify-start">
            <button type="submit" class="btn-primary">Simpan Ketidakhadiran</button>
        </div>
    </form>

    <div class="callout callout-info mt-6">
        Lokasi resmi dan jadwal diambil dari data <b>penempatan</b> (modul Operasional) yang berlaku pada tanggal absensi.
        Terlambat bila check-in lebih dari 30 menit setelah jam datang; indikasi lembur dihitung dari waktu kerja setelah jam pulang
        dan langsung dihitung di payroll. Bila jadwal penempatan kosong, keduanya tidak dihitung.
        Foto check-in/check-out diunggah karyawan dan seharusnya menampilkan wajah serta keterangan tanggal, jam, dan lokasi dari aplikasi kamera karyawan — periksa kesesuaiannya; sistem tidak membuat keterangan tersebut. Jam absensi resmi tetap jam server.
    </div>
    @endif
@endsection

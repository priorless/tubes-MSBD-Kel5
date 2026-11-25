@extends('layouts.tenaga-kerja')

@section('title', 'Riwayat Absensi')

@use('App\Support\FormatKolom')

@php
    // Kartu ringkasan: total dari kolom rekap view (SUM tampilan, dihitung di database).
    $kartuRekap = [
        'rekap_hadir'       => ['label' => 'Hari Hadir',       'warna' => 'text-emerald-600'],
        'rekap_terlambat'   => ['label' => 'Hari Terlambat',   'warna' => 'text-amber-600'],
        'rekap_tidak_hadir' => ['label' => 'Hari Tidak Hadir', 'warna' => 'text-red-500'],
        'rekap_lembur'      => ['label' => 'Indikasi Lembur (Jam)', 'warna' => 'text-sji-biru'],
    ];
    $adaFilter = $tahun || $bulan;
    $labelPeriode = trim(($bulan ? FormatKolom::namaBulan($bulan) : 'Semua bulan').' '.($tahun ?: ''));
@endphp

@section('content')
<x-ui.page-header title="Riwayat Absensi" description="Rekap kehadiran dan catatan check-in / check-out Anda per periode." />

{{-- ===================== Ringkasan ===================== --}}
<div class="mb-6 grid grid-cols-2 gap-6 lg:grid-cols-4">
    @foreach ($kartuRekap as $kunci => $kartu)
        @continue(! isset($rekap[$kunci]))
        <div class="tk-reveal stat-card">
            <h3 class="stat-label">{{ $kartu['label'] }}</h3>
            <p class="stat-value {{ $kartu['warna'] }}" data-hitung="{{ (int) $rekap[$kunci]['jumlah'] }}">0</p>
        </div>
    @endforeach
</div>

{{-- ===================== Hari wajib (roster) vs kehadiran ===================== --}}
@if ($kehadiran && $periodeHarian)
    <section class="tk-reveal card mb-6 p-6">
        <h2 class="card-title text-lg font-bold text-gray-900">
            Hari Wajib Kerja vs Kehadiran
            <span class="font-normal text-gray-400">({{ FormatKolom::namaBulan($periodeHarian['bulan']) }} {{ $periodeHarian['tahun'] }})</span>
        </h2>
        <p class="mt-1 text-sm text-gray-500">
            Hari wajib dihitung dari roster Operasional (jadwal Kerja dan backup shift). Hari wajib yang sudah lewat tanpa absensi
            dihitung <b>Alpha (tanpa keterangan)</b>. Toleransi terlambat 30 menit: telat 1–30 menit tetap Hadir; mulai 31 menit
            tercatat Terlambat dengan seluruh menit telatnya.
        </p>
        <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-5">
            @foreach ([
                ['Hari Wajib', $kehadiran['hari_wajib'], 'text-sji-biru'],
                ['Hadir', $kehadiran['hadir'], 'text-emerald-600'],
                ['Izin / Sakit', $kehadiran['izin'] + $kehadiran['sakit'], 'text-sky-600'],
                ['Alpha (Tanpa Keterangan)', $kehadiran['alpha'], 'text-red-500'],
                ['Belum Berjalan', $kehadiran['belum_berjalan'], 'text-gray-500'],
            ] as [$label, $nilai, $warnaAngka])
                <div class="rounded-xl bg-gray-50 p-4">
                    <p class="text-xs font-medium text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold {{ $warnaAngka }}">{{ $nilai }}</p>
                </div>
            @endforeach
        </div>
        @php $tanggalAlpha = collect($kehadiran['harian'])->where('keterangan_hadir', 'Alpha (tanpa keterangan)')->pluck('tanggal'); @endphp
        @if ($tanggalAlpha->isNotEmpty())
            <p class="mt-3 text-sm text-red-600">
                Tanpa absensi pada: {{ $tanggalAlpha->map(fn ($t) => FormatKolom::tanggal($t, 'd M'))->implode(', ') }}.
            </p>
        @endif
        @if ($kehadiran['hadir_luar_roster'] > 0)
            <p class="mt-1 text-sm text-gray-500">{{ $kehadiran['hadir_luar_roster'] }} hari hadir di luar roster (jadwal penempatan).</p>
        @endif
    </section>
@endif

{{-- ===================== Lupa check-out ===================== --}}
@if ($lupaCheckOut->isNotEmpty() || $riwayatKoreksi->isNotEmpty())
    <section class="tk-reveal card mb-6 p-6" id="koreksi-check-out">
        <h2 class="card-title text-lg font-bold text-gray-900">Perbaikan Lupa Check-out</h2>
        <p class="mt-1 text-sm text-gray-500">Ajukan jam keluar yang sebenarnya untuk absensi yang lupa di-check-out. Setelah disetujui HRD, jam kerja dan lembur dihitung ulang otomatis.</p>

        @foreach ($lupaCheckOut as $absensi)
            <div class="mt-4 rounded-xl border border-gray-200 p-4">
                <p class="font-semibold text-gray-800">{{ FormatKolom::tanggal($absensi->tgl, 'D, d M Y') }} &mdash; check-in {{ FormatKolom::jam($absensi->jam_masuk) }}</p>
                @if ($absensi->id_koreksi)
                    <p class="mt-1 text-sm text-amber-700">Pengajuan jam keluar {{ FormatKolom::jam($absensi->jam_keluar_diajukan) }} sedang menunggu pemeriksaan HRD.</p>
                @else
                    <form method="POST" action="{{ route('tenaga-kerja.koreksi-check-out.store') }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-12"
                          onsubmit="return confirm('Kirim pengajuan koreksi check-out ke HRD?')">
                        @csrf
                        <input type="hidden" name="id_absensi" value="{{ $absensi->id }}">
                        <div class="md:col-span-3">
                            <label for="jam_keluar_{{ $absensi->id }}" class="form-label">Jam keluar</label>
                            <input type="time" id="jam_keluar_{{ $absensi->id }}" name="jam_keluar" required class="form-input">
                        </div>
                        <div class="md:col-span-7">
                            <label for="alasan_{{ $absensi->id }}" class="form-label">Alasan</label>
                            <input id="alasan_{{ $absensi->id }}" name="alasan" minlength="10" maxlength="500" required class="form-input"
                                   placeholder="Contoh: baterai ponsel habis saat jam pulang">
                        </div>
                        <div class="flex items-end md:col-span-2">
                            <button type="submit" class="btn-primary w-full">Ajukan</button>
                        </div>
                    </form>
                @endif
            </div>
        @endforeach

        @if ($riwayatKoreksi->isNotEmpty())
            <div class="mt-5 overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Tanggal Absensi</th><th>Jam Keluar</th><th>Status</th><th>Catatan HRD</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($riwayatKoreksi as $item)
                            <tr>
                                <td class="whitespace-nowrap">{{ FormatKolom::tanggal($item->tgl, 'd M Y') }}</td>
                                <td class="font-mono">{{ FormatKolom::jam($item->jam_keluar) }}</td>
                                <td><span class="tk-badge-{{ $item->status === 'Disetujui' ? 'sukses' : ($item->status === 'Ditolak' ? 'bahaya' : 'peringatan') }}">{{ $item->status }}</span></td>
                                <td class="text-sm text-gray-600">{{ $item->catatan_keputusan ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif

{{-- ===================== Filter per periode ===================== --}}
<section class="tk-reveal card mb-6 p-6">
    <form method="GET" action="{{ route('tenaga-kerja.riwayat') }}" class="grid grid-cols-2 items-end gap-4 md:grid-cols-12" id="tkFormFilter">
        <div class="md:col-span-3">
            <label for="bulan" class="form-label">Bulan</label>
            <select id="bulan" name="bulan" class="form-input">
                <option value="">Semua bulan</option>
                @for ($b = 1; $b <= 12; $b++)
                    <option value="{{ $b }}" @selected($bulan === $b)>{{ FormatKolom::namaBulan($b) }}</option>
                @endfor
            </select>
        </div>
        <div class="md:col-span-3">
            <label for="tahun" class="form-label">Tahun</label>
            <input type="number" id="tahun" name="tahun" value="{{ $tahun }}" min="2000" max="2100" step="1" placeholder="Semua tahun" class="form-input">
        </div>
        <div class="col-span-2 flex flex-wrap gap-2 md:col-span-6">
            <button type="submit" class="tk-btn btn-primary">Terapkan</button>
            @if ($adaFilter)
                <a href="{{ route('tenaga-kerja.riwayat') }}" class="btn-secondary">Reset</a>
            @endif
            @if ($periodeHarian)
                <a href="{{ route('tenaga-kerja.riwayat.unduh', $periodeHarian) }}"
                   class="btn-secondary md:ml-auto" data-no-transisi>
                    Unduh Rekap PDF ({{ FormatKolom::namaBulan($periodeHarian['bulan']) }} {{ $periodeHarian['tahun'] }})
                </a>
            @endif
        </div>
    </form>
</section>

{{-- ===================== Tabel (satu baris = satu bulan) ===================== --}}
<section class="tk-reveal card">
    <div class="card-header">
        <h2 class="card-title">
            Rekap Kehadiran Bulanan
            @if ($adaFilter)
                <span class="font-normal text-gray-400">({{ $labelPeriode }})</span>
            @endif
        </h2>
        <input type="search" id="tkCari" class="form-input w-full sm:w-64" placeholder="Cari di halaman ini…" autocomplete="off" aria-label="Cari di tabel">
    </div>

    @if ($riwayat && $riwayat->count())
        <div class="overflow-x-auto">
            <table class="table" id="tkTabel">
                <thead>
                    <tr>
                        <th>No</th>
                        @foreach ($kolom as $k)
                            <th>{{ FormatKolom::label($k) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($riwayat as $i => $baris)
                        <tr>
                            <td class="text-gray-400">{{ $riwayat->firstItem() + $i }}</td>
                            @foreach ($kolom as $k)
                                <td>
                                    @if ($k === $kolomStatus && filled($baris->{$k}))
                                        <span class="tk-badge-{{ FormatKolom::warnaStatus($baris->{$k}) }}">{{ $baris->{$k} }}</span>
                                    @else
                                        {{ FormatKolom::nilai($k, $baris->{$k}) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="table-empty hidden" id="tkKosongCari">Tidak ada baris yang cocok dengan pencarian.</p>

        <div class="card-footer">
            <span>Menampilkan {{ $riwayat->firstItem() }}&ndash;{{ $riwayat->lastItem() }} dari {{ $riwayat->total() }} bulan</span>
            @include('partials.pagination', ['paginator' => $riwayat])
        </div>
    @else
        <div class="px-6 py-12 text-center">
            <p class="font-semibold text-gray-800">Belum ada catatan absensi</p>
            <p class="mt-1 text-sm text-gray-500">
                @if ($adaFilter) Tidak ada data pada periode {{ $labelPeriode }}. Coba ubah filter. @else Riwayat akan muncul setelah Anda melakukan absensi. @endif
            </p>
            <a href="{{ route('tenaga-kerja.absensi') }}" class="btn-primary mt-4">Ke Halaman Absensi</a>
        </div>
    @endif
</section>

{{-- ===================== Detail harian (v_absensi_harian) ===================== --}}
@if ($periodeHarian)
    <section class="tk-reveal card mt-6">
        <div class="card-header">
            <h2 class="card-title">
                Detail Harian
                <span class="font-normal text-gray-400">({{ FormatKolom::namaBulan($periodeHarian['bulan']) }} {{ $periodeHarian['tahun'] }})</span>
            </h2>
        </div>

        @if ($harian->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Lokasi Kerja</th>
                            <th>Jadwal</th>
                            <th>Masuk</th>
                            <th>Keluar</th>
                            <th>Status</th>
                            <th>Jam Kerja</th>
                            <th title="Bekerja melewati jadwal pulang penempatan; belum melalui persetujuan lembur">Indikasi Lembur</th>
                            <th>Foto Check-in</th>
                            <th>Foto Check-out</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($harian as $hari)
                            <tr>
                                <td class="whitespace-nowrap">{{ FormatKolom::tanggal($hari->tgl, 'D, d M Y') }}</td>
                                <td>
                                    {{ $hari->nama_klien ?? '—' }}
                                    @if ($hari->lokasi_kerja)
                                        <span class="block text-xs text-gray-500">{{ $hari->lokasi_kerja }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($hari->jadwal_datang || $hari->jadwal_pulang)
                                        <span class="font-mono">{{ FormatKolom::jam($hari->jadwal_datang) }}&ndash;{{ FormatKolom::jam($hari->jadwal_pulang) }}</span>
                                    @elseif (($hari->sumber_jadwal ?? null) === 'Libur')
                                        <span class="tk-badge-netral">Libur</span>
                                    @else
                                        <span class="text-xs text-gray-500">Jadwal belum ditentukan</span>
                                    @endif
                                </td>
                                <td class="font-mono">{{ FormatKolom::jam($hari->jam_masuk) }}</td>
                                <td class="font-mono">
                                    @if ($hari->lupa_check_out)
                                        <span class="tk-badge-bahaya">Lupa check-out</span>
                                    @else
                                        {{ FormatKolom::jam($hari->jam_keluar) }}
                                    @endif
                                </td>
                                <td>
                                    <span class="tk-badge-{{ FormatKolom::warnaStatus($hari->status_kehadiran) }}">{{ $hari->status_kehadiran }}</span>
                                    @if ((int) $hari->menit_terlambat > 0)
                                        <span class="block text-xs text-gray-500">{{ (int) $hari->menit_terlambat }} menit</span>
                                    @endif
                                </td>
                                <td>{{ $hari->jam_kerja !== null && $hari->jam_keluar !== null ? $hari->jam_kerja.' jam' : '—' }}</td>
                                <td>{{ (int) $hari->jam_lembur > 0 ? $hari->jam_lembur.' jam' : '—' }}</td>
                                @foreach (['masuk' => 'check-in', 'keluar' => 'check-out'] as $jenis => $labelFoto)
                                    <td>
                                        @if ($hari->{'foto_'.$jenis})
                                            @php $urlFoto = route('absensi.foto', ['absensi' => $hari->id_absensi, 'jenis' => $jenis]); @endphp
                                            <a href="{{ $urlFoto }}" target="_blank" rel="noopener" data-no-transisi title="Buka foto {{ $labelFoto }}">
                                                <img src="{{ $urlFoto }}" alt="Foto {{ $labelFoto }}" loading="lazy" class="h-12 w-16 rounded border border-gray-200 object-cover">
                                            </a>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="table-empty">Belum ada absensi pada bulan ini.</p>
        @endif
    </section>
@endif
@endsection

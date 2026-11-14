@extends('layouts.tenaga-kerja')

@section('title', 'Riwayat Absensi')

@use('App\Support\FormatKolom')

@php
    // Kartu ringkasan: total dari kolom rekap view (SUM tampilan, dihitung di database).
    $kartuRekap = [
        'rekap_hadir'       => ['label' => 'Hari Hadir',       'warna' => 'text-emerald-600'],
        'rekap_terlambat'   => ['label' => 'Hari Terlambat',   'warna' => 'text-amber-600'],
        'rekap_tidak_hadir' => ['label' => 'Hari Tidak Hadir', 'warna' => 'text-red-500'],
        'rekap_lembur'      => ['label' => 'Total Jam Lembur', 'warna' => 'text-sji-biru'],
    ];
    $adaFilter = $tahun || $bulan;
    $labelPeriode = trim(($bulan ? FormatKolom::namaBulan($bulan) : 'Semua bulan').' '.($tahun ?: ''));
@endphp

@section('content')
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
            <a href="{{ route('tenaga-kerja.riwayat.unduh', array_filter(['tahun' => $tahun, 'bulan' => $bulan])) }}"
               class="btn-secondary md:ml-auto" data-no-transisi>
                Unduh CSV
            </a>
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
@endsection

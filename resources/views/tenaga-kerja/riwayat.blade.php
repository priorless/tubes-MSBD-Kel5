@extends('layouts.tenaga-kerja')

@section('title', 'Riwayat Absensi')

@use('App\Support\FormatKolom')

@php
    // Kartu ringkasan: total dari kolom rekap view (SUM tampilan, dihitung di database).
    $kartuRekap = [
        'rekap_hadir'       => ['label' => 'Hari Hadir',       'warna' => 'sukses',     'ikon' => 'bi-check2-circle'],
        'rekap_terlambat'   => ['label' => 'Hari Terlambat',   'warna' => 'peringatan', 'ikon' => 'bi-alarm'],
        'rekap_tidak_hadir' => ['label' => 'Hari Tidak Hadir', 'warna' => 'bahaya',     'ikon' => 'bi-x-circle'],
        'rekap_lembur'      => ['label' => 'Total Jam Lembur', 'warna' => 'teal',       'ikon' => 'bi-hourglass-split'],
    ];
    $adaFilter = $tahun || $bulan;
    $labelPeriode = trim(($bulan ? FormatKolom::namaBulan($bulan) : 'Semua bulan').' '.($tahun ?: ''));
@endphp

@section('content')
{{-- ===================== RINGKASAN ===================== --}}
<div class="row g-4 mb-4">
    @foreach ($kartuRekap as $kunci => $kartu)
        @continue(! isset($rekap[$kunci]))
        <div class="col-6 col-lg-3 tk-reveal">
            <div class="tk-card tk-stat">
                <div class="tk-stat-ikon tk-bg-{{ $kartu['warna'] }}"><i class="bi {{ $kartu['ikon'] }}"></i></div>
                <div class="tk-stat-angka" data-hitung="{{ (int) $rekap[$kunci]['jumlah'] }}">0</div>
                <div class="tk-stat-label">{{ $kartu['label'] }}</div>
            </div>
        </div>
    @endforeach
</div>

{{-- ===================== FILTER (per periode, sesuai view rekap bulanan) ===================== --}}
<section class="tk-card mb-4 tk-reveal">
    <form method="GET" action="{{ route('tenaga-kerja.riwayat') }}" class="row g-3 align-items-end" id="tkFormFilter">
        <div class="col-6 col-md-3">
            <label for="bulan" class="tk-label">Bulan</label>
            <select id="bulan" name="bulan" class="form-select tk-input">
                <option value="">Semua bulan</option>
                @for ($b = 1; $b <= 12; $b++)
                    <option value="{{ $b }}" @selected($bulan === $b)>{{ FormatKolom::namaBulan($b) }}</option>
                @endfor
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="tahun" class="tk-label">Tahun</label>
            <input type="number" id="tahun" name="tahun" value="{{ $tahun }}" min="2000" max="2100" step="1"
                   placeholder="Semua tahun" class="form-control tk-input">
        </div>
        <div class="col-12 col-md-6 d-flex gap-2 flex-wrap">
            <button type="submit" class="tk-btn tk-btn-utama"><i class="bi bi-funnel"></i> Terapkan</button>
            @if ($adaFilter)
                <a href="{{ route('tenaga-kerja.riwayat') }}" class="tk-btn tk-btn-garis"><i class="bi bi-x-lg"></i> Reset</a>
            @endif
            <a href="{{ route('tenaga-kerja.riwayat.unduh', array_filter(['tahun' => $tahun, 'bulan' => $bulan])) }}"
               class="tk-btn tk-btn-garis ms-md-auto" data-no-transisi>
                <i class="bi bi-download"></i> Unduh CSV
            </a>
        </div>
    </form>
</section>

{{-- ===================== TABEL (satu baris = satu bulan) ===================== --}}
<section class="tk-card tk-reveal">
    <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-3">
        <h2 class="tk-card-title mb-0"><i class="bi bi-table"></i> Rekap Kehadiran Bulanan
            @if ($adaFilter)
                <small class="tk-muted fw-normal">({{ $labelPeriode }})</small>
            @endif
        </h2>
        <div class="tk-search">
            <i class="bi bi-search"></i>
            <input type="search" id="tkCari" class="form-control tk-input" placeholder="Cari di halaman ini…" autocomplete="off">
        </div>
    </div>

    @if ($riwayat && $riwayat->count())
        <div class="table-responsive">
            <table class="tk-table" id="tkTabel">
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
                        <tr style="--d: {{ $loop->index }}">
                            <td class="tk-muted">{{ $riwayat->firstItem() + $i }}</td>
                            @foreach ($kolom as $k)
                                <td>
                                    @if ($k === $kolomStatus && filled($baris->{$k}))
                                        <span class="tk-badge tk-badge-{{ FormatKolom::warnaStatus($baris->{$k}) }}">{{ $baris->{$k} }}</span>
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
        <div class="tk-kosong tk-kosong-cari d-none" id="tkKosongCari">
            <i class="bi bi-search"></i>
            <p>Tidak ada baris yang cocok dengan pencarian.</p>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
            <div class="tk-muted small">Menampilkan {{ $riwayat->firstItem() }}–{{ $riwayat->lastItem() }} dari {{ $riwayat->total() }} bulan</div>
            {{ $riwayat->links('pagination::bootstrap-5') }}
        </div>
    @else
        <div class="tk-kosong">
            <div class="tk-kosong-ilustrasi"><i class="bi bi-calendar-x"></i></div>
            <p class="tk-panel-judul">Belum ada catatan absensi</p>
            <p class="tk-panel-sub">
                @if ($adaFilter) Tidak ada data pada periode {{ $labelPeriode }}. Coba ubah filter. @else Riwayat akan muncul setelah Anda melakukan absensi. @endif
            </p>
            <a href="{{ route('tenaga-kerja.absensi') }}" class="tk-btn tk-btn-utama mt-2"><i class="bi bi-fingerprint"></i> Ke Halaman Absensi</a>
        </div>
    @endif
</section>
@endsection

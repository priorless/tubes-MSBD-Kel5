@extends('layouts.tenaga-kerja')

@section('title', 'Riwayat Absensi')

@use('App\Support\FormatKolom')

@section('content')
{{-- ===================== RINGKASAN ===================== --}}
<div class="row g-4 mb-4">
    <div class="col-6 col-lg-3 tk-reveal">
        <div class="tk-card tk-stat">
            <div class="tk-stat-ikon tk-bg-teal"><i class="bi bi-journal-check"></i></div>
            <div class="tk-stat-angka" data-hitung="{{ $riwayat?->total() ?? 0 }}">0</div>
            <div class="tk-stat-label">Total Catatan</div>
        </div>
    </div>
    @foreach ($ringkasan->take(3) as $statusNama => $jumlah)
        <div class="col-6 col-lg-3 tk-reveal">
            <div class="tk-card tk-stat">
                <div class="tk-stat-ikon tk-bg-{{ FormatKolom::warnaStatus($statusNama) }}"><i class="bi bi-bar-chart"></i></div>
                <div class="tk-stat-angka" data-hitung="{{ $jumlah }}">0</div>
                <div class="tk-stat-label">{{ $statusNama ?: 'Tanpa Keterangan' }}</div>
            </div>
        </div>
    @endforeach
</div>

{{-- ===================== FILTER ===================== --}}
<section class="tk-card mb-4 tk-reveal">
    <form method="GET" action="{{ route('tenaga-kerja.riwayat') }}" class="row g-3 align-items-end" id="tkFormFilter">
        <div class="col-6 col-md-3">
            <label for="dari" class="tk-label">Dari Tanggal</label>
            <input type="date" id="dari" name="dari" value="{{ $dari }}" class="form-control tk-input">
        </div>
        <div class="col-6 col-md-3">
            <label for="sampai" class="tk-label">Sampai Tanggal</label>
            <input type="date" id="sampai" name="sampai" value="{{ $sampai }}" class="form-control tk-input">
        </div>
        <div class="col-12 col-md-6 d-flex gap-2 flex-wrap">
            <button type="submit" class="tk-btn tk-btn-utama"><i class="bi bi-funnel"></i> Terapkan</button>
            @if ($dari || $sampai)
                <a href="{{ route('tenaga-kerja.riwayat') }}" class="tk-btn tk-btn-garis"><i class="bi bi-x-lg"></i> Reset</a>
            @endif
            <a href="{{ route('tenaga-kerja.riwayat.unduh', array_filter(['dari' => $dari, 'sampai' => $sampai])) }}"
               class="tk-btn tk-btn-garis ms-md-auto" data-no-transisi>
                <i class="bi bi-download"></i> Unduh CSV
            </a>
        </div>
        <div class="col-12">
            <div class="tk-preset">
                <span class="tk-label mb-0 me-1">Cepat:</span>
                <button type="button" class="tk-pill" data-preset="7">7 hari terakhir</button>
                <button type="button" class="tk-pill" data-preset="30">30 hari terakhir</button>
                <button type="button" class="tk-pill" data-preset="bulan-ini">Bulan ini</button>
                <button type="button" class="tk-pill" data-preset="bulan-lalu">Bulan lalu</button>
            </div>
        </div>
    </form>
</section>

{{-- ===================== TABEL ===================== --}}
<section class="tk-card tk-reveal">
    <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-3">
        <h2 class="tk-card-title mb-0"><i class="bi bi-table"></i> Catatan Kehadiran
            @if ($dari || $sampai)
                <small class="tk-muted fw-normal">
                    ({{ $dari ? FormatKolom::tanggal($dari, 'd M Y') : 'awal' }} – {{ $sampai ? FormatKolom::tanggal($sampai, 'd M Y') : 'sekarang' }})
                </small>
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
            <div class="tk-muted small">Menampilkan {{ $riwayat->firstItem() }}–{{ $riwayat->lastItem() }} dari {{ $riwayat->total() }} catatan</div>
            {{ $riwayat->links('pagination::bootstrap-5') }}
        </div>
    @else
        <div class="tk-kosong">
            <div class="tk-kosong-ilustrasi"><i class="bi bi-calendar-x"></i></div>
            <p class="tk-panel-judul">Belum ada catatan absensi</p>
            <p class="tk-panel-sub">
                @if ($dari || $sampai) Tidak ada data pada rentang tanggal ini. Coba ubah filter. @else Riwayat akan muncul setelah Anda melakukan absensi. @endif
            </p>
            <a href="{{ route('tenaga-kerja.absensi') }}" class="tk-btn tk-btn-utama mt-2"><i class="bi bi-fingerprint"></i> Ke Halaman Absensi</a>
        </div>
    @endif
</section>
@endsection

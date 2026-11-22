@extends('layouts.app')

@section('title', 'Dashboard CEO')
@section('page-title', 'Dashboard Eksekutif')

@php
    $rupiah = fn (float $nilai): string => ($nilai < 0 ? '-Rp ' : 'Rp ').number_format(abs($nilai), 0, ',', '.');
    $panah = '<svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

    // Keterangan singkat tiap KPI dari CeoController::dashboard() (label tetap dari controller).
    $keteranganKpi = [
        'Lapangan Bertugas' => 'orang sedang ditempatkan di klien',
        'Lapangan Siap' => 'orang tersedia untuk ditempatkan',
        'Internal Aktif' => 'orang staf internal berstatus aktif',
        'Total Piutang' => 'Tagihan invoice klien berstatus Belum Lunas',
        'Profit Bulan Berjalan' => 'Pemasukan dikurangi pengeluaran '.$labelPeriode,
    ];
@endphp

@section('content')
    <x-ui.page-header :kicker="now()->locale('id')->translatedFormat('l, j F Y').' · Ringkasan eksekutif'"
        :title="'Selamat datang, '.auth()->user()->namaTampilan()"
        :description="'Ringkasan kinerja perusahaan secara real-time · Periode berjalan: '.$labelPeriode">
        <a href="{{ route('ceo.log-aktivitas') }}" class="btn-secondary">Log Aktivitas</a>
        <a href="{{ route('ceo.profit-klien') }}" class="btn-primary">Analisis Profit Klien</a>
    </x-ui.page-header>

    {{-- Ringkasan dari view v_dashboard_eksekutif: satu strip seperti Beranda Operasional. Piutang yang belum lunas diberi blok navy. --}}
    <section class="op-headline is-nominal mb-6" style="--kolom: {{ count($kpi) }}" aria-label="Ringkasan eksekutif">
        @foreach ($kpi as $kartu)
            @php
                $perluTindakan = $kartu['label'] === 'Total Piutang' && $kartu['nilai'] > 0;
                $kelasNilai = match (true) {
                    $kartu['label'] === 'Profit Bulan Berjalan' && $kartu['nilai'] < 0 => 'is-negatif',
                    $kartu['label'] === 'Profit Bulan Berjalan' => 'is-positif',
                    default => null,
                };
            @endphp
            <div @class(['op-headline-item', 'is-perlu-tindakan' => $perluTindakan])>
                @if ($perluTindakan)
                    <span class="op-stempel">PERLU TINDAKAN</span>
                @endif
                <span class="stat-label">{{ $kartu['label'] }}</span>
                <span @class(['op-headline-angka', 'is-nominal' => $kartu['uang'], $kelasNilai])>
                    {{ $kartu['uang'] ? $rupiah($kartu['nilai']) : number_format($kartu['nilai'], 0, ',', '.') }}
                </span>
                @isset($keteranganKpi[$kartu['label']])
                    <span class="op-headline-hint">{{ $keteranganKpi[$kartu['label']] }}</span>
                @endisset
                @if ($kartu['label'] === 'Profit Bulan Berjalan')
                    <a href="{{ route('ceo.profit-klien') }}" class="op-link">Analisis per Periode {!! $panah !!}</a>
                @endif
            </div>
        @endforeach
    </section>

    {{-- Grafik arus kas dari view v_arus_kas_bulanan --}}
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Grafik Arus Kas <span class="text-sm font-normal text-gray-400">12 bulan terakhir</span></h3>
        </div>
        <div class="card-body">
            @if (count($chartLabels) > 0)
                <div class="relative h-80">
                    <canvas id="arusKasChart" role="img" aria-label="Grafik pemasukan dan pengeluaran per bulan"></canvas>
                </div>
            @else
                <p class="text-gray-500">Belum ada mutasi keuangan yang tercatat.</p>
            @endif
        </div>
    </div>

    {{-- Profit per klien dari function fn_profit_klien --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Profit per Klien &middot; {{ $labelPeriode }}</h3>
            <a href="{{ route('ceo.profit-klien') }}" class="btn-secondary btn-sm">Analisis per Periode</a>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode</th>
                        <th>Nama Perusahaan</th>
                        <th class="text-right">Profit</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($profitKlien as $klien)
                        @php $profit = (float) $klien->profit; @endphp
                        <tr>
                            <td class="text-gray-500">{{ $loop->iteration }}</td>
                            <td class="font-mono text-gray-600">{{ $klien->kode_klien }}</td>
                            <td>
                                <a href="{{ route('ceo.profit-klien', ['id_klien' => $klien->id]) }}" class="font-medium text-sji-biru hover:underline">{{ $klien->nama_perusahaan }}</a>
                            </td>
                            <td @class([
                                'text-right font-semibold tabular-nums',
                                'text-red-500' => $profit < 0,
                                'text-emerald-600' => $profit > 0,
                                'text-gray-400' => $profit == 0,
                            ])>{{ $rupiah($profit) }}</td>
                            <td class="text-center">
                                @if ($profit > 0)
                                    <span class="badge badge-success">Untung</span>
                                @elseif ($profit < 0)
                                    <span class="badge badge-danger">Rugi</span>
                                @else
                                    <span class="badge badge-secondary">Belum ada transaksi</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="table-empty">Belum ada klien aktif.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($profitKlien->isNotEmpty())
                    <tfoot class="border-t border-gray-200 bg-gray-50">
                        <tr>
                            <th colspan="3" class="px-4 py-3 text-right font-semibold text-gray-700">Total</th>
                            <th @class(['px-4 py-3 text-right font-bold tabular-nums', 'text-red-500' => $totalProfitKlien < 0, 'text-emerald-600' => $totalProfitKlien >= 0])>
                                {{ $rupiah($totalProfitKlien) }}
                            </th>
                            <th></th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
@endsection

@push('scripts')
    @if (count($chartLabels) > 0)
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            (() => {
                const rupiah = (n) => (n < 0 ? '-Rp ' : 'Rp ') + Math.abs(Math.round(Number(n))).toLocaleString('id-ID');

                // Warna mengikuti token tema (resources/css/operasional.css): hijau = pemasukan, merah = pengeluaran.
                Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
                Chart.defaults.color = '#6b655b';

                new Chart(document.getElementById('arusKasChart'), {
                    type: 'line',
                    data: {
                        labels: @json($chartLabels),
                        datasets: [
                            { label: 'Pemasukan', data: @json($chartPemasukan), borderColor: '#2a6646', backgroundColor: 'rgba(42,102,70,.08)', fill: true, tension: .3, pointRadius: 4, pointHoverRadius: 6, borderWidth: 2 },
                            { label: 'Pengeluaran', data: @json($chartPengeluaran), borderColor: '#b03d2a', backgroundColor: 'rgba(176,61,42,.06)', fill: true, tension: .3, pointRadius: 4, pointHoverRadius: 6, borderWidth: 2 },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'top', align: 'end' },
                            tooltip: { callbacks: { label: (c) => ' ' + c.dataset.label + ': ' + rupiah(c.parsed.y) } },
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { callback: (v) => rupiah(v) }, grid: { color: '#efeae0' } },
                            x: { grid: { display: false } },
                        },
                    },
                });
            })();
        </script>
    @endif
@endpush

@extends('layouts.app')

@section('title', 'Dashboard CEO')
@section('page-title', 'Dashboard Eksekutif')

@php
    $rupiah = fn (float $nilai): string => ($nilai < 0 ? '-Rp ' : 'Rp ').number_format(abs($nilai), 0, ',', '.');

    // Keterangan singkat tiap KPI dari CeoController::dashboard() (label tetap dari controller).
    $keteranganKpi = [
        'Lapangan Bertugas' => 'orang sedang ditempatkan di klien',
        'Lapangan Siap' => 'orang tersedia untuk ditempatkan',
        'Internal Aktif' => 'orang staf internal berstatus aktif',
        'Total Piutang' => 'Tagihan invoice klien berstatus Belum Lunas',
    ];
@endphp

@section('content')
    <x-ui.page-header :kicker="now()->locale('id')->translatedFormat('l, j F Y').' · Ringkasan eksekutif'"
        :title="'Selamat datang, '.auth()->user()->namaTampilan()"
        :description="'Ringkasan kinerja perusahaan secara real-time · Periode berjalan: '.$labelPeriode">
        <a href="{{ route('ceo.log-aktivitas') }}" class="btn-secondary">Log Aktivitas</a>
        <a href="{{ route('ceo.klien') }}" class="btn-primary">Lihat Klien</a>
    </x-ui.page-header>

    {{-- Ringkasan dari view v_dashboard_eksekutif: satu strip seperti Beranda Operasional. Piutang yang belum lunas diberi blok navy. --}}
    <section class="op-headline is-nominal mb-6" style="--kolom: {{ count($kpi) }}" aria-label="Ringkasan eksekutif">
        @foreach ($kpi as $kartu)
            @php
                $perluTindakan = $kartu['label'] === 'Total Piutang' && $kartu['nilai'] > 0;
            @endphp
            <div @class(['op-headline-item', 'is-perlu-tindakan' => $perluTindakan])>
                @if ($perluTindakan)
                    <span class="op-stempel">PERLU TINDAKAN</span>
                @endif
                <span class="stat-label">{{ $kartu['label'] }}</span>
                <span @class(['op-headline-angka', 'is-nominal' => $kartu['uang']])>
                    {{ $kartu['uang'] ? $rupiah($kartu['nilai']) : number_format($kartu['nilai'], 0, ',', '.') }}
                </span>
                @isset($keteranganKpi[$kartu['label']])
                    <span class="op-headline-hint">{{ $keteranganKpi[$kartu['label']] }}</span>
                @endisset
            </div>
        @endforeach
    </section>

    {{-- Grafik arus kas dari view v_arus_kas_bulanan --}}
    <div class="card">
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

@extends('layouts.app')

@section('title', 'Dashboard CEO')
@section('page-title', 'Dashboard Eksekutif')

@php
    $rupiah = fn (float $nilai): string => ($nilai < 0 ? '-Rp ' : 'Rp ').number_format(abs($nilai), 0, ',', '.');
@endphp

@section('content')
    <p class="mb-6 text-sm text-gray-500">Ringkasan kinerja perusahaan secara real-time &middot; Periode berjalan: <span class="font-medium text-gray-700">{{ $labelPeriode }}</span></p>

    {{-- Kartu statistik dari view v_dashboard_eksekutif --}}
    <div class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($kpi as $kartu)
            @php
                $warnaNilai = match (true) {
                    $kartu['label'] === 'Total Piutang' && $kartu['nilai'] > 0 => 'text-red-500',
                    $kartu['label'] === 'Profit Bulan Berjalan' && $kartu['nilai'] < 0 => 'text-red-500',
                    $kartu['label'] === 'Profit Bulan Berjalan' => 'text-emerald-600',
                    default => 'text-gray-800',
                };
            @endphp
            <div class="stat-card">
                <h3 class="stat-label">{{ $kartu['label'] }}</h3>
                <p class="stat-value {{ $warnaNilai }}">
                    {{ $kartu['uang'] ? $rupiah($kartu['nilai']) : number_format($kartu['nilai'], 0, ',', '.') }}
                </p>
                @unless ($kartu['uang'])
                    <p class="mt-1 text-xs text-gray-400">orang</p>
                @endunless
            </div>
        @endforeach
    </div>

    {{-- Grafik arus kas dari view v_arus_kas_bulanan --}}
    <div class="card mb-6 p-6">
        <h3 class="mb-4 text-lg font-semibold text-gray-700">Grafik Arus Kas <span class="text-sm font-normal text-gray-400">12 bulan terakhir</span></h3>
        @if (count($chartLabels) > 0)
            <div class="relative h-80">
                <canvas id="arusKasChart" role="img" aria-label="Grafik pemasukan dan pengeluaran per bulan"></canvas>
            </div>
        @else
            <p class="text-gray-500">Belum ada mutasi keuangan yang tercatat.</p>
        @endif
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

                new Chart(document.getElementById('arusKasChart'), {
                    type: 'line',
                    data: {
                        labels: @json($chartLabels),
                        datasets: [
                            { label: 'Pemasukan', data: @json($chartPemasukan), borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.08)', fill: true, tension: .3, pointRadius: 4, pointHoverRadius: 6, borderWidth: 2 },
                            { label: 'Pengeluaran', data: @json($chartPengeluaran), borderColor: '#e11d24', backgroundColor: 'rgba(225,29,36,.06)', fill: true, tension: .3, pointRadius: 4, pointHoverRadius: 6, borderWidth: 2 },
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
                            y: { beginAtZero: true, ticks: { callback: (v) => rupiah(v) }, grid: { color: '#f3f4f6' } },
                            x: { grid: { display: false } },
                        },
                    },
                });
            })();
        </script>
    @endif
@endpush

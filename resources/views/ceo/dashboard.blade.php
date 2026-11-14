@extends('layouts.admin')

@section('title', 'Dashboard Eksekutif')

@push('css')
    <link rel="stylesheet" href="{{ asset('css/ceo-theme.css') }}">
    <style>
        .ceo-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .ceo-kpi-value { font-variant-numeric: tabular-nums; }
        .ceo-table td, .ceo-table th { vertical-align: middle; font-variant-numeric: tabular-nums; }
    </style>
@endpush

@section('content')
<div class="ceo-wrap">
    <div class="ceo-hero">
        <h2>Dashboard Eksekutif</h2>
        <p>Ringkasan kinerja perusahaan SJI secara real-time &middot; Periode berjalan: {{ $labelPeriode }}</p>
        <button type="button" class="ceo-toggle" id="ceoTheme">🌙 Mode Gelap</button>
    </div>

    {{-- Kartu statistik dari view v_dashboard_eksekutif --}}
    <div class="ceo-kpi-grid ceo-stagger">
        @foreach ($kpi as $kartu)
            <div>
                <div class="ceo-card">
                    <div class="ceo-kpi-icon text-{{ $kartu['warna'] }}"><i class="fas {{ $kartu['ikon'] }}"></i></div>
                    <div class="ceo-kpi-label">{{ $kartu['label'] }}</div>
                    <div class="ceo-kpi-value" data-count="{{ $kartu['nilai'] }}" data-uang="{{ $kartu['uang'] ? 1 : 0 }}">
                        {{ $kartu['uang'] ? 'Rp '.number_format($kartu['nilai'], 0, ',', '.') : number_format($kartu['nilai'], 0, ',', '.') }}
                    </div>
                    @unless ($kartu['uang'])
                        <div class="text-muted small">orang</div>
                    @endunless
                </div>
            </div>
        @endforeach
    </div>

    {{-- Grafik arus kas dari view v_arus_kas_bulanan --}}
    <div class="ceo-card mb-4" style="animation-delay:.4s">
        <h4 class="ceo-title">Arus Kas Bulanan <small class="text-muted">(12 bulan terakhir)</small></h4>
        @if (count($chartLabels) > 0)
            <div class="ceo-chart"><canvas id="arusKasChart" aria-label="Grafik pemasukan dan pengeluaran per bulan" role="img"></canvas></div>
        @else
            <div class="ceo-empty">Belum ada mutasi keuangan yang tercatat.</div>
        @endif
    </div>

    {{-- Profit per klien dari function fn_profit_klien --}}
    <div class="ceo-card" style="animation-delay:.5s">
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
            <h4 class="ceo-title mb-0">Profit per Klien &middot; {{ $labelPeriode }}</h4>
            <a href="{{ route('ceo.profit-klien') }}" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-search-dollar mr-1"></i> Analisis per Periode
            </a>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ceo-table mb-0">
                <thead>
                    <tr>
                        <th style="width: 50px">No</th>
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
                            <td>{{ $loop->iteration }}</td>
                            <td><code>{{ $klien->kode_klien }}</code></td>
                            <td>
                                <a href="{{ route('ceo.profit-klien', ['id_klien' => $klien->id]) }}">{{ $klien->nama_perusahaan }}</a>
                            </td>
                            <td class="text-right font-weight-bold {{ $profit < 0 ? 'text-danger' : ($profit > 0 ? 'text-success' : 'text-muted') }}">
                                {{ ($profit < 0 ? '-Rp ' : 'Rp ').number_format(abs($profit), 0, ',', '.') }}
                            </td>
                            <td class="text-center">
                                @if ($profit > 0)
                                    <span class="ceo-badge good">▲ Untung</span>
                                @elseif ($profit < 0)
                                    <span class="ceo-badge bad">▼ Rugi</span>
                                @else
                                    <span class="badge badge-light">Belum ada transaksi</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Belum ada klien aktif.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($profitKlien->isNotEmpty())
                    <tfoot>
                        <tr>
                            <th colspan="3" class="text-right">Total</th>
                            <th class="text-right {{ $totalProfitKlien < 0 ? 'text-danger' : 'text-success' }}">
                                {{ ($totalProfitKlien < 0 ? '-Rp ' : 'Rp ').number_format(abs($totalProfitKlien), 0, ',', '.') }}
                            </th>
                            <th></th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
@endsection

@push('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(() => {
    const rupiah = n => (n < 0 ? '-Rp ' : 'Rp ') + Math.abs(Math.round(Number(n))).toLocaleString('id-ID');

    // Mode gelap tersimpan di browser
    const body = document.body, btn = document.getElementById('ceoTheme');
    const setDark = on => {
        body.classList.toggle('ceo-dark', on);
        btn.textContent = on ? '☀️ Mode Terang' : '🌙 Mode Gelap';
        try { localStorage.setItem('ceoDark', on ? '1' : '0'); } catch (e) {}
    };
    let simpanDark = false;
    try { simpanDark = localStorage.getItem('ceoDark') === '1'; } catch (e) {}
    setDark(simpanDark);
    btn.onclick = () => setDark(!body.classList.contains('ceo-dark'));

    // Animasi hitung naik pada kartu KPI
    document.querySelectorAll('[data-count]').forEach(el => {
        const target = parseFloat(el.dataset.count), uang = el.dataset.uang === '1', dur = 1200, t0 = performance.now();
        const tick = now => {
            const p = Math.min((now - t0) / dur, 1), v = target * (1 - Math.pow(1 - p, 4));
            el.textContent = uang ? rupiah(v) : Math.round(v).toLocaleString('id-ID');
            if (p < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });

    // Grafik garis pemasukan vs pengeluaran
    const canvas = document.getElementById('arusKasChart');
    if (!canvas) return;
    new Chart(canvas, {
        type: 'line',
        data: {
            labels: @json($chartLabels),
            datasets: [
                { label: 'Pemasukan', data: @json($chartPemasukan), borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.12)', fill: true, tension: .3, pointRadius: 4, pointHoverRadius: 7, borderWidth: 2 },
                { label: 'Pengeluaran', data: @json($chartPengeluaran), borderColor: '#e11d48', backgroundColor: 'rgba(225,29,72,.08)', fill: true, tension: .3, pointRadius: 4, pointHoverRadius: 7, borderWidth: 2 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top' },
                tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + rupiah(c.parsed.y) } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => rupiah(v) }, grid: { color: 'rgba(148,163,184,.2)' } },
                x: { grid: { display: false } }
            }
        }
    });
})();
</script>
@endpush

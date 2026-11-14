@extends('layouts.admin')

@section('title', 'Profit Klien')

@push('css')
    <link rel="stylesheet" href="{{ asset('css/ceo-theme.css') }}">
@endpush

@section('content')
<div class="ceo-wrap">
    <div class="ceo-hero">
        <h2>Profitabilitas Klien</h2>
        <p>Hitung laba/rugi per klien per periode langsung dari database</p>
    </div>

    @if($errors->any())
        <div class="alert alert-warning ceo-alert">{{ $errors->first() }}</div>
    @endif

    <div class="row ceo-stagger">
        <div class="col-md-4 mb-4">
            <div class="ceo-card">
                <form action="{{ route('ceo.profit-klien') }}" method="GET">
                    <div class="form-group">
                        <label>Pilih Klien</label>
                        <select name="id_klien" required class="form-control">
                            <option value="">-- Pilih Klien --</option>
                            @foreach($klienList as $k)
                                <option value="{{ $k->id }}" @selected($selectedKlienId == $k->id)>{{ $k->nama_perusahaan }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Bulan</label>
                        <input type="number" name="bulan" value="{{ $selectedBulan }}" min="1" max="12" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Tahun</label>
                        <input type="number" name="tahun" value="{{ $selectedTahun }}" class="form-control">
                    </div>
                    <button type="submit" class="ceo-btn">Hitung Profit</button>
                </form>
            </div>
        </div>

        <div class="col-md-8 mb-4">
            <div class="ceo-card">
                @if($selectedKlienId && $profitResult !== null)
                    <div class="ceo-kpi-label">Hasil Perhitungan Profit</div>
                    <div class="ceo-result {{ $profitResult >= 0 ? 'ceo-good' : 'ceo-bad' }}"
                         data-count="{{ $profitResult }}">Rp 0</div>
                    <span class="ceo-badge {{ $profitResult >= 0 ? 'good' : 'bad' }}">
                        {{ $profitResult >= 0 ? '▲ Surplus (Untung)' : '▼ Defisit (Rugi)' }}
                    </span>
                    <div class="ceo-chart mt-4" style="height:260px"><canvas id="trenChart"></canvas></div>
                @else
                    <div class="ceo-empty">📈<br>Pilih klien pada formulir untuk melihat profitabilitas.</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('js')
@if($selectedKlienId && $profitResult !== null)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(() => {
    const rupiah = n => (n < 0 ? '-Rp ' : 'Rp ') + Math.abs(Number(n)).toLocaleString('id-ID');
    const el = document.querySelector('[data-count]'), target = parseFloat(el.dataset.count), t0 = performance.now();
    const tick = now => {
        const p = Math.min((now - t0) / 1300, 1);
        el.textContent = rupiah(Math.round(target * (1 - Math.pow(1 - p, 4))));
        if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);

    const ctx = document.getElementById('trenChart').getContext('2d');
    const g = ctx.createLinearGradient(0, 0, 0, 260);
    g.addColorStop(0, 'rgba(99,102,241,.45)'); g.addColorStop(1, 'rgba(99,102,241,0)');
    new Chart(ctx, {
        type: 'line',
        data: { labels: @json($trenLabels), datasets: [{ label: 'Profit', data: @json($trenProfit), borderColor: '#6366f1', backgroundColor: g, fill: true, tension: .4, pointRadius: 5, pointHoverRadius: 8 }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            animation: { duration: 1500, easing: 'easeOutQuart' },
            plugins: { legend: { display: false }, title: { display: true, text: 'Tren Profit 6 Bulan' }, tooltip: { callbacks: { label: c => ' ' + rupiah(c.parsed.y) } } },
            scales: { y: { ticks: { callback: v => rupiah(v) }, grid: { color: 'rgba(148,163,184,.2)' } }, x: { grid: { display: false } } }
        }
    });
})();
</script>
@endif
@endpush

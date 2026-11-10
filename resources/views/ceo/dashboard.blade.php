{{-- resources/views/ceo/dashboard.blade.php
     GANTI 'layouts.app' dengan nama layout AdminLTE yang dipakai kelompokmu, dan sesuaikan nama section/stack-nya. --}}
@extends('layouts.app')

@section('title', 'Dashboard CEO')

@push('css')
    <link rel="stylesheet" href="{{ asset('css/ceo-theme.css') }}">
@endpush

@section('content')
<div class="ceo-wrap">
    <div class="ceo-hero">
        <h2>Dashboard Eksekutif</h2>
        <p>Ringkasan kinerja perusahaan SJI secara real-time</p>
        <button type="button" class="ceo-toggle" id="ceoTheme">🌙 Mode Gelap</button>
    </div>

    @if(session('error') || isset($error))
        <div class="alert alert-danger ceo-alert">{{ session('error') ?? $error }}</div>
    @endif

    <div class="row ceo-stagger">
        @forelse($kpi as $k)
            <div class="col-md-6 col-xl-3 mb-4">
                <div class="ceo-card">
                    <div class="ceo-kpi-icon">{{ $k['uang'] ? '💰' : '📊' }}</div>
                    <div class="ceo-kpi-label">{{ $k['label'] }}</div>
                    @if($k['angka'])
                        <div class="ceo-kpi-value" data-count="{{ $k['nilai'] }}" data-uang="{{ $k['uang'] ? 1 : 0 }}">0</div>
                    @else
                        <div class="ceo-kpi-value" style="font-size:1.3rem">{{ $k['nilai'] }}</div>
                    @endif
                </div>
            </div>
        @empty
            <div class="col-12"><div class="ceo-card ceo-empty">Belum ada data ringkasan eksekutif.</div></div>
        @endforelse
    </div>

    <div class="ceo-card" style="animation-delay:.5s">
        <h4 class="ceo-title">Arus Kas Bulanan</h4>
        <div class="ceo-chart"><canvas id="arusKasChart"></canvas></div>
    </div>
</div>
@endsection

@push('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
    const rupiah = n => 'Rp ' + Number(n).toLocaleString('id-ID');

    // Mode gelap tersimpan di browser
    const body = document.body, btn = document.getElementById('ceoTheme');
    const setDark = on => { body.classList.toggle('ceo-dark', on); btn.textContent = on ? '☀️ Mode Terang' : '🌙 Mode Gelap'; localStorage.setItem('ceoDark', on ? '1' : '0'); };
    setDark(localStorage.getItem('ceoDark') === '1');
    btn.onclick = () => setDark(!body.classList.contains('ceo-dark'));

    // Animasi hitung naik pada kartu KPI
    document.querySelectorAll('[data-count]').forEach(el => {
        const target = parseFloat(el.dataset.count), uang = el.dataset.uang === '1', dur = 1400, t0 = performance.now();
        const tick = now => {
            const p = Math.min((now - t0) / dur, 1), v = target * (1 - Math.pow(1 - p, 4));
            el.textContent = uang ? rupiah(Math.round(v)) : Math.round(v).toLocaleString('id-ID');
            if (p < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });

    // Grafik arus kas
    const ctx = document.getElementById('arusKasChart').getContext('2d');
    const grad = (c1, c2) => { const g = ctx.createLinearGradient(0, 0, 0, 380); g.addColorStop(0, c1); g.addColorStop(1, c2); return g; };
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: @json($chartLabels),
            datasets: [
                { label: 'Pemasukan', data: @json($chartPemasukan), backgroundColor: grad('#34d399', '#059669'), borderRadius: 8 },
                { label: 'Pengeluaran', data: @json($chartPengeluaran), backgroundColor: grad('#fb7185', '#e11d48'), borderRadius: 8 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            animation: { duration: 1400, easing: 'easeOutQuart' },
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + rupiah(c.parsed.y) } } },
            scales: { y: { ticks: { callback: v => rupiah(v) }, grid: { color: 'rgba(148,163,184,.2)' } }, x: { grid: { display: false } } }
        }
    });
})();
</script>
@endpush

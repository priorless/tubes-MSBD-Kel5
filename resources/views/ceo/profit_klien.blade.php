@extends('layouts.app')

@section('title', 'Profit Klien')
@section('page-title', 'Profitabilitas Klien')

@php
    $rupiah = fn (float $nilai): string => ($nilai < 0 ? '-Rp ' : 'Rp ').number_format(abs($nilai), 0, ',', '.');
    $adaHasil = $selectedKlienId && $profitResult !== null;
@endphp

@section('content')
    <p class="mb-6 text-sm text-gray-500">Hitung laba/rugi per klien per periode langsung dari database.</p>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <form action="{{ route('ceo.profit-klien') }}" method="GET" class="card space-y-4 p-6">
            <div>
                <label for="id_klien" class="form-label">Pilih Klien</label>
                <select id="id_klien" name="id_klien" class="form-input" required>
                    <option value="">-- Pilih Klien --</option>
                    @foreach ($klienList as $klien)
                        <option value="{{ $klien->id }}" @selected($selectedKlienId == $klien->id)>{{ $klien->nama_perusahaan }}</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="bulan" class="form-label">Bulan</label>
                    <select id="bulan" name="bulan" class="form-input">
                        @foreach (range(1, 12) as $bulan)
                            <option value="{{ $bulan }}" @selected($selectedBulan === $bulan)>
                                {{ \Illuminate\Support\Carbon::create(null, $bulan, 1)->translatedFormat('F') }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="tahun" class="form-label">Tahun</label>
                    <input type="number" id="tahun" name="tahun" value="{{ $selectedTahun }}" min="2000" max="2100" class="form-input">
                </div>
            </div>

            <button type="submit" class="btn-primary w-full">Hitung Profit</button>
        </form>

        <div class="card p-6 lg:col-span-2">
            @if ($adaHasil)
                <h3 class="stat-label">Profit {{ \Illuminate\Support\Carbon::create($selectedTahun, $selectedBulan, 1)->translatedFormat('F Y') }}</h3>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <p @class(['text-3xl font-bold tabular-nums', 'text-emerald-600' => $profitResult >= 0, 'text-red-500' => $profitResult < 0])>
                        {{ $rupiah($profitResult) }}
                    </p>
                    @if ($profitResult >= 0)
                        <span class="badge badge-success">Surplus (Untung)</span>
                    @else
                        <span class="badge badge-danger">Defisit (Rugi)</span>
                    @endif
                </div>

                <h3 class="mt-8 mb-4 text-lg font-semibold text-gray-700">Tren Profit 6 Bulan</h3>
                <div class="relative h-64">
                    <canvas id="trenChart" role="img" aria-label="Grafik tren profit 6 bulan"></canvas>
                </div>
            @else
                <div class="flex h-full min-h-48 items-center justify-center text-center text-gray-500">
                    Pilih klien pada formulir untuk melihat profitabilitas.
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    @if ($adaHasil)
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            (() => {
                const rupiah = (n) => (n < 0 ? '-Rp ' : 'Rp ') + Math.abs(Math.round(Number(n))).toLocaleString('id-ID');

                new Chart(document.getElementById('trenChart'), {
                    type: 'line',
                    data: {
                        labels: @json($trenLabels),
                        datasets: [{ label: 'Profit', data: @json($trenProfit), borderColor: '#1e40af', backgroundColor: 'rgba(30,64,175,.08)', fill: true, tension: .3, pointRadius: 4, pointHoverRadius: 6, borderWidth: 2 }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: (c) => ' ' + rupiah(c.parsed.y) } },
                        },
                        scales: {
                            y: { ticks: { callback: (v) => rupiah(v) }, grid: { color: '#f3f4f6' } },
                            x: { grid: { display: false } },
                        },
                    },
                });
            })();
        </script>
    @endif
@endpush

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Eksekutif - CEO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased">
    <div class="min-h-screen flex flex-col">
        <!-- Header Navigasi -->
        <header class="bg-slate-900 text-white shadow-md">
            <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
                <h1 class="text-xl font-bold tracking-wide">Executive Panel - CEO</h1>
                <nav class="flex space-x-4">
                    <a href="{{ route('ceo.dashboard') }}" class="px-3 py-2 rounded bg-slate-800 text-white font-medium">Dashboard</a>
                    <a href="{{ route('ceo.profit-klien') }}" class="px-3 py-2 rounded hover:bg-slate-800 text-slate-300">Profit Klien</a>
                </nav>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-grow max-w-7xl w-full mx-auto px-4 py-8">
            <div class="mb-8 flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900">Dashboard Ringkasan Eksekutif</h2>
                    <p class="text-sm text-slate-500">Pemantauan metrik operasional dan performa keuangan perusahaan.</p>
                </div>
            </div>

            <!-- Kartu Metrik Utama (v_dashboard_eksekutif) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                @php
                    $metrics = (array) $dashboardData;
                @endphp

                @forelse($metrics as $key => $val)
                    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">
                            {{ ucwords(str_replace('_', ' ', $key)) }}
                        </p>
                        <h3 class="text-2xl font-extrabold text-slate-800">
                            @if(is_numeric($val) && str_contains(strtolower($key), 'total') || str_contains(strtolower($key), 'kas') || str_contains(strtolower($key), 'pendapatan'))
                                Rp {{ number_format((float)$val, 0, ',', '.') }}
                            @else
                                {{ $val ?? '-' }}
                            @endif
                        </h3>
                    </div>
                @empty
                    <div class="col-span-4 bg-white p-6 rounded-xl border border-slate-200 text-center text-slate-500">
                        Data ringkasan eksekutif belum tersedia.
                    </div>
                @endforelse
            </div>

            <!-- Area Grafik Chart.js (v_arus_kas_bulanan) -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-slate-800">Tren Arus Kas Bulanan (Pemasukan vs Pengeluaran)</h3>
                    <span class="text-xs bg-slate-100 text-slate-600 px-2.5 py-1 rounded-full font-medium">Chart.js Live</span>
                </div>
                <div class="relative w-full" style="height: 380px;">
                    <canvas id="arusKasChart"></canvas>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const ctx = document.getElementById('arusKasChart').getContext('2d');
            
            const labels = @json($chartLabels);
            const dataPemasukan = @json($chartPemasukan);
            const dataPengeluaran = @json($chartPengeluaran);

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels.length ? labels : ['Belum Ada Data'],
                    datasets: [
                        {
                            label: 'Pemasukan (Rp)',
                            data: dataPemasukan,
                            backgroundColor: 'rgba(16, 185, 129, 0.85)', // Emerald
                            borderColor: 'rgb(16, 185, 129)',
                            borderWidth: 1,
                            borderRadius: 6
                        },
                        {
                            label: 'Pengeluaran (Rp)',
                            data: dataPengeluaran,
                            backgroundColor: 'rgba(239, 68, 68, 0.85)', // Red
                            borderColor: 'rgb(239, 68, 68)',
                            borderWidth: 1,
                            borderRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { font: { family: 'sans-serif', size: 13 } }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) label += ': ';
                                    if (context.parsed.y !== null) {
                                        label += new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(context.parsed.y);
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'Rp ' + (value / 1000000).toLocaleString('id-ID') + ' Jt';
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
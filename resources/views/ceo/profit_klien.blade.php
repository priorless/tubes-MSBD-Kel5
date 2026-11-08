<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analisis Profit Klien - CEO</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased">
    <div class="min-h-screen flex flex-col">
        <!-- Header Navigasi -->
        <header class="bg-slate-900 text-white shadow-md">
            <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
                <h1 class="text-xl font-bold tracking-wide">Executive Panel - CEO</h1>
                <nav class="flex space-x-4">
                    <a href="{{ route('ceo.dashboard') }}" class="px-3 py-2 rounded hover:bg-slate-800 text-slate-300">Dashboard</a>
                    <a href="{{ route('ceo.profit-klien') }}" class="px-3 py-2 rounded bg-slate-800 text-white font-medium">Profit Klien</a>
                </nav>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-grow max-w-7xl w-full mx-auto px-4 py-8">
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-slate-900">Analisis Profitabilitas Per Klien</h2>
                <p class="text-sm text-slate-500">Menghitung net profit per proyek/klien berdasarkan kalkulasi Stored Function <code class="bg-slate-200 px-1 py-0.5 rounded text-slate-700">fn_profit_klien</code>.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Form Filter -->
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <h3 class="text-lg font-bold text-slate-800 mb-4 border-b pb-2">Filter Perhitungan</h3>
                    <form action="{{ route('ceo.profit-klien') }}" method="GET" class="space-y-4">
                        <div>
                            <label for="id_klien" class="block text-sm font-semibold text-slate-700 mb-1">Pilih Klien / Perusahaan</label>
                            <select name="id_klien" id="id_klien" required class="w-full border-slate-300 rounded-lg shadow-sm focus:ring-slate-500 focus:border-slate-500 p-2.5 border">
                                <option value="">-- Pilih Klien --</option>
                                @foreach($klienList as $klien)
                                    <option value="{{ $klien->id }}" {{ $selectedKlienId == $klien->id ? 'selected' : '' }}>
                                        {{ $klien->nama_perusahaan }} ({{ $klien->kode_klien ?? 'ID: '.$klien->id }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="bulan" class="block text-sm font-semibold text-slate-700 mb-1">Periode Bulan</label>
                            <select name="bulan" id="bulan" required class="w-full border-slate-300 rounded-lg shadow-sm focus:ring-slate-500 focus:border-slate-500 p-2.5 border">
                                @for($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}" {{ $selectedBulan == $m ? 'selected' : '' }}>
                                        {{ DateTime::createFromFormat('!m', $m)->format('F') }}
                                    </option>
                                @endfor
                            </select>
                        </div>

                        <div>
                            <label for="tahun" class="block text-sm font-semibold text-slate-700 mb-1">Periode Tahun</label>
                            <input type="number" name="tahun" id="tahun" value="{{ $selectedTahun }}" min="2020" max="2030" required class="w-full border-slate-300 rounded-lg shadow-sm focus:ring-slate-500 focus:border-slate-500 p-2.5 border">
                        </div>

                        <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2.5 px-4 rounded-lg shadow transition duration-150">
                            Hitung Profit
                        </button>
                    </form>
                </div>

                <!-- Hasil Kalkulasi Stored Function -->
                <div class="lg:col-span-2">
                    @if($selectedKlienId && $profitResult !== null)
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                            <div class="border-b pb-4 mb-6 flex justify-between items-start">
                                <div>
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Hasil Stored Function</span>
                                    <h3 class="text-2xl font-bold text-slate-800">{{ $selectedKlienDetail->nama_perusahaan ?? 'Klien ID #'.$selectedKlienId }}</h3>
                                    <p class="text-sm text-slate-500">
                                        Periode: {{ DateTime::createFromFormat('!m', $selectedBulan)->format('F') }} {{ $selectedTahun }}
                                    </p>
                                </div>
                                <span class="px-3 py-1 bg-emerald-100 text-emerald-800 font-semibold text-xs rounded-full">
                                    fn_profit_klien() Executed
                                </span>
                            </div>

                            <div class="bg-slate-50 rounded-xl p-6 border border-slate-200 text-center mb-6">
                                <p class="text-sm font-semibold text-slate-500 mb-1">Total Net Profit Proyek</p>
                                <div class="text-4xl font-extrabold {{ $profitResult >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                    Rp {{ number_format($profitResult, 2, ',', '.') }}
                                </div>
                                <p class="text-xs text-slate-400 mt-2">
                                    Status: {{ $profitResult >= 0 ? 'Profit (Surplus)' : 'Defisit (Rugikan Operasional)' }}
                                </p>
                            </div>

                            @if($selectedKlienDetail)
                                <div class="grid grid-cols-2 gap-4 text-sm text-slate-600 border-t pt-4">
                                    <div>
                                        <span class="font-semibold block text-slate-700">PIC Perusahaan:</span>
                                        {{ $selectedKlienDetail->pic_nama ?? '-' }} ({{ $selectedKlienDetail->pic_jabatan ?? '-' }})
                                    </div>
                                    <div>
                                        <span class="font-semibold block text-slate-700">Kontak PIC:</span>
                                        {{ $selectedKlienDetail->pic_telp ?? '-' }} / {{ $selectedKlienDetail->pic_email ?? '-' }}
                                    </div>
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center text-slate-400 flex flex-col items-center justify-center h-full">
                            <svg class="w-16 h-16 mb-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                            </svg>
                            <h4 class="text-lg font-semibold text-slate-600 mb-1">Belum Ada Perhitungan Selected</h4>
                            <p class="text-sm max-w-md">Silahkan pilih Klien, Bulan, dan Tahun pada form di sebelah kiri untuk mengeksekusi perhitungan profitabilitas.</p>
                        </div>
                    @endif
                </div>
            </div>
        </main>
    </div>
</body>
</html>
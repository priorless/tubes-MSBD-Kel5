@extends('layouts.app')

@section('title', 'Mutasi Kas')

@section('content')
    <div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-3">
        <div class="stat-card">
            <h3 class="stat-label">Total Pemasukan</h3>
            <p class="stat-value text-emerald-600">Rp {{ number_format((float) ($ringkasan->total_masuk ?? 0), 0, ',', '.') }}</p>
        </div>
        <div class="stat-card">
            <h3 class="stat-label">Total Pengeluaran</h3>
            <p class="stat-value text-red-500">Rp {{ number_format((float) ($ringkasan->total_keluar ?? 0), 0, ',', '.') }}</p>
        </div>
        <div class="stat-card">
            <h3 class="stat-label">Selisih Arus Kas</h3>
            <p class="stat-value">Rp {{ number_format((float) ($ringkasan->selisih ?? 0), 0, ',', '.') }}</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Mutasi periode {{ $daftarBulan[$bulan] }} {{ $tahun }}</h2>
            <form method="GET" action="{{ route('keuangan.mutasi.index') }}" class="flex flex-wrap items-center gap-2">
                <label for="bulan" class="sr-only">Bulan</label>
                <select name="bulan" id="bulan" class="form-input w-auto py-1.5">
                    @foreach ($daftarBulan as $angka => $nama)
                        <option value="{{ $angka }}" @selected($angka === $bulan)>{{ $nama }}</option>
                    @endforeach
                </select>
                <label for="tahun" class="sr-only">Tahun</label>
                <input type="number" name="tahun" id="tahun" value="{{ $tahun }}" min="2000" max="2100" class="form-input w-24 py-1.5">
                <label for="jenis" class="sr-only">Jenis</label>
                <select name="jenis" id="jenis" class="form-input w-auto py-1.5">
                    <option value="">Semua jenis</option>
                    <option value="Pemasukan" @selected($jenis === 'Pemasukan')>Pemasukan</option>
                    <option value="Pengeluaran" @selected($jenis === 'Pengeluaran')>Pengeluaran</option>
                </select>
                <button type="submit" class="btn-secondary btn-sm">Tampilkan</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Jenis</th>
                        <th>Referensi</th>
                        <th>Keterangan</th>
                        <th class="text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mutasi as $item)
                        <tr>
                            <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($item->tgl_mutasi)->format('d/m/Y') }}</td>
                            <td>
                                @if ($item->jenis === 'Pemasukan')
                                    <span class="badge badge-success">Pemasukan</span>
                                @else
                                    <span class="badge badge-danger">Pengeluaran</span>
                                @endif
                            </td>
                            <td class="font-mono text-gray-600">{{ $item->referensi ?? '-' }}</td>
                            <td>{{ $item->keterangan ?? '-' }}</td>
                            <td @class(['text-right font-medium whitespace-nowrap tabular-nums', 'text-emerald-600' => $item->jenis === 'Pemasukan', 'text-red-600' => $item->jenis !== 'Pemasukan'])>
                                {{ $item->jenis === 'Pemasukan' ? '+' : '-' }} Rp {{ number_format((float) $item->nominal, 0, ',', '.') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="table-empty">Belum ada mutasi kas pada periode ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $mutasi->firstItem() ?? 0 }}&ndash;{{ $mutasi->lastItem() ?? 0 }} dari {{ $mutasi->total() }} mutasi</span>
            @include('partials.pagination', ['paginator' => $mutasi])
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Pool Karyawan')

@section('content')
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Karyawan Lapangan berstatus <span class="text-sky-700">Tersedia</span> dan siap ditempatkan</h2>
        </div>

        <form method="GET" action="{{ route('hrd.pool') }}" class="grid grid-cols-1 gap-3 border-b border-gray-200 bg-gray-50 px-6 py-4 md:grid-cols-12">
            <input type="search" name="q" value="{{ request('q') }}" class="form-input md:col-span-6" placeholder="Cari nama atau NIP" aria-label="Cari nama atau NIP">
            <select name="layanan" class="form-input md:col-span-4" aria-label="Filter jenis layanan">
                <option value="">Semua jenis layanan</option>
                @foreach ($daftarLayanan as $layanan)
                    <option value="{{ $layanan }}" @selected(request('layanan') === $layanan)>{{ $layanan }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary md:col-span-2">Terapkan</button>
        </form>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>NIP</th>
                        <th>Nama</th>
                        <th>Jenis Layanan</th>
                        <th>Tanggal Masuk</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pool as $item)
                        <tr>
                            <td class="font-mono text-gray-600">{{ $item->nip }}</td>
                            <td class="font-medium text-gray-800">{{ $item->nama }}</td>
                            <td>{{ $item->nama_layanan }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($item->tgl_masuk)->format('d/m/Y') }}</td>
                            <td><span class="badge badge-info">{{ $item->status }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="table-empty">Tidak ada karyawan Lapangan yang sedang tersedia.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $pool->firstItem() ?? 0 }}&ndash;{{ $pool->lastItem() ?? 0 }} dari {{ $pool->total() }} karyawan tersedia</span>
            @include('partials.pagination', ['paginator' => $pool])
        </div>
    </div>
@endsection

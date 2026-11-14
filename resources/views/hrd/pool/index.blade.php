@extends('layouts.adminlte')

@section('title', 'Pool Karyawan')
@section('modul', 'HRD')
@section('heading', 'Pool Karyawan')
@section('breadcrumb', 'Pool Karyawan')

@section('content')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Karyawan Lapangan berstatus <strong>Tersedia</strong> dan siap ditempatkan</h3>
        </div>
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('hrd.pool') }}" class="form-row">
                <div class="col-md-6 mb-2">
                    <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Cari nama atau NIP" aria-label="Cari nama atau NIP">
                </div>
                <div class="col-md-4 mb-2">
                    <select name="layanan" class="form-control form-control-sm" aria-label="Filter jenis layanan">
                        <option value="">Semua jenis layanan</option>
                        @foreach ($daftarLayanan as $layanan)
                            <option value="{{ $layanan }}" @selected(request('layanan') === $layanan)>{{ $layanan }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <button type="submit" class="btn btn-default btn-sm btn-block"><i class="fas fa-filter mr-1"></i> Terapkan</button>
                </div>
            </form>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-hover text-nowrap">
                <thead>
                    <tr>
                        <th>NIP</th>
                        <th>Nama</th>
                        <th>Jenis Layanan</th>
                        <th>Rating</th>
                        <th>Tanggal Masuk</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pool as $item)
                        <tr>
                            <td>{{ $item->nip }}</td>
                            <td><strong>{{ $item->nama }}</strong></td>
                            <td>{{ $item->nama_layanan }}</td>
                            <td><i class="fas fa-star text-warning"></i> {{ number_format((float) $item->rating, 1) }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($item->tgl_masuk)->format('d/m/Y') }}</td>
                            <td><span class="badge badge-info">{{ $item->status }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted p-4">Tidak ada karyawan Lapangan yang sedang tersedia.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">
            <span class="text-muted">Menampilkan {{ $pool->firstItem() ?? 0 }}&ndash;{{ $pool->lastItem() ?? 0 }} dari {{ $pool->total() }} karyawan tersedia</span>
            <div class="float-right">
                @include('partials.pagination', ['paginator' => $pool])
            </div>
        </div>
    </div>
@endsection

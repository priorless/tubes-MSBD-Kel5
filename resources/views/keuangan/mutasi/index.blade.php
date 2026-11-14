@extends('layouts.adminlte')

@section('title', 'Mutasi Kas')
@section('modul', 'Keuangan')
@section('heading', 'Mutasi Kas')
@section('breadcrumb', 'Mutasi Kas')

@section('content')
    <div class="row">
        <div class="col-md-4">
            <div class="small-box bg-success">
                <div class="inner">
                    <h4>Rp {{ number_format((float) ($ringkasan->total_masuk ?? 0), 0, ',', '.') }}</h4>
                    <p>Total Pemasukan</p>
                </div>
                <div class="icon"><i class="fas fa-arrow-down"></i></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="small-box bg-danger">
                <div class="inner">
                    <h4>Rp {{ number_format((float) ($ringkasan->total_keluar ?? 0), 0, ',', '.') }}</h4>
                    <p>Total Pengeluaran</p>
                </div>
                <div class="icon"><i class="fas fa-arrow-up"></i></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="small-box bg-info">
                <div class="inner">
                    <h4>Rp {{ number_format((float) ($ringkasan->selisih ?? 0), 0, ',', '.') }}</h4>
                    <p>Selisih Arus Kas</p>
                </div>
                <div class="icon"><i class="fas fa-balance-scale"></i></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Mutasi periode {{ $daftarBulan[$bulan] }} {{ $tahun }}</h3>
                    <div class="card-tools">
                        <form method="GET" action="{{ route('keuangan.mutasi.index') }}" class="form-inline">
                            <label for="bulan" class="sr-only">Bulan</label>
                            <select name="bulan" id="bulan" class="form-control form-control-sm mr-1">
                                @foreach ($daftarBulan as $angka => $nama)
                                    <option value="{{ $angka }}" @selected($angka === $bulan)>{{ $nama }}</option>
                                @endforeach
                            </select>
                            <label for="tahun" class="sr-only">Tahun</label>
                            <input type="number" name="tahun" id="tahun" value="{{ $tahun }}" min="2000" max="2100"
                                   class="form-control form-control-sm mr-1" style="width: 90px;">
                            <label for="jenis" class="sr-only">Jenis</label>
                            <select name="jenis" id="jenis" class="form-control form-control-sm mr-1">
                                <option value="">Semua jenis</option>
                                <option value="Pemasukan" @selected($jenis === 'Pemasukan')>Pemasukan</option>
                                <option value="Pengeluaran" @selected($jenis === 'Pengeluaran')>Pengeluaran</option>
                            </select>
                            <button type="submit" class="btn btn-default btn-sm"><i class="fas fa-filter mr-1"></i> Tampilkan</button>
                        </form>
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover text-nowrap">
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
                                    <td>{{ \Illuminate\Support\Carbon::parse($item->tgl_mutasi)->format('d/m/Y') }}</td>
                                    <td>
                                        @if ($item->jenis === 'Pemasukan')
                                            <span class="badge badge-success">Pemasukan</span>
                                        @else
                                            <span class="badge badge-danger">Pengeluaran</span>
                                        @endif
                                    </td>
                                    <td>{{ $item->referensi ?? '-' }}</td>
                                    <td>{{ $item->keterangan ?? '-' }}</td>
                                    <td @class(['text-right', 'text-success' => $item->jenis === 'Pemasukan', 'text-danger' => $item->jenis !== 'Pemasukan'])>
                                        {{ $item->jenis === 'Pemasukan' ? '+' : '-' }} Rp {{ number_format((float) $item->nominal, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted p-4">Belum ada mutasi kas pada periode ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer clearfix">
                    <span class="text-muted">Menampilkan {{ $mutasi->firstItem() ?? 0 }}&ndash;{{ $mutasi->lastItem() ?? 0 }} dari {{ $mutasi->total() }} mutasi</span>
                    <div class="float-right">
                        @include('partials.pagination', ['paginator' => $mutasi])
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

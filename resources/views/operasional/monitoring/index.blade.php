@extends('layouts.adminlte')

@section('title', 'Monitoring Sebaran Operasional')
@section('heading', 'Monitoring Sebaran Operasional')
@section('breadcrumb', 'Monitoring')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="callout callout-info">
                <h5><i class="fas fa-info-circle mr-1"></i> Pemantauan penempatan aktif</h5>
                <p class="mb-0">Baris berwarna merah menandakan sisa kontrak kurang dari 30 hari.</p>
            </div>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Sebaran karyawan aktif</h3>
                    <div class="card-tools">
                        <a href="{{ route('operasional.penempatan.create') }}" class="btn btn-primary btn-sm">
                            <i class="fas fa-user-plus mr-1"></i> Buat Penempatan
                        </a>
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover text-nowrap">
                        <thead>
                            <tr>
                                <th>NIP</th>
                                <th>Nama Karyawan</th>
                                <th>Jenis Layanan</th>
                                <th>Klien</th>
                                <th>Lokasi</th>
                                <th>Periode</th>
                                <th>Sisa Kontrak</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($penempatan as $item)
                                <tr @class(['table-danger' => $item->sisa_hari_kontrak < 30])>
                                    <td>{{ $item->nip }}</td>
                                    <td>{{ $item->nama }}</td>
                                    <td>{{ $item->nama_layanan }}</td>
                                    <td>{{ $item->nama_perusahaan }}</td>
                                    <td>{{ $item->lokasi_kerja }}</td>
                                    <td>
                                        {{ \Illuminate\Support\Carbon::parse($item->tgl_mulai)->format('d/m/Y') }}
                                        &ndash;
                                        {{ $item->tgl_selesai ? \Illuminate\Support\Carbon::parse($item->tgl_selesai)->format('d/m/Y') : 'Tanpa batas akhir' }}
                                    </td>
                                    <td>
                                        @if ((int) $item->sisa_hari_kontrak === 9999)
                                            <span class="badge badge-secondary">Tanpa batas akhir</span>
                                        @elseif ($item->sisa_hari_kontrak < 30)
                                            <span class="badge badge-danger">{{ $item->sisa_hari_kontrak }} hari</span>
                                        @else
                                            <span class="badge badge-success">{{ $item->sisa_hari_kontrak }} hari</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted p-4">Belum ada penempatan aktif.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer clearfix">
                    <span class="text-muted">Menampilkan {{ $penempatan->firstItem() ?? 0 }}&ndash;{{ $penempatan->lastItem() ?? 0 }} dari {{ $penempatan->total() }} penempatan</span>
                    <div class="float-right">
                        @include('partials.pagination', ['paginator' => $penempatan])
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

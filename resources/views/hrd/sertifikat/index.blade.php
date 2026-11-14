@extends('layouts.adminlte')

@section('title', 'Dokumen Sertifikat')
@section('modul', 'HRD')
@section('heading', 'Dokumen Sertifikat')
@section('breadcrumb', 'Dokumen Sertifikat')

@section('content')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Masa berlaku dokumen karyawan</h3>
            <div class="card-tools">
                <a href="{{ route('hrd.sertifikat.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus mr-1"></i> Tambah Dokumen
                </a>
            </div>
        </div>
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('hrd.sertifikat.index') }}" class="form-row align-items-center">
                <div class="col-md-6 mb-2">
                    <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Cari nama karyawan, NIP, atau nama dokumen" aria-label="Cari dokumen">
                </div>
                <div class="col-md-4 mb-2">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="hampir_habis" name="hampir_habis" value="1" @checked(request()->boolean('hampir_habis'))>
                        <label class="custom-control-label" for="hampir_habis">Hanya sisa &lt; {{ $batasHari }} hari</label>
                    </div>
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
                        <th>Karyawan</th>
                        <th>Dokumen</th>
                        <th>Tgl Terbit</th>
                        <th>Tgl Kedaluwarsa</th>
                        <th>Sisa Hari</th>
                        <th>Berkas</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sertifikat as $item)
                        <tr>
                            <td>
                                <strong>{{ $item->nama }}</strong>
                                <div class="text-muted small">NIP {{ $item->nip }}</div>
                            </td>
                            <td>
                                {{ $item->nama_dokumen }}
                                @if ($item->nomor_dokumen)
                                    <div class="text-muted small">No. {{ $item->nomor_dokumen }}</div>
                                @endif
                            </td>
                            <td>{{ \Illuminate\Support\Carbon::parse($item->tgl_terbit)->format('d/m/Y') }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($item->tgl_kadaluarsa)->format('d/m/Y') }}</td>
                            <td>
                                @if ((int) $item->sisa_hari === 0)
                                    <span class="badge badge-danger">Kedaluwarsa</span>
                                @else
                                    <span class="badge badge-{{ $item->sisa_hari < $batasHari ? 'danger' : 'success' }}">{{ $item->sisa_hari }} hari</span>
                                @endif
                            </td>
                            <td>
                                @if ($item->file_upload)
                                    <a href="{{ asset('storage/'.$item->file_upload) }}" target="_blank" rel="noopener"><i class="fas fa-file-alt mr-1"></i> Lihat</a>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <form action="{{ route('hrd.sertifikat.destroy', $item->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Hapus dokumen ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted p-4">Belum ada dokumen sertifikat.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">
            <span class="text-muted">
                Menampilkan {{ $sertifikat->firstItem() ?? 0 }}&ndash;{{ $sertifikat->lastItem() ?? 0 }} dari {{ $sertifikat->total() }} dokumen.
                <span class="badge badge-danger">merah</span> = sisa kurang dari {{ $batasHari }} hari.
            </span>
            <div class="float-right">
                @include('partials.pagination', ['paginator' => $sertifikat])
            </div>
        </div>
    </div>
@endsection

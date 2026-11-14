@extends('layouts.adminlte')

@section('title', 'Data Klien')
@section('heading', 'Data Klien')
@section('breadcrumb', 'Data Klien')

@section('content')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Daftar klien SJI</h3>
            <div class="card-tools">
                <a href="{{ route('operasional.klien.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus mr-1"></i> Tambah Klien
                </a>
            </div>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-hover text-nowrap">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama Perusahaan</th>
                        <th>PIC</th>
                        <th>Kontak</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($klien as $item)
                        <tr>
                            <td>{{ $item->kode_klien }}</td>
                            <td>
                                <strong>{{ $item->nama_perusahaan }}</strong>
                                <div class="text-muted small">{{ $item->alamat }}</div>
                            </td>
                            <td>
                                {{ $item->pic_nama }}
                                @if ($item->pic_jabatan)
                                    <div class="text-muted small">{{ $item->pic_jabatan }}</div>
                                @endif
                            </td>
                            <td>
                                {{ $item->pic_telp }}
                                @if ($item->pic_email)
                                    <div class="text-muted small">{{ $item->pic_email }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-{{ $item->status === 'Aktif' ? 'success' : 'secondary' }}">
                                    {{ $item->status }}
                                </span>
                            </td>
                            <td class="text-right">
                                <a href="{{ route('operasional.klien.edit', $item->id) }}" class="btn btn-outline-primary btn-sm">Ubah</a>
                                <form action="{{ route('operasional.klien.destroy', $item->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Hapus data klien ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted p-4">Belum ada data klien.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">
            <span class="text-muted">Menampilkan {{ $klien->firstItem() ?? 0 }}&ndash;{{ $klien->lastItem() ?? 0 }} dari {{ $klien->total() }} klien</span>
            <div class="float-right">
                @include('partials.pagination', ['paginator' => $klien])
            </div>
        </div>
    </div>
@endsection

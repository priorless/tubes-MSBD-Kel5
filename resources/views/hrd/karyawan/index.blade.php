@extends('layouts.adminlte')

@php
    $warnaStatus = ['Tersedia' => 'info', 'Ditempatkan' => 'primary', 'Aktif' => 'success', 'Cuti' => 'warning', 'Nonaktif' => 'secondary'];
@endphp

@section('title', 'Data Karyawan')
@section('modul', 'HRD')
@section('heading', 'Data Karyawan')
@section('breadcrumb', 'Data Karyawan')

@section('content')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Daftar karyawan SJI</h3>
            <div class="card-tools">
                <a href="{{ route('hrd.karyawan.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus mr-1"></i> Tambah Karyawan
                </a>
            </div>
        </div>
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('hrd.karyawan.index') }}" class="form-row">
                <div class="col-md-5 mb-2">
                    <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Cari nama atau NIP" aria-label="Cari nama atau NIP">
                </div>
                <div class="col-md-3 mb-2">
                    <select name="kategori" class="form-control form-control-sm" aria-label="Filter kategori">
                        <option value="">Semua kategori</option>
                        @foreach (['Lapangan', 'Internal'] as $kategori)
                            <option value="{{ $kategori }}" @selected(request('kategori') === $kategori)>{{ $kategori }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <select name="status" class="form-control form-control-sm" aria-label="Filter status">
                        <option value="">Semua status</option>
                        @foreach (array_keys($warnaStatus) as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
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
                        <th>Karyawan</th>
                        <th>Kategori</th>
                        <th>Layanan / Gaji Pokok</th>
                        <th>Kontak</th>
                        <th>Status</th>
                        <th>Rating</th>
                        <th>Akun Login</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($karyawan as $item)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    @if ($item->foto)
                                        <img src="{{ asset('storage/'.$item->foto) }}" alt="Foto {{ $item->nama }}" class="img-circle mr-2" width="36" height="36" style="object-fit: cover;">
                                    @else
                                        <span class="img-circle bg-secondary d-inline-flex align-items-center justify-content-center mr-2" style="width:36px;height:36px;">
                                            <i class="fas fa-user"></i>
                                        </span>
                                    @endif
                                    <div>
                                        <strong>{{ $item->nama }}</strong>
                                        <div class="text-muted small">NIP {{ $item->nip }}</div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge badge-{{ $item->kategori === 'Internal' ? 'dark' : 'info' }}">{{ $item->kategori }}</span></td>
                            <td>
                                @if ($item->kategori === 'Lapangan')
                                    {{ $item->nama_layanan ?? '—' }}
                                @else
                                    Rp {{ number_format((float) $item->gaji_pokok, 0, ',', '.') }}
                                @endif
                            </td>
                            <td>
                                {{ $item->no_telp }}
                                @if ($item->email)
                                    <div class="text-muted small">{{ $item->email }}</div>
                                @endif
                            </td>
                            <td><span class="badge badge-{{ $warnaStatus[$item->status] ?? 'secondary' }}">{{ $item->status }}</span></td>
                            <td><i class="fas fa-star text-warning"></i> {{ number_format((float) $item->rating, 1) }}</td>
                            <td>{!! $item->username ? e($item->username) : '<span class="text-muted">&mdash;</span>' !!}</td>
                            <td class="text-right">
                                <a href="{{ route('hrd.karyawan.edit', $item->id) }}" class="btn btn-outline-primary btn-sm">Ubah</a>
                                <form action="{{ route('hrd.karyawan.destroy', $item->id) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm(@js('Hapus karyawan '.$item->nama.' beserta akun loginnya?'))">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted p-4">Belum ada data karyawan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer clearfix">
            <span class="text-muted">Menampilkan {{ $karyawan->firstItem() ?? 0 }}&ndash;{{ $karyawan->lastItem() ?? 0 }} dari {{ $karyawan->total() }} karyawan</span>
            <div class="float-right">
                @include('partials.pagination', ['paginator' => $karyawan])
            </div>
        </div>
    </div>
@endsection

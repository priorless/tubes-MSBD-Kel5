@extends('layouts.app')

@section('title', 'Data Klien')

@php
    $jumlahSemua = $jumlahPerStatus->sum();
    $sedangMenyaring = $kataKunci !== '' || $status !== null;
@endphp

@section('content')
    <x-ui.page-header title="Data Klien"
        description="Perusahaan mitra PT Satu Jaya Indonesia.">
        <a href="{{ route($ruteKlien.'.create') }}" class="btn-primary">+ Tambah Klien</a>
    </x-ui.page-header>

    <div class="card">
        <form method="GET" action="{{ route($ruteKlien.'.index') }}" class="card-body flex flex-col gap-4 border-b border-gray-200 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex flex-1 flex-wrap items-end gap-2">
                <div class="w-full max-w-md">
                    <label for="q" class="form-label">Cari klien</label>
                    <input id="q" name="q" type="search" class="form-input" value="{{ $kataKunci }}" placeholder="Nama perusahaan atau kode klien">
                </div>
                @if ($status !== null)
                    <input type="hidden" name="status" value="{{ $status }}">
                @endif
                <button type="submit" class="btn-secondary">Cari</button>
                @if ($sedangMenyaring)
                    <a href="{{ route($ruteKlien.'.index') }}" class="btn-secondary">Hapus filter</a>
                @endif
            </div>
            <nav class="flex flex-wrap gap-2" aria-label="Filter status klien">
                @foreach (['' => 'Semua', 'Aktif' => 'Aktif', 'Nonaktif' => 'Nonaktif'] as $nilai => $label)
                    @php $aktif = ($status ?? '') === $nilai; @endphp
                    <a href="{{ route($ruteKlien.'.index', array_filter(['q' => $kataKunci, 'status' => $nilai])) }}"
                       @class(['btn-sm', 'btn-primary' => $aktif, 'btn-secondary' => ! $aktif]) @if ($aktif) aria-current="page" @endif>
                        {{ $label }} ({{ $nilai === '' ? $jumlahSemua : ($jumlahPerStatus[$nilai] ?? 0) }})
                    </a>
                @endforeach
            </nav>
        </form>

        @if ($klien->isEmpty())
            <div class="card-body">
                @if ($sedangMenyaring)
                    <x-ui.empty-state title="Tidak ada klien yang cocok" message="Coba kata pencarian lain atau hapus filter.">
                        <a href="{{ route($ruteKlien.'.index') }}" class="btn-secondary btn-sm">Tampilkan semua klien</a>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state title="Belum ada klien" message="Tambahkan perusahaan mitra pertama untuk mulai membuat penempatan karyawan.">
                        <a href="{{ route($ruteKlien.'.create') }}" class="btn-primary btn-sm">+ Tambah Klien</a>
                    </x-ui.empty-state>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Perusahaan</th>
                            <th>Kontak</th>
                            <th>Jenis Layanan</th>
                            <th>Karyawan Aktif</th>
                            <th>Status</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($klien as $item)
                            @php
                                $layanan = $layananPerKlien->get($item->id, collect());
                                $jumlahKaryawan = $layanan->sum('jumlah');
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route($ruteKlien.'.show', $item->id) }}" class="font-medium text-sji-biru hover:underline">{{ $item->nama_perusahaan }}</a>
                                    <p class="text-xs text-gray-400">{{ $item->kode_klien }} &middot; {{ \Illuminate\Support\Str::limit($item->alamat, 60) }}</p>
                                </td>
                                <td>
                                    {{ $item->pic_nama }}
                                    <p class="text-xs text-gray-400">{{ $item->pic_telp }}@if ($item->pic_email) &middot; {{ $item->pic_email }}@endif</p>
                                </td>
                                <td>
                                    @forelse ($layanan as $baris)
                                        <span class="badge badge-info">{{ $baris->nama_layanan ?? 'Tanpa layanan' }}</span>
                                    @empty
                                        <span class="text-xs text-gray-400">&mdash;</span>
                                    @endforelse
                                </td>
                                <td class="tabular-nums">{{ $jumlahKaryawan }} orang</td>
                                <td><span class="badge badge-{{ $item->status === 'Aktif' ? 'success' : 'secondary' }}">{{ $item->status }}</span></td>
                                <td>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route($ruteKlien.'.show', $item->id) }}" class="btn-secondary btn-sm">Detail</a>
                                        <a href="{{ route($ruteKlien.'.edit', $item->id) }}" class="btn-secondary btn-sm">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer">
                <span>Menampilkan {{ $klien->firstItem() }}&ndash;{{ $klien->lastItem() }} dari {{ $klien->total() }} klien</span>
                @include('partials.pagination', ['paginator' => $klien])
            </div>
        @endif
    </div>
@endsection

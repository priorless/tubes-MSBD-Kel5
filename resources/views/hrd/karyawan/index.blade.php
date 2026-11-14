@extends('layouts.app')

@php
    $warnaStatus = ['Tersedia' => 'info', 'Ditempatkan' => 'primary', 'Aktif' => 'success', 'Cuti' => 'warning', 'Nonaktif' => 'secondary'];
@endphp

@section('title', 'Data Karyawan')

@section('content')
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Daftar karyawan SJI</h2>
            <a href="{{ route('hrd.karyawan.create') }}" class="btn-primary btn-sm">+ Tambah Karyawan</a>
        </div>

        <form method="GET" action="{{ route('hrd.karyawan.index') }}" class="grid grid-cols-1 gap-3 border-b border-gray-200 bg-gray-50 px-6 py-4 md:grid-cols-12">
            <input type="search" name="q" value="{{ request('q') }}" class="form-input md:col-span-5" placeholder="Cari nama atau NIP" aria-label="Cari nama atau NIP">
            <select name="kategori" class="form-input md:col-span-3" aria-label="Filter kategori">
                <option value="">Semua kategori</option>
                @foreach (['Lapangan', 'Internal'] as $kategori)
                    <option value="{{ $kategori }}" @selected(request('kategori') === $kategori)>{{ $kategori }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input md:col-span-2" aria-label="Filter status">
                <option value="">Semua status</option>
                @foreach (array_keys($warnaStatus) as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary md:col-span-2">Terapkan</button>
        </form>

        <div class="overflow-x-auto">
            <table class="table">
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
                                <div class="flex items-center gap-3">
                                    @if ($item->foto)
                                        <img src="{{ asset('storage/'.$item->foto) }}" alt="Foto {{ $item->nama }}" class="size-9 rounded-full object-cover">
                                    @else
                                        <span class="flex size-9 items-center justify-center rounded-full bg-gray-200 text-sm font-semibold text-gray-500">{{ mb_substr($item->nama, 0, 1) }}</span>
                                    @endif
                                    <div>
                                        <p class="font-medium whitespace-nowrap text-gray-800">{{ $item->nama }}</p>
                                        <p class="text-xs text-gray-400">NIP {{ $item->nip }}</p>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge badge-{{ $item->kategori === 'Internal' ? 'dark' : 'info' }}">{{ $item->kategori }}</span></td>
                            <td class="whitespace-nowrap">
                                @if ($item->kategori === 'Lapangan')
                                    {{ $item->nama_layanan ?? '—' }}
                                @else
                                    Rp {{ number_format((float) $item->gaji_pokok, 0, ',', '.') }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $item->no_telp }}
                                @if ($item->email)
                                    <p class="text-xs text-gray-400">{{ $item->email }}</p>
                                @endif
                            </td>
                            <td><span class="badge badge-{{ $warnaStatus[$item->status] ?? 'secondary' }}">{{ $item->status }}</span></td>
                            <td class="whitespace-nowrap text-amber-600">&#9733; {{ number_format((float) $item->rating, 1) }}</td>
                            <td>
                                @if ($item->username)
                                    <span class="font-mono text-gray-600">{{ $item->username }}</span>
                                @else
                                    <span class="text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('hrd.karyawan.edit', $item->id) }}" class="btn-secondary btn-sm">Ubah</a>
                                    <form action="{{ route('hrd.karyawan.destroy', $item->id) }}" method="POST"
                                          onsubmit="return confirm(@js('Hapus karyawan '.$item->nama.' beserta akun loginnya?'))">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="table-empty">Belum ada data karyawan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $karyawan->firstItem() ?? 0 }}&ndash;{{ $karyawan->lastItem() ?? 0 }} dari {{ $karyawan->total() }} karyawan</span>
            @include('partials.pagination', ['paginator' => $karyawan])
        </div>
    </div>
@endsection

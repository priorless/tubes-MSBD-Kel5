@extends('layouts.app')

@section('title', 'Data Klien')

@section('content')
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Daftar klien SJI</h2>
            <a href="{{ route('operasional.klien.create') }}" class="btn-primary btn-sm">+ Tambah Klien</a>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
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
                            <td class="font-mono text-gray-600">{{ $item->kode_klien }}</td>
                            <td>
                                <p class="font-medium text-gray-800">{{ $item->nama_perusahaan }}</p>
                                <p class="text-xs text-gray-400">{{ $item->alamat }}</p>
                            </td>
                            <td>
                                {{ $item->pic_nama }}
                                @if ($item->pic_jabatan)
                                    <p class="text-xs text-gray-400">{{ $item->pic_jabatan }}</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $item->pic_telp }}
                                @if ($item->pic_email)
                                    <p class="text-xs text-gray-400">{{ $item->pic_email }}</p>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-{{ $item->status === 'Aktif' ? 'success' : 'secondary' }}">{{ $item->status }}</span>
                            </td>
                            <td>
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('operasional.klien.edit', $item->id) }}" class="btn-secondary btn-sm">Ubah</a>
                                    <form action="{{ route('operasional.klien.destroy', $item->id) }}" method="POST" onsubmit="return confirm('Hapus data klien ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-outline-danger btn-sm">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="table-empty">Belum ada data klien.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $klien->firstItem() ?? 0 }}&ndash;{{ $klien->lastItem() ?? 0 }} dari {{ $klien->total() }} klien</span>
            @include('partials.pagination', ['paginator' => $klien])
        </div>
    </div>
@endsection

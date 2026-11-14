@extends('layouts.app')

@section('title', 'Dokumen Sertifikat')

@section('content')
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Masa berlaku dokumen karyawan</h2>
            <a href="{{ route('hrd.sertifikat.create') }}" class="btn-primary btn-sm">+ Tambah Dokumen</a>
        </div>

        <form method="GET" action="{{ route('hrd.sertifikat.index') }}" class="grid grid-cols-1 items-center gap-3 border-b border-gray-200 bg-gray-50 px-6 py-4 md:grid-cols-12">
            <input type="search" name="q" value="{{ request('q') }}" class="form-input md:col-span-6" placeholder="Cari nama karyawan, NIP, atau nama dokumen" aria-label="Cari dokumen">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700 md:col-span-4">
                <input type="checkbox" class="form-check" name="hampir_habis" value="1" @checked(request()->boolean('hampir_habis'))>
                Hanya sisa &lt; {{ $batasHari }} hari
            </label>
            <button type="submit" class="btn-secondary md:col-span-2">Terapkan</button>
        </form>

        <div class="overflow-x-auto">
            <table class="table">
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
                                <p class="font-medium text-gray-800">{{ $item->nama }}</p>
                                <p class="text-xs text-gray-400">NIP {{ $item->nip }}</p>
                            </td>
                            <td>
                                {{ $item->nama_dokumen }}
                                @if ($item->nomor_dokumen)
                                    <p class="text-xs text-gray-400">No. {{ $item->nomor_dokumen }}</p>
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
                                    <a href="{{ asset('storage/'.$item->file_upload) }}" target="_blank" rel="noopener" class="font-medium text-sji-biru hover:underline">Lihat</a>
                                @else
                                    <span class="text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <form action="{{ route('hrd.sertifikat.destroy', $item->id) }}" method="POST" class="inline" onsubmit="return confirm('Hapus dokumen ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-outline-danger btn-sm">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="table-empty">Belum ada dokumen sertifikat.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>
                Menampilkan {{ $sertifikat->firstItem() ?? 0 }}&ndash;{{ $sertifikat->lastItem() ?? 0 }} dari {{ $sertifikat->total() }} dokumen.
                <span class="badge badge-danger">merah</span> = sisa kurang dari {{ $batasHari }} hari.
            </span>
            @include('partials.pagination', ['paginator' => $sertifikat])
        </div>
    </div>
@endsection

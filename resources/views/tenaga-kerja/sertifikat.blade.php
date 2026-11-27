@extends('layouts.tenaga-kerja')

@section('title', 'Sertifikat Saya')

@php
    $tanggal = static fn (string $tgl): string => \Carbon\Carbon::parse($tgl)->locale('id')->translatedFormat('d F Y');
@endphp

@section('content')
<div class="space-y-6">
    <section class="card p-6">
        <h2 class="card-title text-lg font-bold text-gray-900">Sertifikat Saya</h2>
        <p class="mt-1 text-sm text-gray-500">Dokumen dan sertifikat Anda yang dicatat HRD. Hubungi HRD untuk menambah atau memperbarui sertifikat.</p>

        @if ($sertifikat->isEmpty())
            <x-ui.empty-state title="Belum ada sertifikat." message="Sertifikat yang dicatat HRD akan tampil di sini." />
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Dokumen</th><th>Terbit</th><th>Berlaku sampai</th><th>Status</th><th>Berkas</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($sertifikat as $item)
                            <tr>
                                <td>
                                    <p class="font-medium text-gray-800">{{ $item->nama_dokumen }}</p>
                                    @if ($item->nomor_dokumen)
                                        <p class="text-xs text-gray-400">No. {{ $item->nomor_dokumen }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">{{ $tanggal($item->tgl_terbit) }}</td>
                                <td class="whitespace-nowrap">{{ $tanggal($item->tgl_kadaluarsa) }}</td>
                                <td>
                                    @if ($item->status === 'Kadaluarsa')
                                        <span class="badge badge-danger">Kedaluwarsa</span>
                                    @elseif ((int) $item->sisa_hari === 0)
                                        <span class="badge badge-danger">Berakhir hari ini</span>
                                    @else
                                        <span class="badge badge-{{ $item->sisa_hari < $batasHari ? 'warning' : 'success' }}">Aktif &middot; {{ $item->sisa_hari }} hari lagi</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($item->file_upload)
                                        <a href="{{ route('sertifikat.berkas', $item->id) }}" target="_blank" rel="noopener" class="font-medium text-sji-biru hover:underline">Lihat</a>
                                    @else
                                        <span class="text-gray-400">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">Sertifikat yang berakhir kurang dari {{ $batasHari }} hari lagi ditandai kuning. Segera hubungi HRD untuk perpanjangan.</p>
        @endif
    </section>
</div>
@endsection

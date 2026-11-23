@extends('layouts.app')

@section('title', 'Detail Pola Jadwal')

@php
    $jumlahKerja = collect($pola)->where('status', 'Kerja')->count();
    $jumlahLibur = collect($pola)->where('status', 'Libur')->count();
@endphp

@section('content')
    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">{{ $template->nama_template }}</h2>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-500">
                        <span class="badge badge-{{ $template->jenis === 'Pribadi' ? 'info' : 'primary' }}">Pola {{ $template->jenis }}</span>
                        <span class="badge badge-{{ $template->status === 'Aktif' ? 'success' : 'secondary' }}">{{ $template->status }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('operasional.jadwal-kerja.template-jadwal.index') }}" class="btn-secondary btn-sm">Daftar Pola</a>
                    @if ($template->status === 'Aktif')
                        <a href="{{ route('operasional.jadwal-kerja.template-jadwal.edit', $template->id) }}" class="btn-secondary btn-sm">Edit Pola</a>
                        <a href="{{ route('operasional.jadwal-kerja.template-jadwal.apply.preview', $template->id) }}" class="btn-primary btn-sm">Gunakan Pola</a>
                    @endif
                </div>
            </div>

            <div class="card-body grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                <div>
                    <p class="text-gray-500">Berlaku untuk</p>
                    @if ($template->jenis === 'Pribadi')
                        <p class="font-medium text-gray-800">{{ $template->nip }} &mdash; {{ $template->nama_karyawan }}</p>
                    @else
                        <p class="font-medium text-gray-800">{{ $template->nama_perusahaan }}</p>
                        <p class="text-gray-500">{{ $template->nama_layanan ?? 'Semua jenis layanan' }}</p>
                    @endif
                </div>
                <div>
                    <p class="text-gray-500">Ringkasan pola</p>
                    <p class="font-medium text-gray-800">{{ $jumlahKerja }} hari kerja, {{ $jumlahLibur }} libur, {{ 31 - $jumlahKerja - $jumlahLibur }} belum diatur</p>
                </div>
                <div>
                    <p class="text-gray-500">Keterangan</p>
                    <p class="text-gray-800">{{ $template->keterangan ?? '—' }}</p>
                </div>
            </div>

            @if ($template->status === 'Aktif')
                <div class="card-footer justify-start">
                    <form action="{{ route('operasional.jadwal-kerja.template-jadwal.deactivate', $template->id) }}" method="POST"
                          onsubmit="return confirm('Nonaktifkan pola ini? Jadwal yang sudah dibuat dari pola ini tidak berubah.')">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn-outline-danger btn-sm">Nonaktifkan Pola</button>
                    </form>
                </div>
            @endif
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Pola Hari ke-1 sampai 31</h3>
                    <p class="mt-1 text-sm text-gray-500">Dipetakan ke tanggal yang sama pada bulan tujuan; hari yang tidak ada pada bulan tujuan dilewati.</p>
                </div>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-4 gap-2 sm:grid-cols-7">
                    @for ($hari = 1; $hari <= 31; $hari++)
                        @php $nilai = $pola[$hari] ?? null; @endphp
                        <div class="min-h-20 rounded-lg border border-gray-200 p-2">
                            <span class="text-sm font-semibold text-gray-700">{{ $hari }}</span>
                            @if ($nilai === null)
                                <p class="mt-1 text-xs text-gray-400">Belum Diatur</p>
                            @elseif ($nilai['status'] === 'Libur')
                                <p class="mt-1"><span class="badge badge-secondary">Libur</span></p>
                            @else
                                <p class="mt-1"><span class="badge badge-success">Kerja</span></p>
                                <p class="text-xs text-gray-700">
                                    {{ $nilai['jam_masuk'] }}&ndash;{{ $nilai['jam_pulang'] }}
                                    @if ($nilai['jam_pulang'] <= $nilai['jam_masuk'])
                                        <span class="text-gray-500">(+1 hari)</span>
                                    @endif
                                </p>
                            @endif
                        </div>
                    @endfor
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Riwayat Pemakaian</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Karyawan</th>
                            <th>Bulan Tujuan</th>
                            <th>Jadwal lama</th>
                            <th>Hasil</th>
                            <th>Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($riwayatPenerapan as $item)
                            <tr>
                                <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($item->created_at)->format('d/m/Y H:i') }}</td>
                                <td>{{ $item->nip }} &mdash; {{ $item->nama_karyawan }}</td>
                                <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($item->bulan_tujuan)->locale('id')->translatedFormat('F Y') }}</td>
                                <td>{{ $item->mode_konflik === 'Timpa' ? 'Diganti' : 'Dipertahankan' }}</td>
                                <td class="whitespace-nowrap">{{ $item->jumlah_dibuat }} tanggal baru, {{ $item->jumlah_ditimpa }} diganti, {{ $item->jumlah_dilewati }} dilewati</td>
                                <td>{{ $item->nama_user ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="table-empty">Pola ini belum pernah dipakai.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

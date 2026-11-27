@extends('layouts.app')

@section('title', 'Koreksi Check-out')

@php
    $tanggal = static fn (string $tgl): string => \Carbon\Carbon::parse($tgl)->format('d/m/Y');
@endphp

@section('content')
    <x-ui.page-header title="Koreksi Check-out"
        description="Pengajuan karyawan yang lupa check-out. Jika disetujui, jam keluar absensi diisi dari pengajuan dan jam lembur dihitung ulang otomatis." />

    <form method="GET" action="{{ route('hrd.koreksi-check-out.index') }}" class="card card-body mb-6 grid grid-cols-1 items-end gap-4 md:grid-cols-[1fr_2fr_auto]">
        <div>
            <label for="status" class="form-label">Status</label>
            <select id="status" name="status" class="form-input">
                @foreach ($daftarStatus as $pilihan)
                    <option value="{{ $pilihan }}" @selected($status === $pilihan)>{{ $pilihan }}{{ $pilihan === 'Menunggu' ? ' ('.$jumlahMenunggu.')' : '' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="q" class="form-label">Cari karyawan</label>
            <input id="q" name="q" type="search" class="form-input" value="{{ $q }}" placeholder="Nama atau NIP...">
        </div>
        <button type="submit" class="btn-primary">Tampilkan</button>
    </form>

    @error('keputusan') <div class="callout callout-danger mb-6 text-sm">{{ $message }}</div> @enderror
    @error('catatan_keputusan') <div class="callout callout-danger mb-6 text-sm">{{ $message }}</div> @enderror

    @if ($pengajuan->isEmpty())
        <x-ui.empty-state title="Tidak ada pengajuan {{ strtolower($status) }}" message="Pengajuan koreksi check-out dari karyawan akan tampil di sini." />
    @else
        <section class="card">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Karyawan</th>
                            <th>Tanggal</th>
                            <th>Masuk &ndash; Keluar diajukan</th>
                            <th>Alasan</th>
                            <th>{{ $status === 'Menunggu' ? 'Keputusan' : 'Diproses' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pengajuan as $baris)
                            <tr class="align-top">
                                <td>
                                    <p class="font-medium">{{ $baris->nama }}</p>
                                    <p class="text-xs text-gray-400">{{ $baris->nip }}</p>
                                </td>
                                <td class="whitespace-nowrap">
                                    <a href="{{ route('hrd.absensi.detail', $baris->id_absensi) }}" class="text-sji-biru hover:underline">{{ $tanggal($baris->tgl) }}</a>
                                </td>
                                <td class="whitespace-nowrap tabular-nums">{{ substr($baris->jam_masuk, 0, 5) }} &ndash; {{ substr($baris->jam_keluar, 0, 5) }}</td>
                                <td class="max-w-sm">{{ $baris->alasan }}</td>
                                <td>
                                    @if ($baris->status === 'Menunggu')
                                        <div class="flex flex-col gap-2">
                                            <form method="POST" action="{{ route('hrd.koreksi-check-out.update', $baris->id) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="keputusan" value="Disetujui">
                                                <button type="submit" class="btn-primary btn-sm">Setujui</button>
                                            </form>
                                            <details>
                                                <summary class="btn-outline-danger btn-sm cursor-pointer list-none">Tolak&hellip;</summary>
                                                <form method="POST" action="{{ route('hrd.koreksi-check-out.update', $baris->id) }}" class="mt-2 flex gap-2">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="keputusan" value="Ditolak">
                                                    <input name="catatan_keputusan" type="text" maxlength="500" class="form-input py-1.5" placeholder="Alasan penolakan" required>
                                                    <button type="submit" class="btn-danger btn-sm">Tolak</button>
                                                </form>
                                            </details>
                                        </div>
                                    @else
                                        <span class="badge {{ $baris->status === 'Disetujui' ? 'badge-success' : 'badge-danger' }}">{{ $baris->status }}</span>
                                        <p class="mt-1 text-xs text-gray-500">{{ $baris->nama_pemroses }} &middot; {{ \Carbon\Carbon::parse($baris->diproses_pada)->format('d/m/Y H:i') }}</p>
                                        @if ($baris->catatan_keputusan)
                                            <p class="text-xs text-gray-500">{{ $baris->catatan_keputusan }}</p>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-4">{{ $pengajuan->links() }}</div>
    @endif
@endsection

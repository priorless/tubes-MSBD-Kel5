@extends('layouts.tenaga-kerja')

@section('title', 'Koreksi Check-out')

@php
    $tanggal = static fn (string $tgl): string => \Carbon\Carbon::parse($tgl)->locale('id')->translatedFormat('l, d/m/Y');
    $warna = ['Menunggu' => 'badge-warning', 'Disetujui' => 'badge-success', 'Ditolak' => 'badge-danger'];
@endphp

@section('content')
<div class="space-y-6">
    <section class="card p-6">
        <h2 class="card-title text-lg font-bold text-gray-900">Lupa check-out</h2>
        <p class="mt-1 text-sm text-gray-500">Absensi {{ \App\Http\Controllers\TenagaKerja\KoreksiCheckOutController::BATAS_HARI }} hari terakhir yang sudah check-in tetapi belum check-out. Ajukan jam keluar yang sebenarnya untuk disetujui HRD.</p>

        @error('id_absensi') <p class="form-error mt-3">{{ $message }}</p> @enderror

        @if ($lupaCheckOut->isEmpty())
            <x-ui.empty-state title="Tidak ada absensi yang lupa check-out." message="Semua absensi Anda sudah lengkap atau sudah diajukan koreksinya." />
        @else
            <div class="mt-4 space-y-4">
                @foreach ($lupaCheckOut as $absensi)
                    <form method="POST" action="{{ route('tenaga-kerja.koreksi-check-out.store') }}" class="rounded-lg border border-gray-200 p-4">
                        @csrf
                        <input type="hidden" name="id_absensi" value="{{ $absensi->id }}">
                        <p class="font-medium text-gray-800">{{ $tanggal($absensi->tgl) }}</p>
                        <p class="text-sm text-gray-500">Check-in {{ substr($absensi->jam_masuk, 0, 5) }} &middot; belum check-out</p>
                        @php $formIni = (int) old('id_absensi') === (int) $absensi->id; @endphp
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[10rem_1fr_auto] sm:items-end">
                            <div>
                                <label for="jam_keluar_{{ $absensi->id }}" class="form-label">Jam keluar</label>
                                <input id="jam_keluar_{{ $absensi->id }}" name="jam_keluar" type="time" class="form-input" required value="{{ $formIni ? old('jam_keluar') : '' }}">
                            </div>
                            <div>
                                <label for="alasan_{{ $absensi->id }}" class="form-label">Alasan</label>
                                <input id="alasan_{{ $absensi->id }}" name="alasan" type="text" maxlength="500" class="form-input" required value="{{ $formIni ? old('alasan') : '' }}" placeholder="Contoh: baterai HP habis saat pulang">
                            </div>
                            <button type="submit" class="btn-primary">Ajukan</button>
                        </div>
                        @if ($formIni)
                            @error('jam_keluar') <p class="form-error">{{ $message }}</p> @enderror
                            @error('alasan') <p class="form-error">{{ $message }}</p> @enderror
                        @endif
                    </form>
                @endforeach
            </div>
        @endif
    </section>

    <section class="card p-6">
        <h2 class="card-title text-lg font-bold text-gray-900">Riwayat pengajuan</h2>
        @if ($pengajuan->isEmpty())
            <x-ui.empty-state title="Belum ada pengajuan." message="Pengajuan koreksi check-out Anda akan tampil di sini." />
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Tanggal absensi</th><th>Jam</th><th>Alasan</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($pengajuan as $baris)
                            <tr>
                                <td class="whitespace-nowrap">{{ $tanggal($baris->tgl) }}</td>
                                <td class="whitespace-nowrap tabular-nums">{{ substr($baris->jam_masuk, 0, 5) }} &ndash; {{ substr($baris->jam_keluar, 0, 5) }}</td>
                                <td>{{ $baris->alasan }}</td>
                                <td>
                                    <span class="badge {{ $warna[$baris->status] ?? 'badge-secondary' }}">{{ $baris->status }}</span>
                                    @if ($baris->catatan_keputusan)
                                        <p class="mt-1 text-xs text-gray-500">{{ $baris->catatan_keputusan }}</p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection

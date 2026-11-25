@extends('layouts.app')

@section('title', 'Absensi Karyawan')

@use('App\Support\FormatKolom')

@section('content')
    <x-ui.page-header title="Absensi Karyawan" description="Pantau absensi harian karyawan lapangan dan rekap per periode." />

    @include('hrd.absensi._tab', ['tab' => $tab])

    <div class="space-y-6">
        <section class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Pengajuan Koreksi Check-out</h2>
                    <p class="mt-1 text-sm text-gray-500">Karyawan yang lupa check-out mengajukan jam keluar. Setelah disetujui, jam kerja dan lembur dihitung otomatis.</p>
                </div>
            </div>

            @if ($pengajuan->isEmpty())
                <p class="table-empty">Tidak ada pengajuan yang menunggu.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                <th>Tanggal</th>
                                <th>Masuk</th>
                                <th>Jam Keluar Diajukan</th>
                                <th>Alasan</th>
                                <th class="text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pengajuan as $item)
                                <tr>
                                    <td>{{ $item->nama }} <span class="block text-xs text-gray-500">{{ $item->nip }}</span></td>
                                    <td class="whitespace-nowrap">{{ FormatKolom::tanggal($item->tgl, 'D, d M Y') }}</td>
                                    <td class="font-mono">{{ FormatKolom::jam($item->jam_masuk) }}</td>
                                    <td class="font-mono font-semibold">{{ FormatKolom::jam($item->jam_keluar) }}</td>
                                    <td class="max-w-xs text-sm">{{ $item->alasan }}</td>
                                    <td>
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <form method="POST" action="{{ route('hrd.absensi.koreksi-check-out.approve', $item->id) }}"
                                                  onsubmit="return confirm('Setujui jam keluar {{ FormatKolom::jam($item->jam_keluar) }} untuk {{ $item->nama }}?')">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn-primary btn-sm">Setujui</button>
                                            </form>
                                            <form method="POST" action="{{ route('hrd.absensi.koreksi-check-out.reject', $item->id) }}" class="flex gap-1"
                                                  onsubmit="return confirm('Tolak pengajuan ini?')">
                                                @csrf
                                                @method('PATCH')
                                                <label for="catatan_{{ $item->id }}" class="sr-only">Catatan penolakan</label>
                                                <input id="catatan_{{ $item->id }}" name="catatan_keputusan" maxlength="500" class="form-input w-40" placeholder="Catatan (opsional)">
                                                <button type="submit" class="btn-outline-danger btn-sm">Tolak</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Absensi Lupa Check-out ({{ \App\Services\KoreksiCheckOutService::HARI_DAFTAR_HRD }} hari terakhir)</h2>
                    <p class="mt-1 text-sm text-gray-500">HRD dapat langsung mengisi jam keluar. Pengajuan karyawan yang masih menunggu pada absensi yang sama akan ditutup.</p>
                </div>
            </div>

            @if ($lupaCheckOut->isEmpty())
                <p class="table-empty">Tidak ada absensi yang lupa check-out.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                <th>Tanggal</th>
                                <th>Masuk</th>
                                <th>Isi Jam Keluar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lupaCheckOut as $absensi)
                                <tr>
                                    <td>{{ $absensi->nama }} <span class="block text-xs text-gray-500">{{ $absensi->nip }}</span></td>
                                    <td class="whitespace-nowrap">
                                        {{ FormatKolom::tanggal($absensi->tgl, 'D, d M Y') }}
                                        @if ($absensi->id_koreksi)
                                            <span class="block text-xs text-amber-700">Ada pengajuan karyawan</span>
                                        @endif
                                    </td>
                                    <td class="font-mono">{{ FormatKolom::jam($absensi->jam_masuk) }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('hrd.absensi.koreksi-check-out.store') }}" class="flex flex-wrap items-center gap-2"
                                              onsubmit="return confirm('Simpan jam keluar untuk {{ $absensi->nama }}?')">
                                            @csrf
                                            <input type="hidden" name="id_absensi" value="{{ $absensi->id }}">
                                            <label for="jam_{{ $absensi->id }}" class="sr-only">Jam keluar</label>
                                            <input type="time" id="jam_{{ $absensi->id }}" name="jam_keluar" required class="form-input w-32">
                                            <label for="alasan_hrd_{{ $absensi->id }}" class="sr-only">Alasan</label>
                                            <input id="alasan_hrd_{{ $absensi->id }}" name="alasan" minlength="5" maxlength="500" required class="form-input w-56" placeholder="Dasar pengisian">
                                            <button type="submit" class="btn-primary btn-sm">Simpan</button>
                                        </form>
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

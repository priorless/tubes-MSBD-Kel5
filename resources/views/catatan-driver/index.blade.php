@extends('layouts.app')

@section('title', 'Catatan Driver')

@section('content')
    <x-ui.page-header title="Catatan Driver"
        description="Semua catatan yang ditulis driver: laporan perjalanan, keterangan P2H saat check-in, dan kondisi akhir kendaraan saat check-out." />

    <form method="GET" action="{{ route($rute) }}" class="card card-body mb-6 grid grid-cols-1 items-end gap-4 md:grid-cols-[2fr_1fr_1fr_1fr_auto]">
        <div>
            <label for="q" class="form-label">Cari</label>
            <input id="q" name="q" type="search" class="form-input" value="{{ $filter['q'] ?? '' }}" placeholder="Nama, NIP, atau isi catatan...">
        </div>
        <div>
            <label for="sumber" class="form-label">Jenis catatan</label>
            <select id="sumber" name="sumber" class="form-input">
                <option value="">Semua jenis</option>
                @foreach ($daftarSumber as $nilai => $label)
                    <option value="{{ $nilai }}" @selected(($filter['sumber'] ?? null) === $nilai)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="tanggal_awal" class="form-label">Dari tanggal</label>
            <input id="tanggal_awal" name="tanggal_awal" type="date" class="form-input" value="{{ $filter['tanggal_awal'] ?? '' }}">
        </div>
        <div>
            <label for="tanggal_akhir" class="form-label">Sampai tanggal</label>
            <input id="tanggal_akhir" name="tanggal_akhir" type="date" class="form-input" value="{{ $filter['tanggal_akhir'] ?? '' }}">
            @error('tanggal_akhir') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn-primary">Tampilkan</button>
    </form>

    @if ($catatan->isEmpty())
        <x-ui.empty-state title="Belum ada catatan driver" message="Catatan muncul setelah driver mengisi laporan perjalanan atau P2H kendaraan." />
    @else
        <section class="card">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Driver</th>
                            <th>Jenis</th>
                            <th>Catatan</th>
                            <th>Kondisi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($catatan as $baris)
                            <tr>
                                <td class="whitespace-nowrap tabular-nums">{{ $baris->waktu ? \Carbon\Carbon::parse($baris->waktu)->format('d/m/Y H:i') : '-' }}</td>
                                <td>
                                    <p class="font-medium">{{ $baris->nama }}</p>
                                    <p class="text-xs text-gray-400">{{ $baris->nip }}{{ $baris->klien ? ' · '.$baris->klien : '' }}</p>
                                </td>
                                <td class="whitespace-nowrap">
                                    {{ $daftarSumber[$baris->sumber] }}
                                    @if ($baris->komponen)
                                        <p class="text-xs text-gray-400">{{ $komponenP2h[$baris->komponen] ?? $baris->komponen }}</p>
                                    @endif
                                </td>
                                <td class="max-w-md whitespace-pre-line">
                                    {{ $baris->catatan }}
                                    @if ($bisaLihatFoto && $baris->sumber === 'laporan')
                                        <a href="{{ route('absensi.laporan.foto', [$baris->id_laporan, 'utama']) }}" target="_blank" class="mt-1 block text-xs text-sji-biru hover:underline">Lihat foto</a>
                                    @elseif ($bisaLihatFoto && $baris->sumber === 'akhir')
                                        <a href="{{ route('absensi.p2h.foto', [$baris->id_absensi, 'akhir']) }}" target="_blank" class="mt-1 block text-xs text-sji-biru hover:underline">Lihat foto kendaraan</a>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($baris->kondisi)
                                        <span @class(['font-medium', 'text-green-600' => $baris->kondisi === 'Baik', 'text-amber-600' => $baris->kondisi === 'Perlu Perhatian', 'text-red-600' => $baris->kondisi === 'Rusak'])>{{ $baris->kondisi }}</span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-4">{{ $catatan->links() }}</div>
    @endif
@endsection

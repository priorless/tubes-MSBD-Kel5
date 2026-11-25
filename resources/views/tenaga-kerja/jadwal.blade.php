@extends('layouts.tenaga-kerja')

@section('title', 'Jadwal Saya')

@php
    $hariId = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $labelTanggal = static fn (string $tgl): string => $hariId[\Carbon\CarbonImmutable::parse($tgl)->dayOfWeek].', '.\Carbon\CarbonImmutable::parse($tgl)->format('d/m/Y');
    $warna = [
        'Kerja' => 'badge-success',
        'Backup' => 'badge-info',
        'Digantikan' => 'badge-warning',
        'Libur' => 'badge-secondary',
        'Belum Dijadwalkan' => 'badge-secondary',
        'Tanpa Penempatan' => 'badge-secondary',
    ];
    $labelShift = static function (?string $json): string {
        $data = $json === null ? null : json_decode($json, true);
        if (! is_array($data) || ($data['status'] ?? null) !== 'Kerja') {
            return 'Libur';
        }
        $jam = substr((string) $data['jam_masuk'], 0, 5).'–'.substr((string) $data['jam_pulang'], 0, 5);

        return filled($data['nama_shift_snapshot'] ?? null) ? $data['nama_shift_snapshot'].' · '.$jam : $jam;
    };
@endphp

@section('content')
<x-ui.page-header title="Jadwal Saya" description="Roster kerja Anda untuk 30 hari ke depan, termasuk hari libur dan perubahan karena backup shift." />

<div class="space-y-6">
    <section class="tk-reveal card p-6">
        <h2 class="card-title text-lg font-bold text-gray-900">Roster 30 Hari ke Depan</h2>

        @if ($roster === [])
            <x-ui.empty-state title="Roster belum tersedia." message="Jadwal akan tampil setelah Anda ditempatkan dan dijadwalkan Operasional." />
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Status</th>
                            <th>Shift</th>
                            <th>Lokasi</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roster as $hari)
                            <tr>
                                <td class="whitespace-nowrap">{{ $labelTanggal($hari['tanggal']) }}</td>
                                <td><span class="badge {{ $warna[$hari['status']] ?? 'badge-secondary' }}">{{ $hari['status'] }}</span></td>
                                <td class="whitespace-nowrap">{{ $hari['shift'] ?? '-' }}</td>
                                <td>{{ $hari['klien'] ?? '-' }}</td>
                                <td class="text-sm text-gray-600">
                                    @switch($hari['status'])
                                        @case('Backup') Menggantikan {{ $hari['rekan'] }} @break
                                        @case('Digantikan') Digantikan {{ $hari['rekan'] }}; Anda tidak perlu absen @break
                                        @case('Belum Dijadwalkan') Menunggu jadwal dari Operasional @break
                                        @default -
                                    @endswitch
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="tk-reveal card p-6">
        <h2 class="card-title text-lg font-bold text-gray-900">Riwayat Perubahan Jadwal (30 Hari Terakhir)</h2>

        @if ($perubahan->isEmpty())
            <x-ui.empty-state title="Belum ada perubahan jadwal." message="Perubahan oleh Operasional maupun karena backup shift akan tampil di sini." />
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Diubah Pada</th>
                            <th>Tanggal Shift</th>
                            <th>Sebelum</th>
                            <th>Sesudah</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($perubahan as $item)
                            @php
                                $sesudah = json_decode((string) $item->data_sesudah, true) ?: [];
                                $backup = isset($sesudah['sumber']['pertukaran_shift']);
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($item->created_at)->format('d/m/Y H:i') }}</td>
                                <td class="whitespace-nowrap">{{ $labelTanggal(substr((string) $item->tanggal_kerja, 0, 10)) }}</td>
                                <td class="whitespace-nowrap">{{ $labelShift($item->data_sebelum) }}</td>
                                <td class="whitespace-nowrap">{{ $labelShift($item->data_sesudah) }}</td>
                                <td class="text-sm text-gray-600">
                                    {{ $backup ? 'Backup shift disetujui' : 'Diubah Operasional' }}
                                    @if ((int) $item->id_pemilik !== (int) \App\Support\KaryawanAktif::id())
                                        <span class="text-xs text-gray-400">(jadwal {{ $item->nama_pemilik }})</span>
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

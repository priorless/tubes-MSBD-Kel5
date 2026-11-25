@extends('layouts.tenaga-kerja')

@section('title', 'Backup Shift')

@use('App\Services\PertukaranShiftService')

@php
    $idSaya = \App\Support\KaryawanAktif::id();
    $hariId = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    $labelTanggal = static fn (string $tgl): string => $hariId[\Carbon\CarbonImmutable::parse($tgl)->dayOfWeek].', '.\Carbon\CarbonImmutable::parse($tgl)->format('d/m/Y');
    $jadwalDipilih = collect($jadwalSaya)->firstWhere('id', $idJadwalDipilih);
    $nomor = static fn (int $n): string => '<span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">'.$n.'</span>';
@endphp

@section('content')
<x-ui.page-header title="Backup Shift" description="Minta rekan menggantikan shift Anda, tanggapi permintaan rekan, dan pantau statusnya." />

<div class="space-y-6">
    @if (! $tersedia)
        <section class="tk-reveal card p-6">
            <x-ui.empty-state title="Fitur backup shift belum tersedia."
                message="Hubungi bagian Operasional." />
        </section>
    @else
        {{-- ===================== Permintaan masuk (saya sebagai rekan) ===================== --}}
        @if ($permintaanMasuk->isNotEmpty())
            <section class="tk-reveal card border-l-4 border-amber-400 p-6" id="permintaan-masuk">
                <h2 class="card-title text-lg font-bold text-gray-900">Permintaan Backup untuk Anda</h2>
                <p class="mt-1.5 text-sm text-gray-500">
                    Rekan meminta Anda menggantikan shift-nya. Tanggapi paling lambat {{ PertukaranShiftService::JAM_BATAS_TANGGAPAN_REKAN }} jam
                    sebelum shift dimulai; setelah Anda menerima, pengajuan diteruskan ke Operasional.
                </p>

                <div class="mt-4 space-y-3">
                    @foreach ($permintaanMasuk as $item)
                        <div class="rounded-xl border border-gray-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-gray-900">{{ $item->nama_pengaju }} <span class="text-xs font-normal text-gray-400">{{ $item->nip_pengaju }}</span></p>
                                    <p class="text-sm text-gray-700">{{ $labelTanggal($item->tanggal_kerja) }} &mdash; {{ $layananTukar->labelShift($item->snapshot_pengaju) }}</p>
                                    <p class="text-sm text-gray-500">Lokasi: {{ $item->nama_perusahaan }}</p>
                                    <p class="mt-1 text-sm text-gray-600">Alasan: {{ $item->alasan }}</p>
                                    <p class="mt-1 text-xs text-amber-700">Batas tanggapan: {{ $layananTukar->batasTanggapanRekan($item)->format('d/m/Y H:i') }} WIB</p>
                                </div>
                                <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.tanggapi', $item->id) }}" class="flex w-full flex-col gap-2 sm:w-72">
                                    @csrf
                                    @method('PATCH')
                                    <label for="catatan_rekan_{{ $item->id }}" class="sr-only">Catatan (opsional)</label>
                                    <input id="catatan_rekan_{{ $item->id }}" name="catatan_rekan" maxlength="500" class="form-input" placeholder="Catatan (opsional)">
                                    <div class="flex gap-2">
                                        <button type="submit" name="keputusan" value="terima" class="btn-primary flex-1"
                                                onclick="return confirm('Terima permintaan backup shift ini?')">Terima</button>
                                        <button type="submit" name="keputusan" value="tolak" class="btn-outline-danger flex-1"
                                                onclick="return confirm('Tolak permintaan backup shift ini?')">Tolak</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ===================== Form pengajuan ===================== --}}
        <section class="tk-reveal card p-6" id="backup-shift">
            <h2 class="card-title text-lg font-bold text-gray-900">Minta Rekan Menggantikan Shift Anda</h2>
            <p class="mt-1.5 text-sm text-gray-500">
                Pilih jadwal Anda yang ingin digantikan, lalu pilih rekan pengganti. Rekan yang ditampilkan memiliki
                <b>jabatan yang sama</b> (boleh dari klien/lokasi lain) dan <b>tidak memiliki jadwal kerja</b> pada waktu shift tersebut.
                Ajukan paling lambat H-1. Rekan menanggapi lebih dulu, lalu Operasional memutuskan; shift baru berpindah ke rekan setelah disetujui.
            </p>

            <div class="mt-4">
                @if ($jadwalSaya === [])
                    <x-ui.empty-state title="Belum ada jadwal yang dapat dimintakan backup."
                        message="Backup shift tersedia untuk jadwal Kerja mulai besok yang sudah dijadwalkan Operasional dan belum memiliki pengajuan berjalan." />
                @else
                    <form method="GET" action="{{ route('tenaga-kerja.tukar-shift.index') }}" class="rounded-xl bg-gray-50 p-4">
                        <label for="id_jadwal_pilih" class="form-label">{!! $nomor(1) !!} Pilih jadwal Anda yang ingin digantikan</label>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <select id="id_jadwal_pilih" name="id_jadwal" class="form-input" required onchange="this.form.submit()">
                                <option value="">Pilih jadwal</option>
                                @foreach ($jadwalSaya as $pilihan)
                                    <option value="{{ $pilihan['id'] }}" @selected($idJadwalDipilih === $pilihan['id'])>
                                        {{ $labelTanggal($pilihan['tanggal']) }} &mdash; {{ $pilihan['shift'] }} ({{ $pilihan['klien'] }})
                                    </option>
                                @endforeach
                            </select>
                            <noscript><button type="submit" class="btn-secondary">Cari rekan</button></noscript>
                        </div>
                    </form>

                    @if ($jadwalDipilih !== null)
                        @if ($calonRekan === [])
                            <div class="mt-4">
                                <x-ui.empty-state title="Belum ada rekan yang tersedia."
                                    message="Tidak ada rekan dengan jabatan yang sama yang kosong (libur atau belum terjadwal) pada waktu shift ini." />
                            </div>
                        @else
                            <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.store') }}" class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2"
                                  onsubmit="return confirm('Kirim permintaan backup shift ke rekan yang dipilih?')">
                                @csrf
                                <input type="hidden" name="id_jadwal" value="{{ $jadwalDipilih['id'] }}">

                                <div class="rounded-xl bg-gray-50 p-4">
                                    <label for="id_karyawan_rekan" class="form-label">{!! $nomor(2) !!} Pilih rekan pengganti</label>
                                    <select id="id_karyawan_rekan" name="id_karyawan_rekan" class="form-input" required>
                                        <option value="">Pilih rekan</option>
                                        @foreach ($calonRekan as $rekan)
                                            <option value="{{ $rekan['id_karyawan'] }}" @selected((string) old('id_karyawan_rekan') === (string) $rekan['id_karyawan'])>
                                                {{ $rekan['nama'] }} ({{ $rekan['nip'] }}) &mdash; {{ $rekan['klien'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="form-hint">{{ count($calonRekan) }} rekan tersedia pada {{ $labelTanggal($jadwalDipilih['tanggal']) }}.</p>
                                </div>

                                <div class="rounded-xl bg-gray-50 p-4">
                                    <label for="alasan" class="form-label">{!! $nomor(3) !!} Alasan</label>
                                    <textarea id="alasan" name="alasan" rows="2" minlength="10" maxlength="500" required class="form-input"
                                              placeholder="Contoh: ada keperluan keluarga pada hari tersebut">{{ old('alasan') }}</textarea>
                                </div>

                                <div class="rounded-xl border border-gray-200 p-4 text-sm lg:col-span-2">
                                    <p class="form-label">{!! $nomor(4) !!} Ringkasan</p>
                                    <p class="text-gray-700">Shift <b>{{ $labelTanggal($jadwalDipilih['tanggal']) }} &mdash; {{ $jadwalDipilih['shift'] }}</b> di {{ $jadwalDipilih['klien'] }}
                                        akan dikerjakan rekan pengganti setelah rekan menerima dan Operasional menyetujui. Anda tidak perlu absen pada shift tersebut.</p>
                                </div>

                                <div class="flex justify-end lg:col-span-2">
                                    <button type="submit" class="btn-primary">Kirim Permintaan Backup</button>
                                </div>
                            </form>
                        @endif
                    @endif
                @endif
            </div>
        </section>

        {{-- ===================== Status & riwayat ===================== --}}
        <section class="tk-reveal card p-6">
            <h2 class="card-title text-lg font-bold text-gray-900">Status Backup Shift</h2>

            @if ($riwayat === null || $riwayat->isEmpty())
                <x-ui.empty-state title="Belum ada pengajuan backup shift."
                    message="Permintaan yang Anda kirim maupun yang ditujukan kepada Anda akan tampil di sini." />
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tanggal Shift</th>
                                <th>Shift</th>
                                <th>Peran Anda</th>
                                <th>Status</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($riwayat as $item)
                                @php
                                    $sayaPengaju = (int) $item->id_karyawan_pengaju === $idSaya;
                                    $tahap = PertukaranShiftService::labelTahap($item);
                                @endphp
                                <tr>
                                    <td class="whitespace-nowrap">
                                        {{ $labelTanggal($item->tanggal_kerja) }}
                                        <p class="text-xs text-gray-400">{{ $item->nama_perusahaan }}</p>
                                    </td>
                                    <td class="whitespace-nowrap">{{ $layananTukar->labelShift($item->snapshot_pengaju) }}</td>
                                    <td>
                                        @if ($sayaPengaju)
                                            Digantikan {{ $item->nama_rekan }}
                                            <p class="text-xs text-gray-400">Anda yang mengajukan</p>
                                        @else
                                            Menggantikan {{ $item->nama_pengaju }}
                                            <p class="text-xs text-gray-400">Anda sebagai rekan pengganti</p>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ PertukaranShiftService::kelasBadge($item->status) }}">{{ $tahap }}</span>

                                        @if ($item->status === PertukaranShiftService::STATUS_MENUNGGU && $sayaPengaju)
                                            <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.batalkan', $item->id) }}" class="mt-2"
                                                  onsubmit="return confirm('Batalkan permintaan backup shift ini?')">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn-secondary px-3 py-1.5 text-xs">Batalkan</button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="text-sm text-gray-600">
                                        @if (filled($item->catatan_rekan))
                                            <p>Rekan: {{ $item->catatan_rekan }}</p>
                                        @endif
                                        {{ $item->catatan_keputusan ?? (filled($item->catatan_rekan) ? '' : '-') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $riwayat->links() }}</div>
            @endif
        </section>
    @endif
</div>
@endsection

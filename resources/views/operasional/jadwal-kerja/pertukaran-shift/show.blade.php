@extends('layouts.app')

@section('title', 'Jadwal Kerja')

@use('App\Services\PertukaranShiftService')

@php
    $menunggu = $pengajuan->status === PertukaranShiftService::STATUS_MENUNGGU;
    $tanggalShift = \Carbon\CarbonImmutable::parse($pengajuan->tanggal_kerja)->format('d/m/Y');
    $tanggalShiftRekan = \Carbon\CarbonImmutable::parse(PertukaranShiftService::tanggalRekan($pengajuan))->format('d/m/Y');
    $tanggalSama = $tanggalShift === $tanggalShiftRekan;
    $keteranganTanggal = $tanggalSama ? 'pada '.$tanggalShift : $tanggalShift.' ('.$pengajuan->nama_pengaju.') dan '.$tanggalShiftRekan.' ('.$pengajuan->nama_rekan.')';
    $shiftPengaju = $pertukaranShift->labelShift($pengajuan->snapshot_pengaju);
    $shiftRekan = $pertukaranShift->labelShift($pengajuan->snapshot_rekan);
@endphp

@section('content')
    <div class="space-y-6">
        @include('operasional.jadwal-kerja._tab', ['tabAktif' => 'pertukaran', 'jumlahMenunggu' => $jumlahMenunggu])

        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Detail Pengajuan Pertukaran Shift</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ $pengajuan->nama_pengaju }} &#8644; {{ $pengajuan->nama_rekan }}</p>
                </div>
                <span class="badge {{ PertukaranShiftService::kelasBadge($pengajuan->status) }}">{{ PertukaranShiftService::labelTahap($pengajuan) }}</span>
            </div>

            <div class="card-body space-y-6">
                {{-- Langkah 1: siapa dan mengapa --}}
                <section>
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">1. Karyawan &amp; alasan</h3>
                    <dl class="grid grid-cols-1 gap-4 text-sm md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-md bg-gray-50 p-4">
                            <dt class="text-xs text-gray-500">Perusahaan klien</dt>
                            <dd class="mt-1 font-semibold text-gray-800">{{ $pengajuan->nama_perusahaan }}</dd>
                            <dd class="text-gray-600">Tanggal shift {{ $tanggalSama ? $tanggalShift : $tanggalShift.' & '.$tanggalShiftRekan }}</dd>
                        </div>
                        <div class="rounded-md bg-gray-50 p-4">
                            <dt class="text-xs text-gray-500">Pengaju</dt>
                            <dd class="mt-1 font-semibold text-gray-800">{{ $pengajuan->nama_pengaju }}</dd>
                            <dd class="text-gray-600">{{ $pengajuan->nip_pengaju }}</dd>
                        </div>
                        <div class="rounded-md bg-gray-50 p-4">
                            <dt class="text-xs text-gray-500">Bertukar dengan</dt>
                            <dd class="mt-1 font-semibold text-gray-800">{{ $pengajuan->nama_rekan }}</dd>
                            <dd class="text-gray-600">{{ $pengajuan->nip_rekan }}</dd>
                        </div>
                        <div class="rounded-md bg-gray-50 p-4">
                            <dt class="text-xs text-gray-500">Diajukan</dt>
                            <dd class="mt-1 font-semibold text-gray-800">{{ \Carbon\CarbonImmutable::parse($pengajuan->created_at)->format('d/m/Y H:i') }}</dd>
                        </div>
                        <div class="rounded-md bg-gray-50 p-4 md:col-span-2 xl:col-span-4">
                            <dt class="text-xs text-gray-500">Alasan</dt>
                            <dd class="mt-1 whitespace-pre-line text-gray-800">{{ $pengajuan->alasan }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Langkah 2: perubahan jadwal --}}
                <section>
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">2. Perubahan jadwal {{ $tanggalSama ? 'pada '.$tanggalShift : '' }}</h3>
                    @unless ($tanggalSama)
                        <p class="mb-3 text-sm text-gray-500">Tanggal kedua jadwal berbeda. Tanggal kerja masing-masing tetap; yang ditukar hanya shift-nya.</p>
                    @endunless
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Karyawan</th>
                                    <th>Tanggal</th>
                                    <th>Shift Awal</th>
                                    <th>{{ $pengajuan->status === PertukaranShiftService::STATUS_DISETUJUI ? 'Shift Sekarang' : 'Shift Jika Disetujui' }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="font-medium text-gray-800">{{ $pengajuan->nama_pengaju }}</td>
                                    <td class="whitespace-nowrap">{{ $tanggalShift }}</td>
                                    <td>{{ $shiftPengaju }}</td>
                                    <td class="font-medium text-sji-biru">{{ $shiftRekan }}</td>
                                </tr>
                                <tr>
                                    <td class="font-medium text-gray-800">{{ $pengajuan->nama_rekan }}</td>
                                    <td class="whitespace-nowrap">{{ $tanggalShiftRekan }}</td>
                                    <td>{{ $shiftRekan }}</td>
                                    <td class="font-medium text-sji-biru">{{ $shiftPengaju }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Tanggal kerja dan penempatan masing-masing karyawan tidak berubah; hanya isi shift yang ditukar.</p>
                </section>

                {{-- Langkah 3: keputusan --}}
                <section>
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">3. Keputusan</h3>

                    @if ($menunggu)
                        @if ($masalah !== [])
                            <div class="callout callout-warning mb-4">
                                <p class="font-semibold">Pengajuan belum dapat disetujui</p>
                                <ul class="mt-1 list-inside list-disc">
                                    @foreach ($masalah as $pesan)
                                        <li>{{ $pesan }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <div class="callout callout-info mb-4">
                                <p class="font-semibold">Siap disetujui</p>
                                <p class="mt-1">Kedua jadwal masih sesuai pengajuan, belum ada absensi, dan tidak ada shift yang bertumpuk.</p>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <form method="POST" action="{{ route('operasional.jadwal-kerja.pertukaran-shift.approve', $pengajuan->id) }}"
                                  class="rounded-md border border-gray-200 p-4"
                                  onsubmit="return confirm(@js('Setujui pertukaran ini? Jadwal '.$pengajuan->nama_pengaju.' dan '.$pengajuan->nama_rekan.' '.$keteranganTanggal.' akan langsung ditukar.'))">
                                @csrf
                                @method('PATCH')
                                <p class="text-sm font-medium text-gray-800">Setujui</p>
                                <p class="mt-1 text-sm text-gray-500">Shift kedua jadwal ditukar dan tercatat di riwayat jadwal.</p>
                                <button type="submit" class="btn-primary mt-4" @disabled($masalah !== [])>Approve</button>
                            </form>

                            <form method="POST" action="{{ route('operasional.jadwal-kerja.pertukaran-shift.reject', $pengajuan->id) }}"
                                  class="rounded-md border border-gray-200 p-4"
                                  onsubmit="return confirm('Tolak pengajuan ini? Jadwal kedua karyawan tetap seperti semula.')">
                                @csrf
                                @method('PATCH')
                                <label for="catatan_keputusan" class="text-sm font-medium text-gray-800">Tolak</label>
                                <textarea id="catatan_keputusan" name="catatan_keputusan" rows="2" maxlength="500" class="form-input mt-2"
                                          placeholder="Catatan untuk karyawan (opsional)">{{ old('catatan_keputusan') }}</textarea>
                                <button type="submit" class="btn-outline-danger mt-3">Reject</button>
                            </form>
                        </div>
                    @else
                        <dl class="grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                            <div class="rounded-md bg-gray-50 p-4">
                                <dt class="text-xs text-gray-500">Diproses oleh</dt>
                                <dd class="mt-1 font-semibold text-gray-800">{{ $pengajuan->nama_pemroses ?? '-' }}</dd>
                            </div>
                            <div class="rounded-md bg-gray-50 p-4">
                                <dt class="text-xs text-gray-500">Waktu keputusan</dt>
                                <dd class="mt-1 font-semibold text-gray-800">{{ \Carbon\CarbonImmutable::parse($pengajuan->diproses_pada)->format('d/m/Y H:i') }}</dd>
                            </div>
                            <div class="rounded-md bg-gray-50 p-4">
                                <dt class="text-xs text-gray-500">Catatan</dt>
                                <dd class="mt-1 text-gray-800">{{ $pengajuan->catatan_keputusan ?? '-' }}</dd>
                            </div>
                        </dl>
                    @endif
                </section>
            </div>

            <div class="card-footer">
                <a href="{{ route('operasional.jadwal-kerja.pertukaran-shift.index') }}" class="btn-secondary">Kembali ke daftar</a>
            </div>
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Jadwal Kerja')

@use('App\Services\PertukaranShiftService')

@php
    $menunggu = $pengajuan->status === PertukaranShiftService::STATUS_MENUNGGU;
    $tanggalShift = \Carbon\CarbonImmutable::parse($pengajuan->tanggal_kerja)->format('d/m/Y');
    $shift = $pertukaranShift->labelShift($pengajuan->snapshot_pengaju);
@endphp

@section('content')
    <div class="space-y-6">
        @include('operasional.jadwal-kerja._tab', ['tabAktif' => 'pertukaran', 'jumlahMenunggu' => $jumlahMenunggu])

        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Detail Pengajuan Backup Shift</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ $pengajuan->nama_rekan }} menggantikan {{ $pengajuan->nama_pengaju }}</p>
                </div>
                <span class="badge {{ PertukaranShiftService::kelasBadge($pengajuan->status) }}">{{ PertukaranShiftService::labelTahap($pengajuan) }}</span>
            </div>

            <div class="card-body space-y-6">
                <section>
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">1. Shift yang digantikan &amp; alasan</h3>
                    <dl class="grid grid-cols-1 gap-4 text-sm md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-md bg-gray-50 p-4">
                            <dt class="text-xs text-gray-500">Shift</dt>
                            <dd class="mt-1 font-semibold text-gray-800">{{ $tanggalShift }}</dd>
                            <dd class="text-gray-600">{{ $shift }}</dd>
                            <dd class="text-gray-600">{{ $pengajuan->nama_perusahaan }}</dd>
                        </div>
                        <div class="rounded-md bg-gray-50 p-4">
                            <dt class="text-xs text-gray-500">Pengaju (pemilik jadwal)</dt>
                            <dd class="mt-1 font-semibold text-gray-800">{{ $pengajuan->nama_pengaju }}</dd>
                            <dd class="text-gray-600">{{ $pengajuan->nip_pengaju }}</dd>
                        </div>
                        <div class="rounded-md bg-gray-50 p-4">
                            <dt class="text-xs text-gray-500">Rekan pengganti</dt>
                            <dd class="mt-1 font-semibold text-gray-800">{{ $pengajuan->nama_rekan }}</dd>
                            <dd class="text-gray-600">{{ $pengajuan->nip_rekan }}</dd>
                            <dd class="mt-1 text-xs text-gray-500">
                                Tanggapan: {{ $pengajuan->status_rekan }}
                                @if ($pengajuan->ditanggapi_rekan_pada)
                                    &middot; {{ \Carbon\CarbonImmutable::parse($pengajuan->ditanggapi_rekan_pada)->format('d/m/Y H:i') }}
                                @endif
                            </dd>
                            @if (filled($pengajuan->catatan_rekan))
                                <dd class="text-xs text-gray-600">"{{ $pengajuan->catatan_rekan }}"</dd>
                            @endif
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
                    <p class="mt-2 text-xs text-gray-500">Jadwal tetap milik penempatan pengaju (klien, lokasi, dan jam shift tidak berubah). Setelah disetujui, rekan yang absen pada shift ini dan pengaju tidak perlu absen.</p>
                </section>

                <section>
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">2. Keputusan</h3>

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
                                <p class="mt-1">Rekan sudah menerima, jadwal pengaju belum berubah, dan rekan tidak punya jadwal yang bertabrakan.</p>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <form method="POST" action="{{ route('operasional.jadwal-kerja.pertukaran-shift.approve', $pengajuan->id) }}"
                                  class="rounded-md border border-gray-200 p-4"
                                  onsubmit="return confirm(@js('Setujui? '.$pengajuan->nama_rekan.' akan menggantikan '.$pengajuan->nama_pengaju.' pada '.$tanggalShift.'.'))">
                                @csrf
                                @method('PATCH')
                                <p class="text-sm font-medium text-gray-800">Setujui</p>
                                <p class="mt-1 text-sm text-gray-500">Shift ditugaskan kepada rekan pengganti dan tercatat di riwayat jadwal.</p>
                                <button type="submit" class="btn-primary mt-4" @disabled($masalah !== [])>Approve</button>
                            </form>

                            <form method="POST" action="{{ route('operasional.jadwal-kerja.pertukaran-shift.reject', $pengajuan->id) }}"
                                  class="rounded-md border border-gray-200 p-4"
                                  onsubmit="return confirm('Tolak pengajuan ini? Jadwal pengaju tetap seperti semula.')">
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
                                <dd class="mt-1 font-semibold text-gray-800">{{ $pengajuan->nama_pemroses ?? ($pengajuan->status_rekan === PertukaranShiftService::REKAN_DITOLAK ? $pengajuan->nama_rekan.' (rekan)' : 'Sistem') }}</dd>
                            </div>
                            <div class="rounded-md bg-gray-50 p-4">
                                <dt class="text-xs text-gray-500">Waktu keputusan</dt>
                                <dd class="mt-1 font-semibold text-gray-800">{{ $pengajuan->diproses_pada ? \Carbon\CarbonImmutable::parse($pengajuan->diproses_pada)->format('d/m/Y H:i') : '-' }}</dd>
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

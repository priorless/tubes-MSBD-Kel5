@extends('layouts.app')

@section('title', 'Jadwal Kerja')

@use('App\Services\PertukaranShiftService')

@php
    $totalPengajuan = array_sum($ringkasanStatus);
    $jumlahPerStatus = [...$ringkasanStatus, 'Semua' => $totalPengajuan];
    $urlFilter = static fn (array $ubah): string => route('operasional.jadwal-kerja.pertukaran-shift.index', array_filter(
        [...$filter, ...$ubah],
        static fn (mixed $nilai): bool => $nilai !== null && $nilai !== '' && $nilai !== 'Semua',
    ));
@endphp

@section('content')
    <div class="space-y-6">
        @include('operasional.jadwal-kerja._tab', ['tabAktif' => 'pertukaran', 'jumlahMenunggu' => $jumlahMenunggu])

        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Pengajuan Pertukaran Shift</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        @if ($jumlahMenunggu > 0)
                            <span class="font-medium text-amber-700">{{ $jumlahMenunggu }} pengajuan menunggu keputusan Anda.</span>
                        @else
                            Tidak ada pengajuan yang menunggu keputusan.
                        @endif
                        Buka <b>Detail</b> untuk memeriksa, lalu setujui atau tolak.
                    </p>
                </div>
            </div>

            <div class="card-body space-y-4">
                {{-- Status: satu klik, dengan jumlah per status --}}
                <div class="flex flex-wrap gap-2" role="group" aria-label="Filter status">
                    @foreach ($filterStatus as $pilihan)
                        <a href="{{ $urlFilter(['status' => $pilihan]) }}"
                           @if ($pilihan === $filter['status']) aria-current="true" @endif
                           class="{{ $pilihan === $filter['status'] ? 'btn-primary' : 'btn-secondary' }} btn-sm">
                            {{ $pilihan === 'Semua' ? 'Semua' : PertukaranShiftService::labelStatus($pilihan) }}
                            <span class="ml-1 opacity-75">({{ $jumlahPerStatus[$pilihan] }})</span>
                        </a>
                    @endforeach
                </div>

                {{-- Filter tambahan --}}
                <form method="GET" action="{{ route('operasional.jadwal-kerja.pertukaran-shift.index') }}"
                      class="grid grid-cols-1 items-end gap-3 rounded-md bg-gray-50 p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_2fr_auto]">
                    @if ($filter['status'] !== 'Semua')
                        <input type="hidden" name="status" value="{{ $filter['status'] }}">
                    @endif
                    <div>
                        <label for="cari" class="form-label">Nama / NIP karyawan</label>
                        <input id="cari" name="cari" type="search" value="{{ $filter['cari'] }}" maxlength="100" class="form-input" placeholder="Pemohon atau rekan">
                    </div>
                    <div>
                        <label for="tanggal_dari" class="form-label">Tanggal shift dari</label>
                        <input id="tanggal_dari" name="tanggal_dari" type="date" value="{{ $filter['tanggal_dari'] }}" class="form-input">
                    </div>
                    <div>
                        <label for="tanggal_sampai" class="form-label">sampai</label>
                        <input id="tanggal_sampai" name="tanggal_sampai" type="date" value="{{ $filter['tanggal_sampai'] }}" class="form-input">
                    </div>
                    <div>
                        <label for="id_klien" class="form-label">Klien</label>
                        <select id="id_klien" name="id_klien" class="form-input">
                            <option value="">Semua klien</option>
                            @foreach ($daftarKlien as $klien)
                                <option value="{{ $klien->id }}" @selected($filter['id_klien'] === (int) $klien->id)>{{ $klien->nama_perusahaan }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary">Terapkan</button>
                        @if ($filterAktif)
                            <a href="{{ $urlFilter(['cari' => null, 'tanggal_dari' => null, 'tanggal_sampai' => null, 'id_klien' => null]) }}" class="btn-secondary">Reset</a>
                        @endif
                    </div>
                </form>

                @if ($daftarPengajuan->isEmpty())
                    <x-ui.empty-state
                        :title="$totalPengajuan === 0 ? 'Belum ada pengajuan pertukaran shift.' : 'Tidak ada pengajuan yang cocok dengan filter.'"
                        :message="$totalPengajuan === 0 ? 'Pengajuan dibuat karyawan dari menu Absensi dan akan langsung muncul di sini.' : 'Ubah atau reset filter untuk melihat pengajuan lainnya.'" />
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Pemohon &amp; Rekan</th>
                                    <th>Klien</th>
                                    <th>Tanggal Shift</th>
                                    <th>Shift Saat Pengajuan</th>
                                    <th>Alasan</th>
                                    <th>Status</th>
                                    <th class="text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($daftarPengajuan as $item)
                                    @php
                                        $menunggu = $item->status === PertukaranShiftService::STATUS_MENUNGGU;
                                        $kedaluwarsa = $menunggu && $pertukaranShift->pengajuanKedaluwarsa($item);
                                        $tanggalRekan = $pertukaranShift->tanggalKerjaRekan($item);
                                    @endphp
                                    <tr @class(['bg-amber-50/40' => $menunggu && ! $kedaluwarsa])>
                                        <td>
                                            <p class="font-medium text-gray-800">{{ $item->nama_pengaju }}</p>
                                            <p class="text-xs text-gray-500">&#8644; {{ $item->nama_rekan }}</p>
                                        </td>
                                        <td>{{ $item->nama_perusahaan }}</td>
                                        <td class="whitespace-nowrap">
                                            {{ \Carbon\CarbonImmutable::parse($item->tanggal_kerja)->format('d/m/Y') }}
                                            @if ($tanggalRekan !== (string) $item->tanggal_kerja)
                                                &#8644; {{ \Carbon\CarbonImmutable::parse($tanggalRekan)->format('d/m/Y') }}
                                            @endif
                                            <p class="text-xs text-gray-400">Diajukan {{ \Carbon\CarbonImmutable::parse($item->created_at)->format('d/m/Y H:i') }}</p>
                                        </td>
                                        <td class="whitespace-nowrap text-sm">
                                            <p><span class="text-gray-500">{{ \Illuminate\Support\Str::words($item->nama_pengaju, 1, '') }}:</span> {{ \Carbon\CarbonImmutable::parse($item->tanggal_kerja)->format('d/m') }} &middot; {{ $pertukaranShift->labelShift($item->snapshot_pengaju) }}</p>
                                            <p><span class="text-gray-500">{{ \Illuminate\Support\Str::words($item->nama_rekan, 1, '') }}:</span> {{ \Carbon\CarbonImmutable::parse($tanggalRekan)->format('d/m') }} &middot; {{ $pertukaranShift->labelShift($item->snapshot_rekan) }}</p>
                                        </td>
                                        <td class="max-w-xs"><span title="{{ $item->alasan }}">{{ \Illuminate\Support\Str::limit($item->alasan, 60) }}</span></td>
                                        <td class="whitespace-nowrap">
                                            <span class="badge {{ PertukaranShiftService::kelasBadge($item->status) }}">{{ PertukaranShiftService::labelTahap($item) }}</span>
                                            @if ($kedaluwarsa)
                                                <p class="mt-1 text-xs text-red-600">Tanggal sudah lewat</p>
                                            @elseif (! $menunggu)
                                                <p class="mt-1 text-xs text-gray-400">{{ $item->nama_pemroses ?? '-' }} &middot; {{ \Carbon\CarbonImmutable::parse($item->diproses_pada)->format('d/m/Y H:i') }}</p>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <a href="{{ route('operasional.jadwal-kerja.pertukaran-shift.show', $item->id) }}" class="btn-secondary btn-sm">Detail</a>
                                                @if ($menunggu && ! $kedaluwarsa)
                                                    <form method="POST" action="{{ route('operasional.jadwal-kerja.pertukaran-shift.approve', $item->id) }}"
                                                          onsubmit="return confirm(@js('Setujui pertukaran shift '.$item->nama_pengaju.' dengan '.$item->nama_rekan.'? Jadwal keduanya akan langsung ditukar.'))">
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="btn-primary btn-sm">Approve</button>
                                                    </form>
                                                @endif
                                                @if ($menunggu)
                                                    <form method="POST" action="{{ route('operasional.jadwal-kerja.pertukaran-shift.reject', $item->id) }}"
                                                          onsubmit="return confirm(@js('Tolak pengajuan '.$item->nama_pengaju.'? Jadwal keduanya tetap seperti semula.'))">
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="btn-outline-danger btn-sm">Reject</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @include('partials.pagination', ['paginator' => $daftarPengajuan])
                @endif
            </div>
        </div>
    </div>
@endsection

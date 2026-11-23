@extends('layouts.app')

@section('title', 'Rekap & Laporan')

@php
    $namaBulan = $rekap['bulan']->locale('id')->translatedFormat('F Y');
    $parameter = array_filter($filter, static fn ($nilai) => $nilai !== null);
    $kolomBelumAda = array_keys(array_filter($rekap['kolom'], static fn (bool $ada): bool => ! $ada));
    $tanggal = static fn (string $iso): string => \Carbon\Carbon::parse($iso)->format('d/m');
@endphp

@section('content')
    <x-ui.page-header title="Rekap & Laporan"
        :description="'Rekap kerja bulanan '.$namaBulan.': jadwal dari Operasional dibandingkan dengan kehadiran dari Absensi.'">
        @if ($rekap['baris']->isNotEmpty())
            <a href="{{ route('operasional.rekap.export', $parameter) }}" class="btn-secondary">Unduh CSV</a>
        @endif
    </x-ui.page-header>

    <form method="GET" action="{{ route('operasional.rekap.index') }}" class="card card-body mb-6 grid grid-cols-1 items-end gap-4 md:grid-cols-[1fr_1fr_2fr_auto]">
        <div>
            <label for="bulan" class="form-label">Bulan &amp; Tahun</label>
            <input id="bulan" name="bulan" type="month" class="form-input" value="{{ $filter['bulan'] }}" required>
        </div>
        <div>
            <label for="id_klien" class="form-label">Klien</label>
            <select id="id_klien" name="id_klien" class="form-input">
                <option value="">Semua klien</option>
                @foreach ($daftarKlien as $item)
                    <option value="{{ $item->id }}" @selected($filter['id_klien'] === (int) $item->id)>{{ $item->nama_perusahaan }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="id_karyawan" class="form-label">Karyawan</label>
            <select id="id_karyawan" name="id_karyawan" class="form-input">
                <option value="">Semua karyawan</option>
                @foreach ($daftarKaryawan as $item)
                    <option value="{{ $item->id }}" @selected($filter['id_karyawan'] === (int) $item->id)>{{ $item->nip }} &mdash; {{ $item->nama }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-primary">Tampilkan</button>
    </form>

    @if ($kolomBelumAda !== [])
        <div class="callout callout-info mb-6 text-sm">
            <p class="font-semibold">Sebagian data belum tersedia di database</p>
            <ul class="mt-1 list-inside list-disc">
                @if (in_array('menit_terlambat', $kolomBelumAda, true))
                    <li>Menit keterlambatan belum dicatat; yang ditampilkan adalah jumlah hari berstatus Terlambat.</li>
                @endif
            </ul>
        </div>
    @endif

    @if ($rekap['baris']->isEmpty())
        <x-ui.empty-state title="Tidak ada penempatan pada {{ $namaBulan }}"
            message="Rekap muncul untuk karyawan yang memiliki penempatan pada bulan dan klien yang dipilih. Ubah filter atau pilih bulan lain." />
    @else
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <x-ui.stat-card label="Karyawan" :value="$rekap['total']['karyawan']" />
            <x-ui.stat-card label="Hari kerja terjadwal" :value="$rekap['total']['kerja_terjadwal']" />
            <x-ui.stat-card label="Hari hadir" :value="$rekap['total']['hadir']" :hint="$rekap['total']['terlambat'].' hari terlambat'" />
            <x-ui.stat-card label="Tidak hadir" :value="$rekap['total']['tidak_hadir']" hint="Izin, sakit, alpha, atau tanpa catatan"
                :tone="$rekap['total']['tidak_hadir'] > 0 ? 'warning' : 'default'" />
            <x-ui.stat-card label="Jam lembur" :value="$rekap['total']['jam_lembur']" :hint="$rekap['total']['jam_kerja'].' jam kerja tercatat'" />
        </div>

        <section class="card mt-6">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Rincian per karyawan</h3>
                    <p class="mt-1 text-sm text-gray-500">Jam kerja tercatat dihitung dari jam masuk dan jam keluar (jam penuh), sama seperti perhitungan Absensi.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Karyawan</th>
                            <th>Jadwal (kerja / libur / belum diatur)</th>
                            <th>Hadir</th>
                            <th>Terlambat</th>
                            <th>Izin / Sakit / Alpha</th>
                            <th>Tanpa catatan</th>
                            <th>Jam kerja</th>
                            <th>Lembur</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rekap['baris'] as $baris)
                            <tr>
                                <td>
                                    <a href="{{ route('operasional.jadwal-kerja', ['id_karyawan' => $baris->id_karyawan, 'bulan' => $filter['bulan']]) }}" class="font-medium text-sji-biru hover:underline">{{ $baris->nama }}</a>
                                    <p class="text-xs text-gray-400">{{ $baris->nip }} &middot; {{ $baris->klien }}</p>
                                </td>
                                <td class="whitespace-nowrap tabular-nums">{{ $baris->kerja_terjadwal }} / {{ $baris->libur_terjadwal }} / {{ $baris->belum_diatur }}</td>
                                <td class="tabular-nums">{{ $baris->hadir }}</td>
                                <td class="whitespace-nowrap tabular-nums">
                                    {{ $baris->terlambat }} hari
                                    @if ($rekap['kolom']['menit_terlambat'])
                                        <p class="text-xs text-gray-400">{{ $baris->menit_terlambat }} menit</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap tabular-nums">{{ $baris->izin }} / {{ $baris->sakit }} / {{ $baris->alpha }}</td>
                                <td class="tabular-nums">{{ count($baris->tanggal_tanpa_absensi) }}</td>
                                <td class="tabular-nums">{{ $baris->jam_kerja }} jam</td>
                                <td class="whitespace-nowrap tabular-nums">
                                    {{ $baris->jam_lembur }} jam
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card mt-6">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Data belum lengkap</h3>
                    <p class="mt-1 text-sm text-gray-500">Periksa hal berikut sebelum rekap dipakai. Klik nama untuk membuka kalender karyawan.</p>
                </div>
            </div>
            @if ($rekap['catatan']->isEmpty())
                <div class="card-body">
                    <x-ui.empty-state title="Data {{ $namaBulan }} sudah lengkap" message="Semua tanggal terjadwal dan kehadiran sudah sesuai." />
                </div>
            @else
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach ($rekap['catatan'] as $catatan)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-6 py-3">
                            <span>
                                <a href="{{ route('operasional.jadwal-kerja', ['id_karyawan' => $catatan->id_karyawan, 'bulan' => $filter['bulan']]) }}" class="font-medium text-sji-biru hover:underline">{{ $catatan->nama }}</a>:
                                {{ $catatan->jumlah }} {{ $catatan->keterangan }}
                            </span>
                            @if ($catatan->tanggal !== [])
                                <span class="text-xs text-gray-500">{{ implode(', ', array_map($tanggal, $catatan->tanggal)) }}{{ $catatan->jumlah > count($catatan->tanggal) ? ', …' : '' }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
@endsection

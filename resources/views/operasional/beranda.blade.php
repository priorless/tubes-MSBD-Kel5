@extends('layouts.app')

@section('title', 'Dashboard Operasional')

@php
    $pertukaranMenunggu = $ringkasanPertukaranShift['Menunggu'];
    $jumlahPerhatian = $jadwalBelumLengkap->count() + $kontrakSegeraBerakhir->count() + $penempatanLewatMasa->count() + $pertukaranMenunggu;
    $urlPertukaran = static fn (string $status): string => route('operasional.jadwal-kerja.pertukaran-shift.index', ['status' => $status]);
    $panah = '<svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
    $nomorTugas = 0;
    $aksesCepat = [
        ['Atur Jadwal Kerja', route('operasional.jadwal-kerja')],
        ['Buat Penempatan Baru', route('operasional.penempatan.create')],
        ['Tambah Klien', route('operasional.klien.create')],
        ['Lihat Laporan', route('operasional.rekap.index')],
    ];
@endphp

@section('content')
    <x-ui.page-header :kicker="now()->locale('id')->translatedFormat('l, j F Y').' · Ringkasan operasional'"
        :title="'Selamat datang, '.$namaPengguna"
        description="Ringkasan pekerjaan Operasional hari ini. Kerjakan dari daftar Perlu diperhatikan." >
        <a href="{{ route('operasional.rekap.index') }}" class="btn-secondary">Lihat Laporan</a>
        <a href="{{ route('operasional.jadwal-kerja') }}" class="btn-primary">Atur Jadwal Kerja</a>
    </x-ui.page-header>

    {{-- Ringkasan utama: satu strip seperti Dashboard CEO dan HRD. Hal yang butuh tindakan diberi blok navy. --}}
    <section class="op-headline" style="--kolom: {{ $jadwalTersedia ? 4 : 2 }}" aria-label="Ringkasan operasional">
        <div class="op-headline-item">
            <span class="stat-label">Klien aktif</span>
            <span class="op-headline-angka" data-hitung>{{ $jumlahKlienAktif }}</span>
            <span class="op-headline-hint">Perusahaan dengan kerja sama aktif</span>
            <a href="{{ route('operasional.klien.index') }}" class="op-link">Data Klien {!! $panah !!}</a>
        </div>
        <div class="op-headline-item">
            <span class="stat-label">Karyawan ditempatkan</span>
            <span class="op-headline-angka" data-hitung>{{ $jumlahKaryawanDitempatkan }}</span>
            <span class="op-headline-hint">Sedang bekerja di klien hari ini</span>
            <a href="{{ route('operasional.monitoring') }}" class="op-link">Monitoring Sebaran {!! $panah !!}</a>
        </div>
        @if ($jadwalTersedia)
            <div class="op-headline-item">
                <span class="stat-label">Tanggal terjadwal {{ $namaBulan }}</span>
                <span class="op-headline-angka" data-hitung>{{ $jumlahTanggalTerjadwal }}</span>
                <span class="op-headline-hint">Tanggal berstatus Kerja atau Libur</span>
                <a href="{{ route('operasional.jadwal-kerja') }}" class="op-link">Jadwal Kerja {!! $panah !!}</a>
            </div>
            <div @class(['op-headline-item', 'is-perlu-tindakan' => $jadwalBelumLengkap->isNotEmpty()])>
                @if ($jadwalBelumLengkap->isNotEmpty())
                    <span class="op-stempel">PERLU TINDAKAN</span>
                @endif
                <span class="stat-label">Jadwal belum lengkap {{ $namaBulan }}</span>
                <span @class(['op-headline-angka', 'is-positif' => $jadwalBelumLengkap->isEmpty()]) data-hitung>{{ $jadwalBelumLengkap->count() }}</span>
                <span class="op-headline-hint">{{ $jumlahTanggalBelumDiatur }} tanggal belum diatur</span>
                <a href="{{ route('operasional.jadwal-kerja') }}" class="op-link">{{ $jadwalBelumLengkap->isEmpty() ? 'Jadwal Kerja' : 'Lengkapi sekarang' }} {!! $panah !!}</a>
            </div>
        @endif
    </section>

    <div class="mt-6 grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
        {{--
            Daftar kerja: satu <table> dengan kolom tetap (table-fixed + lebar di thead), sehingga
            kolom Keterangan dan Tindakan sejajar di setiap baris. Baris grup memisahkan jenis masalah.
            Di layar kecil kolom No dan Keterangan disembunyikan; keterangan pindah ke bawah nama.
        --}}
        <section class="card" aria-labelledby="judul-perlu-diperhatikan">
            <div class="card-header">
                <div>
                    <h3 id="judul-perlu-diperhatikan" class="card-title">Perlu diperhatikan</h3>
                    <p class="mt-0.5 text-sm text-gray-500">{{ $jumlahPerhatian === 0 ? 'Tidak ada pekerjaan yang tertunda.' : $jumlahPerhatian.' hal menunggu tindakan.' }}</p>
                </div>
            </div>

            @if ($jumlahPerhatian === 0)
                <div class="card-body">
                    <x-ui.empty-state title="Semua beres"
                        message="Jadwal bulan ini sudah lengkap dan tidak ada kontrak yang segera berakhir." />
                </div>
            @else
                <table class="table op-tabel-tugas table-fixed">
                    <thead>
                        <tr>
                            <th scope="col" class="hidden w-14 sm:table-cell">No</th>
                            <th scope="col">Karyawan</th>
                            <th scope="col" class="hidden w-64 sm:table-cell">Keterangan</th>
                            <th scope="col" class="w-44 text-right">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($pertukaranMenunggu > 0)
                            <tr class="op-tabel-grup">
                                <th scope="colgroup" colspan="4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <strong>Pengajuan pertukaran shift menunggu persetujuan</strong>
                                            Periksa detail tiap pengajuan, lalu setujui atau tolak sebelum tanggal shift.
                                        </div>
                                        <a href="{{ $urlPertukaran('Menunggu') }}" class="btn-primary btn-sm">Tinjau {{ $pertukaranMenunggu }} pengajuan</a>
                                    </div>
                                </th>
                            </tr>
                        @endif

                        @if ($penempatanLewatMasa->isNotEmpty())
                            <tr class="op-tabel-grup is-bahaya">
                                <th scope="colgroup" colspan="4">
                                    <strong>Masa penempatan sudah lewat, tetapi masih Aktif</strong>
                                    Akhiri penempatan ini di halaman Monitoring Sebaran.
                                </th>
                            </tr>
                            @foreach ($penempatanLewatMasa->take($batasDaftar) as $item)
                                @php $keterangan = 'Berakhir '.\Carbon\Carbon::parse($item->tgl_selesai)->format('d/m/Y'); @endphp
                                <tr>
                                    <td class="hidden text-gray-500 tabular-nums sm:table-cell">{{ ++$nomorTugas }}</td>
                                    <td>
                                        <p class="truncate font-semibold text-gray-800">{{ $item->nama }}</p>
                                        <p class="truncate text-xs text-gray-500">{{ $item->nama_perusahaan }}</p>
                                        <p class="mt-1.5 sm:hidden"><span class="badge badge-danger">{{ $keterangan }}</span></p>
                                    </td>
                                    <td class="hidden sm:table-cell"><span class="badge badge-danger">{{ $keterangan }}</span></td>
                                    <td class="text-right"><a href="{{ route('operasional.monitoring') }}" class="btn-secondary btn-sm">Buka Monitoring</a></td>
                                </tr>
                            @endforeach
                        @endif

                        @if ($jadwalBelumLengkap->isNotEmpty())
                            <tr class="op-tabel-grup">
                                <th scope="colgroup" colspan="4">
                                    <strong>Jadwal {{ $namaBulan }} belum lengkap</strong>
                                    Tanggal yang belum diatur tidak dianggap Libur. Klik nama untuk membuka kalendernya.
                                </th>
                            </tr>
                            @foreach ($jadwalBelumLengkap->take($batasDaftar) as $item)
                                @php
                                    $urlKalender = route('operasional.jadwal-kerja', ['id_karyawan' => $item->id_karyawan, 'bulan' => $bulan]);
                                    $jumlahHari = max(1, $item->terjadwal + $item->belum_diatur);
                                    $keterangan = $item->belum_diatur.' tanggal belum diatur';
                                @endphp
                                <tr>
                                    <td class="hidden text-gray-500 tabular-nums sm:table-cell">{{ ++$nomorTugas }}</td>
                                    <td>
                                        <a href="{{ $urlKalender }}" class="block truncate font-semibold text-sji-biru hover:underline">{{ $item->nama }}</a>
                                        <p class="truncate text-xs text-gray-500">{{ $item->nama_perusahaan }}</p>
                                        <p class="mt-1.5 text-xs font-semibold text-red-700 sm:hidden">{{ $keterangan }}</p>
                                    </td>
                                    <td class="hidden sm:table-cell">
                                        <p class="text-xs font-semibold text-red-700">{{ $keterangan }}</p>
                                        <div class="op-hari" style="--jumlah: {{ $jumlahHari }}" title="{{ $item->terjadwal }} terjadwal, {{ $item->belum_diatur }} belum diatur" aria-hidden="true">
                                            @for ($hari = 0; $hari < $jumlahHari; $hari++)
                                                <i @class(['is-kosong' => $hari >= $item->terjadwal])></i>
                                            @endfor
                                        </div>
                                    </td>
                                    <td class="text-right"><a href="{{ $urlKalender }}" class="btn-secondary btn-sm">Atur jadwal</a></td>
                                </tr>
                            @endforeach
                            @if ($jadwalBelumLengkap->count() > $batasDaftar)
                                <tr>
                                    <td colspan="4" class="text-xs text-gray-500">dan {{ $jadwalBelumLengkap->count() - $batasDaftar }} karyawan lainnya.</td>
                                </tr>
                            @endif
                        @endif

                        @if ($kontrakSegeraBerakhir->isNotEmpty())
                            <tr class="op-tabel-grup is-info">
                                <th scope="colgroup" colspan="4">
                                    <strong>Penempatan berakhir dalam {{ $batasHariKontrak }} hari</strong>
                                    Siapkan perpanjangan, rotasi, atau akhiri penempatan tepat waktu.
                                </th>
                            </tr>
                            @foreach ($kontrakSegeraBerakhir->take($batasDaftar) as $item)
                                @php $keterangan = $item->sisa_hari === 0 ? 'Berakhir hari ini' : 'Sisa '.$item->sisa_hari.' hari'; @endphp
                                <tr>
                                    <td class="hidden text-gray-500 tabular-nums sm:table-cell">{{ ++$nomorTugas }}</td>
                                    <td>
                                        <p class="truncate font-semibold text-gray-800">{{ $item->nama }}</p>
                                        <p class="truncate text-xs text-gray-500">{{ $item->nama_perusahaan }}</p>
                                        <p class="mt-1.5 sm:hidden"><span class="badge badge-warning">{{ $keterangan }}</span></p>
                                    </td>
                                    <td class="hidden sm:table-cell"><span class="badge badge-warning">{{ $keterangan }}</span></td>
                                    <td class="text-right"><a href="{{ route('operasional.rotasi.create') }}" class="btn-secondary btn-sm">Rotasi</a></td>
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            @endif
        </section>

        <div class="flex flex-col gap-6">
            @if ($pertukaranShiftTersedia)
                <section class="op-kartu-navy" aria-labelledby="judul-pertukaran-shift">
                    <p class="op-lab" style="color: var(--op-gold)">Jadwal Kerja</p>
                    <h3 id="judul-pertukaran-shift" class="mt-1 text-lg font-bold">Pengajuan Pertukaran Shift</h3>
                    <div class="op-trio">
                        <a href="{{ $urlPertukaran('Menunggu') }}" @class(['is-menunggu' => $pertukaranMenunggu > 0])>
                            <span class="op-trio-label">Menunggu persetujuan</span>
                            <span class="op-trio-angka block" data-hitung>{{ $pertukaranMenunggu }}</span>
                        </a>
                        <a href="{{ $urlPertukaran('Disetujui') }}">
                            <span class="op-trio-label">Disetujui</span>
                            <span class="op-trio-angka block" data-hitung>{{ $ringkasanPertukaranShift['Disetujui'] }}</span>
                        </a>
                        <a href="{{ $urlPertukaran('Ditolak') }}">
                            <span class="op-trio-label">Ditolak</span>
                            <span class="op-trio-angka block" data-hitung>{{ $ringkasanPertukaranShift['Ditolak'] }}</span>
                        </a>
                    </div>
                    <p class="relative mt-3 text-sm text-white/80">{{ $pertukaranMenunggu > 0 ? $pertukaranMenunggu.' pengajuan perlu diputuskan.' : 'Tidak ada pengajuan yang tertunda.' }}</p>
                    <a href="{{ route('operasional.jadwal-kerja.pertukaran-shift.index') }}" class="op-link relative mt-3" style="color: #fff">Lihat semua pengajuan {!! $panah !!}</a>
                </section>
            @endif

            <section class="card overflow-hidden" aria-labelledby="judul-akses-cepat">
                <div class="card-header">
                    <h3 id="judul-akses-cepat" class="card-title">Akses cepat</h3>
                </div>
                @foreach ($aksesCepat as $urutan => [$labelAkses, $urlAkses])
                    <a href="{{ $urlAkses }}" class="op-akses-cepat">
                        <span class="op-akses-cepat-no">0{{ $urutan + 1 }}</span>
                        <span>{{ $labelAkses }}</span>
                        <span class="op-akses-cepat-panah">{!! $panah !!}</span>
                    </a>
                @endforeach
            </section>
        </div>
    </div>
@endsection

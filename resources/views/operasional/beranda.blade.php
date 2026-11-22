@extends('layouts.app')

@section('title', 'Beranda Operasional')

@php
    $pertukaranMenunggu = $ringkasanPertukaranShift['Menunggu'];
    $jumlahPerhatian = $jadwalBelumLengkap->count() + $kontrakSegeraBerakhir->count() + $penempatanLewatMasa->count() + $pertukaranMenunggu;
    $urlPertukaran = static fn (string $status): string => route('operasional.jadwal-kerja.pertukaran-shift.index', ['status' => $status]);
    $inisial = static fn (string $nama): string => collect(explode(' ', $nama))->filter()->take(2)
        ->map(fn (string $kata): string => mb_strtoupper(mb_substr($kata, 0, 1)))->implode('');
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
    <x-ui.page-header :kicker="now()->locale('id')->translatedFormat('l, j F Y').' · Briefing operasional'"
        :title="'Selamat datang, '.$namaPengguna"
        description="Ringkasan pekerjaan Operasional hari ini. Kerjakan dari daftar Perlu diperhatikan." >
        <a href="{{ route('operasional.rekap.index') }}" class="btn-secondary">Lihat Laporan</a>
        <a href="{{ route('operasional.jadwal-kerja') }}" class="btn-primary">Atur Jadwal Kerja</a>
    </x-ui.page-header>

    {{-- Ringkasan utama: satu strip, bukan deret kartu. Hal yang butuh tindakan diberi blok navy. --}}
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
                <span @class(['op-headline-angka', 'text-emerald-700' => $jadwalBelumLengkap->isEmpty()]) data-hitung>{{ $jadwalBelumLengkap->count() }}</span>
                <span class="op-headline-hint">{{ $jumlahTanggalBelumDiatur }} tanggal belum diatur</span>
                <a href="{{ route('operasional.jadwal-kerja') }}" class="op-link">{{ $jadwalBelumLengkap->isEmpty() ? 'Jadwal Kerja' : 'Lengkapi sekarang' }} {!! $panah !!}</a>
            </div>
        @endif
    </section>

    <div class="mt-6 grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
        {{-- Daftar kerja: dikelompokkan per jenis masalah, satu tindakan per baris --}}
        <section class="op-sheet" aria-labelledby="judul-perlu-diperhatikan">
            <div class="flex flex-wrap items-end justify-between gap-3 px-6 pt-5 pb-4">
                <div>
                    <p class="op-lab">Daftar kerja hari ini</p>
                    <h3 id="judul-perlu-diperhatikan" class="mt-1 text-[19px] leading-[26px] font-bold text-gray-800">Perlu diperhatikan</h3>
                    <p class="mt-0.5 text-sm text-gray-600">{{ $jumlahPerhatian === 0 ? 'Tidak ada pekerjaan yang tertunda.' : $jumlahPerhatian.' hal menunggu tindakan.' }}</p>
                </div>
            </div>

            @if ($jumlahPerhatian === 0)
                <div class="px-6 pb-6">
                    <x-ui.empty-state title="Semua beres"
                        message="Jadwal bulan ini sudah lengkap dan tidak ada kontrak yang segera berakhir." />
                </div>
            @endif

            @if ($pertukaranMenunggu > 0)
                <div class="op-grup">
                    <div class="min-w-0 flex-1">
                        <strong>Pengajuan pertukaran shift menunggu persetujuan</strong>
                        Periksa detail tiap pengajuan, lalu setujui atau tolak sebelum tanggal shift.
                    </div>
                    <a href="{{ $urlPertukaran('Menunggu') }}" class="btn-primary btn-sm self-center">Tinjau {{ $pertukaranMenunggu }} pengajuan</a>
                </div>
            @endif

            @if ($penempatanLewatMasa->isNotEmpty())
                <div class="op-grup is-bahaya">
                    <div>
                        <strong>Masa penempatan sudah lewat, tetapi masih Aktif</strong>
                        Akhiri penempatan ini di halaman Monitoring Sebaran.
                    </div>
                </div>
                @foreach ($penempatanLewatMasa->take($batasDaftar) as $item)
                    <div class="op-tugas">
                        <span class="op-tugas-no">{{ str_pad((string) ++$nomorTugas, 2, '0', STR_PAD_LEFT) }}</span>
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="op-avatar-sm" aria-hidden="true">{{ $inisial($item->nama) }}</span>
                            <div class="min-w-0">
                                <p class="truncate font-bold text-gray-800">{{ $item->nama }}</p>
                                <p class="truncate text-[12.5px] text-gray-500">{{ $item->nama_perusahaan }}</p>
                            </div>
                        </div>
                        <span><span class="badge badge-danger">Berakhir {{ \Carbon\Carbon::parse($item->tgl_selesai)->format('d/m/Y') }}</span></span>
                        <a href="{{ route('operasional.monitoring') }}" class="btn-secondary btn-sm">Buka Monitoring</a>
                    </div>
                @endforeach
            @endif

            @if ($jadwalBelumLengkap->isNotEmpty())
                <div class="op-grup mt-3">
                    <div>
                        <strong>Jadwal {{ $namaBulan }} belum lengkap</strong>
                        Tanggal yang belum diatur tidak dianggap Libur. Klik nama untuk membuka kalendernya.
                    </div>
                </div>
                @foreach ($jadwalBelumLengkap->take($batasDaftar) as $item)
                    @php
                        $urlKalender = route('operasional.jadwal-kerja', ['id_karyawan' => $item->id_karyawan, 'bulan' => $bulan]);
                        $jumlahHari = max(1, $item->terjadwal + $item->belum_diatur);
                    @endphp
                    <div class="op-tugas">
                        <span class="op-tugas-no">{{ str_pad((string) ++$nomorTugas, 2, '0', STR_PAD_LEFT) }}</span>
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="op-avatar-sm" aria-hidden="true">{{ $inisial($item->nama) }}</span>
                            <div class="min-w-0">
                                <a href="{{ $urlKalender }}" class="block truncate font-bold text-gray-800 hover:text-sji-biru hover:underline">{{ $item->nama }}</a>
                                <p class="truncate text-[12.5px] text-gray-500">{{ $item->nama_perusahaan }}</p>
                            </div>
                        </div>
                        <div>
                            <p class="text-[13px] text-gray-600"><span class="font-bold text-red-700">{{ $item->belum_diatur }} tanggal belum diatur</span></p>
                            <div class="op-hari" style="--jumlah: {{ $jumlahHari }}" title="{{ $item->terjadwal }} terjadwal, {{ $item->belum_diatur }} belum diatur" aria-hidden="true">
                                @for ($hari = 0; $hari < $jumlahHari; $hari++)
                                    <i @class(['op-muncul', 'is-kosong' => $hari >= $item->terjadwal]) style="--d: {{ $hari }}"></i>
                                @endfor
                            </div>
                        </div>
                        <a href="{{ $urlKalender }}" class="btn-secondary btn-sm">Atur jadwal</a>
                    </div>
                @endforeach
                @if ($jadwalBelumLengkap->count() > $batasDaftar)
                    <p class="border-t border-gray-100 px-6 py-3 text-xs text-gray-500">dan {{ $jadwalBelumLengkap->count() - $batasDaftar }} karyawan lainnya.</p>
                @endif
            @endif

            @if ($kontrakSegeraBerakhir->isNotEmpty())
                <div class="op-grup is-info mt-3">
                    <div>
                        <strong>Penempatan berakhir dalam {{ $batasHariKontrak }} hari</strong>
                        Siapkan perpanjangan, rotasi, atau akhiri penempatan tepat waktu.
                    </div>
                </div>
                @foreach ($kontrakSegeraBerakhir->take($batasDaftar) as $item)
                    <div class="op-tugas">
                        <span class="op-tugas-no">{{ str_pad((string) ++$nomorTugas, 2, '0', STR_PAD_LEFT) }}</span>
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="op-avatar-sm" aria-hidden="true">{{ $inisial($item->nama) }}</span>
                            <div class="min-w-0">
                                <p class="truncate font-bold text-gray-800">{{ $item->nama }}</p>
                                <p class="truncate text-[12.5px] text-gray-500">{{ $item->nama_perusahaan }}</p>
                            </div>
                        </div>
                        <span><span class="badge badge-warning">{{ $item->sisa_hari === 0 ? 'Berakhir hari ini' : 'Sisa '.$item->sisa_hari.' hari' }}</span></span>
                        <a href="{{ route('operasional.rotasi.create') }}" class="btn-secondary btn-sm">Rotasi</a>
                    </div>
                @endforeach
            @endif

            @if ($jumlahPerhatian > 0)
                <div class="h-2"></div>
            @endif
        </section>

        <div class="flex flex-col gap-5">
            @if ($pertukaranShiftTersedia)
                <section class="op-kartu-navy" aria-labelledby="judul-pertukaran-shift">
                    <p class="op-lab" style="color: var(--op-gold)">Jadwal Kerja</p>
                    <h3 id="judul-pertukaran-shift" class="mt-1 text-[19px] leading-[26px] font-bold">Pengajuan Pertukaran Shift</h3>
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
                    <p class="relative mt-3 text-[13px] text-white/80">{{ $pertukaranMenunggu > 0 ? $pertukaranMenunggu.' pengajuan perlu diputuskan.' : 'Tidak ada pengajuan yang tertunda.' }}</p>
                    <a href="{{ route('operasional.jadwal-kerja.pertukaran-shift.index') }}" class="op-link relative mt-3" style="color: #fff">Lihat semua pengajuan {!! $panah !!}</a>
                </section>
            @endif

            <section class="op-sheet overflow-hidden" aria-labelledby="judul-akses-cepat">
                <h3 id="judul-akses-cepat" class="px-[22px] pt-5 pb-3 text-[15px] font-bold text-gray-800">Akses cepat</h3>
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

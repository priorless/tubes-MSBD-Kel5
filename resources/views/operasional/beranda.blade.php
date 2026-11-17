@extends('layouts.app')

@section('title', 'Beranda Operasional')

@php
    $pertukaranMenunggu = $ringkasanPertukaranShift['Menunggu'];
    $jumlahPerhatian = $jadwalBelumLengkap->count() + $kontrakSegeraBerakhir->count() + $penempatanLewatMasa->count() + $pertukaranMenunggu;
    $urlPertukaran = static fn (string $status): string => route('operasional.jadwal-kerja.pertukaran-shift.index', ['status' => $status]);
@endphp

@section('content')
    <x-ui.page-header :title="'Selamat datang, '.$namaPengguna"
        description="Ringkasan pekerjaan Operasional hari ini." />

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Klien aktif" :value="$jumlahKlienAktif" hint="Perusahaan dengan kerja sama aktif"
            :href="route('operasional.klien.index')" />
        <x-ui.stat-card label="Karyawan ditempatkan" :value="$jumlahKaryawanDitempatkan" hint="Sedang bekerja di klien hari ini"
            :href="route('operasional.monitoring')" />
        @if ($jadwalTersedia)
            <x-ui.stat-card :label="'Tanggal terjadwal '.$namaBulan" :value="$jumlahTanggalTerjadwal" hint="Tanggal berstatus Kerja atau Libur"
                :href="route('operasional.jadwal-kerja')" />
            <x-ui.stat-card :label="'Jadwal belum lengkap '.$namaBulan" :value="$jadwalBelumLengkap->count()"
                :hint="$jumlahTanggalBelumDiatur.' tanggal belum diatur'" :tone="$jadwalBelumLengkap->isEmpty() ? 'success' : 'warning'"
                :href="route('operasional.jadwal-kerja')" />
        @endif
    </div>

    @if ($pertukaranShiftTersedia)
        <section class="mt-6" aria-labelledby="judul-pertukaran-shift">
            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h3 id="judul-pertukaran-shift" class="text-sm font-semibold text-gray-700">Pengajuan Pertukaran Shift</h3>
                <a href="{{ route('operasional.jadwal-kerja.pertukaran-shift.index') }}" class="text-sm font-medium text-sji-biru hover:underline">Lihat semua &rarr;</a>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-ui.stat-card label="Menunggu persetujuan" :value="$pertukaranMenunggu"
                    :hint="$pertukaranMenunggu > 0 ? 'Perlu diputuskan' : 'Tidak ada yang tertunda'"
                    :tone="$pertukaranMenunggu > 0 ? 'warning' : 'success'" :href="$urlPertukaran('Menunggu')" />
                <x-ui.stat-card label="Disetujui" :value="$ringkasanPertukaranShift['Disetujui']" hint="Jadwal sudah ditukar"
                    :href="$urlPertukaran('Disetujui')" />
                <x-ui.stat-card label="Ditolak" :value="$ringkasanPertukaranShift['Ditolak']" hint="Jadwal tetap seperti semula"
                    :href="$urlPertukaran('Ditolak')" />
            </div>
        </section>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="card xl:col-span-2">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Perlu diperhatikan</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ $jumlahPerhatian === 0 ? 'Tidak ada pekerjaan yang tertunda.' : $jumlahPerhatian.' hal menunggu tindakan.' }}</p>
                </div>
            </div>
            <div class="card-body space-y-6">
                @if ($jumlahPerhatian === 0)
                    <x-ui.empty-state title="Semua beres"
                        message="Jadwal bulan ini sudah lengkap dan tidak ada kontrak yang segera berakhir." />
                @endif

                @if ($pertukaranMenunggu > 0)
                    <section>
                        <h4 class="text-sm font-semibold text-gray-800">Pengajuan pertukaran shift menunggu persetujuan</h4>
                        <p class="text-xs text-gray-500">Periksa detail tiap pengajuan, lalu setujui atau tolak sebelum tanggal shift.</p>
                        <a href="{{ $urlPertukaran('Menunggu') }}" class="mt-2 inline-flex items-center gap-2 text-sm font-medium text-sji-biru hover:underline">
                            <span class="badge badge-warning">{{ $pertukaranMenunggu }}</span> Buka daftar pengajuan &rarr;
                        </a>
                    </section>
                @endif

                @if ($penempatanLewatMasa->isNotEmpty())
                    <section>
                        <h4 class="text-sm font-semibold text-gray-800">Masa penempatan sudah lewat, tetapi masih Aktif</h4>
                        <p class="text-xs text-gray-500">Akhiri penempatan ini di halaman Monitoring Sebaran.</p>
                        <ul class="mt-2 divide-y divide-gray-100 text-sm">
                            @foreach ($penempatanLewatMasa->take($batasDaftar) as $item)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>{{ $item->nama }} <span class="text-gray-500">&mdash; {{ $item->nama_perusahaan }}</span></span>
                                    <span class="badge badge-danger">Berakhir {{ \Carbon\Carbon::parse($item->tgl_selesai)->format('d/m/Y') }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ route('operasional.monitoring') }}" class="mt-2 inline-block text-sm font-medium text-sji-biru hover:underline">Buka Monitoring Sebaran &rarr;</a>
                    </section>
                @endif

                @if ($jadwalBelumLengkap->isNotEmpty())
                    <section>
                        <h4 class="text-sm font-semibold text-gray-800">Jadwal {{ $namaBulan }} belum lengkap</h4>
                        <p class="text-xs text-gray-500">Tanggal yang belum diatur tidak dianggap Libur. Klik nama untuk membuka kalendernya.</p>
                        <ul class="mt-2 divide-y divide-gray-100 text-sm">
                            @foreach ($jadwalBelumLengkap->take($batasDaftar) as $item)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <a href="{{ route('operasional.jadwal-kerja', ['id_karyawan' => $item->id_karyawan, 'bulan' => $bulan]) }}" class="text-sji-biru hover:underline">
                                        {{ $item->nama }} <span class="text-gray-500">&mdash; {{ $item->nama_perusahaan }}</span>
                                    </a>
                                    <span class="badge badge-warning">{{ $item->belum_diatur }} tanggal belum diatur</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($jadwalBelumLengkap->count() > $batasDaftar)
                            <p class="mt-2 text-xs text-gray-500">dan {{ $jadwalBelumLengkap->count() - $batasDaftar }} karyawan lainnya.</p>
                        @endif
                    </section>
                @endif

                @if ($kontrakSegeraBerakhir->isNotEmpty())
                    <section>
                        <h4 class="text-sm font-semibold text-gray-800">Penempatan berakhir dalam {{ $batasHariKontrak }} hari</h4>
                        <p class="text-xs text-gray-500">Siapkan perpanjangan, rotasi, atau akhiri penempatan tepat waktu.</p>
                        <ul class="mt-2 divide-y divide-gray-100 text-sm">
                            @foreach ($kontrakSegeraBerakhir->take($batasDaftar) as $item)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>{{ $item->nama }} <span class="text-gray-500">&mdash; {{ $item->nama_perusahaan }}</span></span>
                                    <span class="badge badge-warning">{{ $item->sisa_hari === 0 ? 'Berakhir hari ini' : 'Sisa '.$item->sisa_hari.' hari' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Akses cepat</h3>
            </div>
            <div class="card-body grid grid-cols-1 gap-2">
                <a href="{{ route('operasional.jadwal-kerja') }}" class="btn-primary justify-start">Atur Jadwal Kerja</a>
                <a href="{{ route('operasional.penempatan.create') }}" class="btn-secondary justify-start">Buat Penempatan Baru</a>
                <a href="{{ route('operasional.klien.create') }}" class="btn-secondary justify-start">Tambah Klien</a>
                <a href="{{ route('operasional.rekap.index') }}" class="btn-secondary justify-start">Lihat Laporan</a>
            </div>
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Detail Penempatan')

@php
    $aktif = $penempatan->status === 'Aktif';
    $labelStatus = ['Aktif' => ['Aktif', 'success'], 'Selesai' => ['Selesai', 'secondary'], 'Rotasi' => ['Dirotasi', 'secondary']];
    [$label, $warna] = $labelStatus[$penempatan->status] ?? [$penempatan->status, 'secondary'];
    $tanggal = static fn (?string $nilai): string => $nilai === null ? 'tanpa batas' : \Carbon\Carbon::parse($nilai)->format('d/m/Y');
@endphp

@section('content')
    <x-ui.page-header :title="$penempatan->nama.' di '.$penempatan->nama_perusahaan"
        :description="'Periode '.$tanggal($penempatan->tgl_mulai).' – '.$tanggal($penempatan->tgl_selesai).' · Status: '.$label">
        <a href="{{ route('operasional.penempatan.index') }}" class="btn-secondary">Daftar Penempatan</a>
        @if ($aktif)
            <a href="{{ route('operasional.rotasi.create', ['id_penempatan' => $penempatan->id]) }}" class="btn-secondary">Rotasi Karyawan</a>
            <a href="{{ route('operasional.jadwal-kerja', ['id_karyawan' => $penempatan->id_karyawan]) }}" class="btn-primary">Atur Jadwal</a>
        @endif
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section class="card">
            <div class="card-header"><h3 class="card-title">Karyawan</h3></div>
            <dl class="card-body space-y-3 text-sm">
                <div><dt class="text-gray-500">Nama</dt><dd class="font-medium text-gray-800">{{ $penempatan->nama }}</dd></div>
                <div><dt class="text-gray-500">NIP</dt><dd class="text-gray-800">{{ $penempatan->nip }}</dd></div>
                <div><dt class="text-gray-500">Jenis layanan</dt><dd class="text-gray-800">{{ $penempatan->nama_layanan ?? '—' }}</dd></div>
                <p class="form-hint">Identitas dan jenis layanan berasal dari data HRD.</p>
            </dl>
        </section>

        <section class="card">
            <div class="card-header"><h3 class="card-title">Tempat kerja</h3></div>
            <dl class="card-body space-y-3 text-sm">
                <div>
                    <dt class="text-gray-500">Klien</dt>
                    <dd><a href="{{ route('operasional.klien.show', $penempatan->id_klien) }}" class="font-medium text-sji-biru hover:underline">{{ $penempatan->nama_perusahaan }}</a></dd>
                </div>
                <div><dt class="text-gray-500">Lokasi kerja</dt><dd class="text-gray-800">{{ $penempatan->lokasi_kerja ?? '—' }}</dd></div>
                <div>
                    <dt class="text-gray-500">Jam kerja standar</dt>
                    <dd class="text-gray-800">
                        @if ($penempatan->jam_datang && $penempatan->jam_pulang)
                            {{ substr($penempatan->jam_datang, 0, 5) }}&ndash;{{ substr($penempatan->jam_pulang, 0, 5) }}
                        @else
                            &mdash;
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        <section class="card">
            <div class="card-header"><h3 class="card-title">Periode &amp; kontrak</h3></div>
            <dl class="card-body space-y-3 text-sm">
                <div><dt class="text-gray-500">Mulai</dt><dd class="text-gray-800">{{ $tanggal($penempatan->tgl_mulai) }}</dd></div>
                <div><dt class="text-gray-500">Selesai</dt><dd class="text-gray-800">{{ $tanggal($penempatan->tgl_selesai) }}</dd></div>
                <div><dt class="text-gray-500">Status penempatan</dt><dd><span class="badge badge-{{ $warna }}">{{ $label }}</span></dd></div>
                <div><dt class="text-gray-500">Status kontrak</dt><dd class="text-gray-800">{{ $kontrak->status ?? 'Belum ada kontrak' }}</dd></div>
            </dl>
        </section>
    </div>

    @if ($jadwalBulanIni !== null)
        <section class="card mt-6">
            <div class="card-body flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="font-semibold text-gray-800">Jadwal {{ $namaBulan }}</h3>
                    <p class="text-sm text-gray-500">
                        {{ $jadwalBulanIni['Kerja'] ?? 0 }} hari kerja dan {{ $jadwalBulanIni['Libur'] ?? 0 }} hari libur sudah diatur.
                        Tanggal lain dalam masa penempatan masih Belum Diatur.
                    </p>
                </div>
                <a href="{{ route('operasional.jadwal-kerja', ['id_karyawan' => $penempatan->id_karyawan]) }}" class="btn-secondary">Buka Kalender</a>
            </div>
        </section>
    @endif

    @if ($aktif)
        <section class="card mt-6 border-red-200">
            <div class="card-body flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <h3 class="font-semibold text-gray-800">Akhiri penempatan</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Pakai bila karyawan berhenti bertugas di klien ini dan tidak pindah ke klien lain.
                        Penempatan dan kontrak menjadi <strong>Selesai</strong>, karyawan kembali <strong>Tersedia</strong> untuk penempatan baru.
                        Bila karyawan pindah klien, gunakan <a href="{{ route('operasional.rotasi.create', ['id_penempatan' => $penempatan->id]) }}" class="text-sji-biru hover:underline">Rotasi Karyawan</a>.
                    </p>
                </div>
                @include('operasional.penempatan._akhiri', [
                    'idPenempatan' => $penempatan->id,
                    'tglMulai' => $penempatan->tgl_mulai,
                    'namaKaryawan' => $penempatan->nama,
                    'namaKlien' => $penempatan->nama_perusahaan,
                    'idInput' => 'tgl_selesai',
                    'tampilkanLabel' => true,
                ])
            </div>
        </section>
    @endif

    <section class="card mt-6">
        <div class="card-header">
            <div>
                <h3 class="card-title">Riwayat penempatan {{ $penempatan->nama }}</h3>
                <p class="mt-1 text-sm text-gray-500">Penempatan lain karyawan ini, termasuk yang sudah selesai atau dirotasi.</p>
            </div>
        </div>
        @if ($riwayatKaryawan->isEmpty())
            <p class="card-body text-sm text-gray-500">Ini satu-satunya penempatan karyawan ini.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Klien</th><th>Periode</th><th>Status</th><th class="text-right">Aksi</th></tr></thead>
                    <tbody>
                        @foreach ($riwayatKaryawan as $item)
                            @php [$labelRiwayat, $warnaRiwayat] = $labelStatus[$item->status] ?? [$item->status, 'secondary']; @endphp
                            <tr>
                                <td>{{ $item->nama_perusahaan }}</td>
                                <td class="whitespace-nowrap">{{ $tanggal($item->tgl_mulai) }} &ndash; {{ $tanggal($item->tgl_selesai) }}</td>
                                <td><span class="badge badge-{{ $warnaRiwayat }}">{{ $labelRiwayat }}</span></td>
                                <td class="text-right"><a href="{{ route('operasional.penempatan.show', $item->id) }}" class="btn-secondary btn-sm">Detail</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection

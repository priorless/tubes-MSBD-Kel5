@extends('layouts.app')

@section('title', 'Monitoring Sebaran Operasional')

@section('content')
    @php
        $ringkasan ??= null;
        $labelSisa = static fn (int $sisa): string => match (true) {
            $sisa < 0 => 'Lewat '.abs($sisa).' hari',
            $sisa === 0 => 'Berakhir hari ini',
            default => 'Sisa '.$sisa.' hari',
        };
    @endphp

    <x-ui.page-header title="Monitoring Sebaran"
        description="Sebaran karyawan aktif per klien dan per jenis layanan.">
        <a href="{{ route('operasional.penempatan.create') }}" class="btn-primary">+ Penempatan Baru</a>
    </x-ui.page-header>

    @if ($ringkasan !== null)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.stat-card label="Karyawan ditempatkan" :value="$ringkasan['jumlah_karyawan']" hint="Dengan penempatan berstatus Aktif" />
            <x-ui.stat-card label="Klien yang dilayani" :value="$ringkasan['per_klien']->count()" hint="Klien dengan minimal satu karyawan aktif"
                :href="route('operasional.klien.index')" />
            <x-ui.stat-card label="Perlu diperiksa" :value="$ringkasan['perlu_diperiksa']->count()" hint="Kontrak kurang dari 30 hari atau sudah lewat"
                :tone="$ringkasan['perlu_diperiksa']->isEmpty() ? 'success' : 'warning'" />
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach (['per_klien' => 'Karyawan per klien', 'per_layanan' => 'Karyawan per jenis layanan'] as $kunci => $judul)
                <section class="card">
                    <div class="card-header"><h3 class="card-title">{{ $judul }}</h3></div>
                    <div class="card-body space-y-3">
                        @forelse ($ringkasan[$kunci] as $baris)
                            @php $persen = $ringkasan['jumlah_penempatan'] > 0 ? (int) round($baris->jumlah / $ringkasan['jumlah_penempatan'] * 100) : 0; @endphp
                            <div>
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-700">{{ $baris->nama }}</span>
                                    <span class="font-medium tabular-nums text-gray-800">{{ $baris->jumlah }} orang</span>
                                </div>
                                <div class="mt-1 h-2 rounded-full bg-gray-100" aria-hidden="true">
                                    <div class="h-2 rounded-full bg-sji-biru" style="width: {{ $persen }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Belum ada penempatan aktif.</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>

        <section class="card mt-6">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Perlu diperiksa</h3>
                    <p class="mt-1 text-sm text-gray-500">Buka detail penempatan untuk merotasi karyawan ke klien lain atau mengakhiri penempatan yang masa kontraknya hampir habis atau sudah lewat.</p>
                </div>
            </div>
            @if ($ringkasan['perlu_diperiksa']->isEmpty())
                <div class="card-body">
                    <x-ui.empty-state title="Tidak ada yang perlu diperiksa" message="Semua penempatan aktif masih memiliki sisa kontrak 30 hari atau lebih." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Karyawan</th><th>Klien</th><th>Berakhir</th><th>Keterangan</th><th class="text-right">Aksi</th></tr></thead>
                        <tbody>
                            @foreach ($ringkasan['perlu_diperiksa'] as $item)
                                <tr>
                                    <td>{{ $item->nama }}<p class="text-xs text-gray-400">{{ $item->nip }}</p></td>
                                    <td>{{ $item->nama_perusahaan }}</td>
                                    <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($item->tgl_selesai)->format('d/m/Y') }}</td>
                                    <td><span class="badge badge-{{ (int) $item->sisa_hari_kontrak < 0 ? 'danger' : 'warning' }}">{{ $labelSisa((int) $item->sisa_hari_kontrak) }}</span></td>
                                    <td class="text-right"><a href="{{ route('operasional.penempatan.show', $item->id_penempatan) }}" class="btn-secondary btn-sm">Lihat Penempatan</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    <div class="card mt-6">
        <div class="card-header">
            <div>
                <h2 class="card-title">Semua penempatan aktif</h2>
                <p class="mt-1 text-sm text-gray-500">Baris berwarna merah menandakan sisa kontrak kurang dari 30 hari.</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>NIP</th>
                        <th>Nama Karyawan</th>
                        <th>Jenis Layanan</th>
                        <th>Klien</th>
                        <th>Lokasi</th>
                        <th>Jam Datang</th>
                        <th>Jam Pulang</th>
                        <th>Periode</th>
                        <th>Sisa Kontrak</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($penempatan as $item)
                        <tr @class(['table-danger' => $item->sisa_hari_kontrak < 30])>
                            <td class="font-mono text-gray-600">{{ $item->nip }}</td>
                            <td class="whitespace-nowrap"><a href="{{ route('operasional.penempatan.show', $item->id_penempatan) }}" class="text-sji-biru hover:underline">{{ $item->nama }}</a></td>
                            <td>{{ $item->nama_layanan }}</td>
                            <td>{{ $item->nama_perusahaan }}</td>
                            <td>{{ $item->lokasi_kerja }}</td>
                            <td class="whitespace-nowrap font-mono">{{ $item->jam_datang ? substr($item->jam_datang, 0, 5) : '-' }}</td>
                            <td class="whitespace-nowrap font-mono">{{ $item->jam_pulang ? substr($item->jam_pulang, 0, 5) : '-' }}</td>
                            <td class="whitespace-nowrap">
                                {{ \Illuminate\Support\Carbon::parse($item->tgl_mulai)->format('d/m/Y') }}
                                &ndash;
                                {{ $item->tgl_selesai ? \Illuminate\Support\Carbon::parse($item->tgl_selesai)->format('d/m/Y') : 'Tanpa batas akhir' }}
                            </td>
                            <td>
                                @if ((int) $item->sisa_hari_kontrak === 9999)
                                    <span class="badge badge-secondary">Tanpa batas akhir</span>
                                @elseif ($item->sisa_hari_kontrak < 30)
                                    <span class="badge badge-danger">{{ $item->sisa_hari_kontrak }} hari</span>
                                @else
                                    <span class="badge badge-success">{{ $item->sisa_hari_kontrak }} hari</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <details class="inline-block text-left">
                                    <summary class="btn-outline-danger btn-sm cursor-pointer list-none">Akhiri&hellip;</summary>
                                    @include('operasional.penempatan._akhiri', [
                                        'idPenempatan' => $item->id_penempatan,
                                        'tglMulai' => $item->tgl_mulai,
                                        'namaKaryawan' => $item->nama,
                                        'namaKlien' => $item->nama_perusahaan,
                                        'idInput' => 'tgl_selesai_'.$item->id_penempatan,
                                        'kelasForm' => 'mt-2 flex justify-end',
                                    ])
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="table-empty">Belum ada penempatan aktif.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $penempatan->firstItem() ?? 0 }}&ndash;{{ $penempatan->lastItem() ?? 0 }} dari {{ $penempatan->total() }} penempatan</span>
            @include('partials.pagination', ['paginator' => $penempatan])
        </div>
    </div>
@endsection

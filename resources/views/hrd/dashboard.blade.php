@extends('layouts.app')

@section('title', 'Dashboard HRD')

@php
    $panah = '<svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
@endphp

@section('content')
    <x-ui.page-header :kicker="now()->locale('id')->translatedFormat('l, j F Y').' · Ringkasan HRD'"
        :title="'Selamat datang, '.auth()->user()->namaTampilan()"
        description="Ringkasan data karyawan, pool, dan dokumen yang perlu ditindaklanjuti.">
        <a href="{{ route('hrd.pool') }}" class="btn-secondary">Pool Karyawan</a>
        <a href="{{ route('hrd.karyawan.create') }}" class="btn-primary">+ Tambah Karyawan</a>
    </x-ui.page-header>

    {{-- Ringkasan: satu strip seperti Beranda Operasional. Dokumen yang hampir kedaluwarsa diberi blok navy. --}}
    <section class="op-headline mb-6" style="--kolom: 3" aria-label="Ringkasan HRD">
        @foreach ([
            ['label' => 'Lapangan Tersedia', 'nilai' => (int) ($ringkasan->lapangan_siap ?? 0), 'keterangan' => 'Karyawan lapangan siap ditempatkan', 'tautan' => route('hrd.pool'), 'teks' => 'Lihat pool', 'perluTindakan' => false],
            ['label' => 'Lapangan Ditempatkan', 'nilai' => (int) ($ringkasan->lapangan_bertugas ?? 0), 'keterangan' => 'Karyawan lapangan bertugas di klien', 'tautan' => route('hrd.karyawan.index', ['kategori' => 'Lapangan', 'status' => 'Ditempatkan']), 'teks' => 'Lihat data', 'perluTindakan' => false],
            ['label' => 'Dokumen < '.$batasHari.' Hari', 'nilai' => (int) $jumlahDokumenHampirHabis, 'keterangan' => 'Dokumen yang akan habis dalam '.$batasHari.' hari', 'tautan' => route('hrd.sertifikat.index', ['hampir_habis' => 1]), 'teks' => 'Tinjau dokumen', 'perluTindakan' => $jumlahDokumenHampirHabis > 0],
        ] as $kartu)
            <div @class(['op-headline-item', 'is-perlu-tindakan' => $kartu['perluTindakan']])>
                @if ($kartu['perluTindakan'])
                    <span class="op-stempel">PERLU TINDAKAN</span>
                @endif
                <span class="stat-label">{{ $kartu['label'] }}</span>
                <span class="op-headline-angka" data-hitung>{{ $kartu['nilai'] }}</span>
                <span class="op-headline-hint">{{ $kartu['keterangan'] }}</span>
                <a href="{{ $kartu['tautan'] }}" class="op-link">{{ $kartu['teks'] }} {!! $panah !!}</a>
            </div>
        @endforeach
    </section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="space-y-6 lg:col-span-7">
            <div class="card border-red-200">
                <div class="card-header">
                    <h3 class="card-title text-red-700">Dokumen hampir kedaluwarsa</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                <th>Dokumen</th>
                                <th>Kedaluwarsa</th>
                                <th>Sisa</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($dokumenHampirHabis as $dokumen)
                                <tr>
                                    <td>{{ $dokumen->nama }} <span class="text-xs text-gray-400">({{ $dokumen->nip }})</span></td>
                                    <td>{{ $dokumen->nama_dokumen }}</td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($dokumen->tgl_kadaluarsa)->format('d/m/Y') }}</td>
                                    <td>
                                        <span class="badge badge-danger">{{ (int) $dokumen->sisa_hari === 0 ? 'Kedaluwarsa' : $dokumen->sisa_hari.' hari' }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="table-empty">Tidak ada dokumen yang akan habis dalam {{ $batasHari }} hari.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Karyawan terbaru</h3>
                    <a href="{{ route('hrd.karyawan.create') }}" class="btn-secondary btn-sm">+ Tambah</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>NIP</th>
                                <th>Nama</th>
                                <th>Kategori</th>
                                <th>Status</th>
                                <th>Tgl Masuk</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($karyawanTerbaru as $item)
                                <tr>
                                    <td class="font-mono text-gray-600">{{ $item->nip }}</td>
                                    <td><a href="{{ route('hrd.karyawan.edit', $item->id) }}" class="font-medium text-sji-biru hover:underline">{{ $item->nama }}</a></td>
                                    <td>{{ $item->kategori }}</td>
                                    <td>{{ $item->status }}</td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($item->tgl_masuk)->format('d/m/Y') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="table-empty">Belum ada karyawan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-6 lg:col-span-5">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Komposisi karyawan Lapangan per layanan</h3>
                </div>
                <div class="card-body space-y-4">
                    <p class="text-sm text-gray-500">Total seluruh karyawan terdaftar: <span class="font-semibold text-gray-800">{{ $totalKaryawan }}</span></p>
                    @php $jumlahLapangan = max(1, $komposisiLayanan->sum('jumlah')); @endphp
                    @foreach ($komposisiLayanan as $layanan)
                        <div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-700">{{ $layanan->nama_layanan }}</span>
                                <span><span class="font-semibold">{{ $layanan->jumlah }}</span> orang</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full bg-sji-biru" style="width: {{ round($layanan->jumlah / $jumlahLapangan * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Pool tersedia &mdash; terdaftar paling lama</h3>
                </div>
                <ul class="divide-y divide-gray-100">
                    @forelse ($poolTeratas as $item)
                        <li class="flex items-center justify-between px-6 py-3 text-sm">
                            <span>{{ $item->nama }} <span class="text-gray-400">&middot; {{ $item->nama_layanan }}</span></span>
                            <span class="text-gray-500">sejak {{ \Illuminate\Support\Carbon::parse($item->tgl_masuk)->format('d/m/Y') }}</span>
                        </li>
                    @empty
                        <li class="table-empty">Pool kosong.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection

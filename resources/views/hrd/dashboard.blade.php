@extends('layouts.app')

@section('title', 'Dashboard HRD')

@section('content')
    <div class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Lapangan Tersedia', 'nilai' => $ringkasan->lapangan_siap ?? 0, 'warna' => 'text-sky-700', 'tautan' => route('hrd.pool'), 'teks' => 'Lihat pool'],
            ['label' => 'Lapangan Ditempatkan', 'nilai' => $ringkasan->lapangan_bertugas ?? 0, 'warna' => 'text-sji-biru', 'tautan' => route('hrd.karyawan.index', ['kategori' => 'Lapangan', 'status' => 'Ditempatkan']), 'teks' => 'Lihat data'],
            ['label' => 'Staf Internal Aktif', 'nilai' => $ringkasan->internal_aktif ?? 0, 'warna' => 'text-emerald-600', 'tautan' => route('hrd.karyawan.index', ['kategori' => 'Internal']), 'teks' => 'Lihat data'],
            ['label' => 'Dokumen < '.$batasHari.' Hari', 'nilai' => $jumlahDokumenHampirHabis, 'warna' => 'text-red-500', 'tautan' => route('hrd.sertifikat.index', ['hampir_habis' => 1]), 'teks' => 'Tinjau dokumen'],
        ] as $kartu)
            <div class="stat-card flex flex-col">
                <h3 class="stat-label">{{ $kartu['label'] }}</h3>
                <p class="stat-value {{ $kartu['warna'] }}">{{ $kartu['nilai'] }}</p>
                <a href="{{ $kartu['tautan'] }}" class="mt-3 text-sm font-medium text-sji-biru hover:underline">{{ $kartu['teks'] }} &rarr;</a>
            </div>
        @endforeach
    </div>

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

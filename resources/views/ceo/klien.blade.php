@extends('layouts.app')

@section('title', 'Klien')
@section('page-title', 'Klien')

@section('content')
    <x-ui.page-header title="Klien"
        description="Perusahaan mitra PT Satu Jaya Indonesia beserta karyawan yang sedang ditempatkan. Halaman ini hanya untuk dilihat." />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Jumlah klien" :value="$jumlahKlien" hint="Seluruh klien yang tercatat" />
        <x-ui.stat-card label="Klien aktif" :value="$jumlahKlienAktif" hint="Kerja sama berstatus Aktif" tone="success" />
        <x-ui.stat-card label="Orang ditempatkan" :value="$jumlahDitempatkan" hint="Karyawan dengan penempatan berstatus Aktif" />
    </div>

    <div class="card">
        @if ($klien->isEmpty())
            <div class="card-body">
                <x-ui.empty-state title="Belum ada klien" message="Data klien diisi oleh tim Operasional atau HRD." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Perusahaan</th>
                            <th>Status</th>
                            <th class="text-right">Ditempatkan</th>
                            <th>Nama yang Ditempatkan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($klien as $item)
                            @php $penempatan = $penempatanPerKlien->get($item->id, collect()); @endphp
                            <tr class="align-top">
                                <td class="text-gray-500">{{ $loop->iteration }}</td>
                                <td>
                                    <p class="font-medium text-gray-800">{{ $item->nama_perusahaan }}</p>
                                    <p class="text-xs text-gray-400">{{ $item->kode_klien }}@if ($item->alamat) &middot; {{ \Illuminate\Support\Str::limit($item->alamat, 60) }}@endif</p>
                                    @if ($item->pic_nama)
                                        <p class="text-xs text-gray-400">PIC: {{ $item->pic_nama }}@if ($item->pic_telp) &middot; {{ $item->pic_telp }}@endif</p>
                                    @endif
                                </td>
                                <td><span class="badge badge-{{ $item->status === 'Aktif' ? 'success' : 'secondary' }}">{{ $item->status }}</span></td>
                                <td class="text-right font-semibold tabular-nums">{{ $penempatan->count() }} orang</td>
                                <td>
                                    @forelse ($penempatan as $orang)
                                        <p class="text-sm text-gray-700">
                                            {{ $orang->nama }}
                                            <span class="text-xs text-gray-400">{{ $orang->nip }}@if ($orang->nama_layanan) &middot; {{ $orang->nama_layanan }}@endif @if ($orang->lokasi_kerja) &middot; {{ $orang->lokasi_kerja }}@endif</span>
                                        </p>
                                    @empty
                                        <span class="text-xs text-gray-400">Belum ada yang ditempatkan</span>
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

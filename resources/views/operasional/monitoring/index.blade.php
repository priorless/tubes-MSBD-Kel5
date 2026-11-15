@extends('layouts.app')

@section('title', 'Monitoring Sebaran Operasional')

@section('content')
    <div class="callout callout-info mb-6">
        <p class="font-semibold">Pemantauan penempatan aktif</p>
        <p class="mt-1">Baris berwarna merah menandakan sisa kontrak kurang dari 30 hari.</p>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Sebaran karyawan aktif</h2>
            <a href="{{ route('operasional.penempatan.create') }}" class="btn-primary btn-sm">+ Buat Penempatan</a>
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
                    </tr>
                </thead>
                <tbody>
                    @forelse ($penempatan as $item)
                        <tr @class(['table-danger' => $item->sisa_hari_kontrak < 30])>
                            <td class="font-mono text-gray-600">{{ $item->nip }}</td>
                            <td class="whitespace-nowrap">{{ $item->nama }}</td>
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
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="table-empty">Belum ada penempatan aktif.</td>
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

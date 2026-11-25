@extends('layouts.app')

@section('title', 'Klien')
@section('page-title', 'Klien')

@section('content')
    <x-ui.page-header title="Klien"
        description="Perusahaan mitra PT Satu Jaya Indonesia beserta karyawan yang sedang ditempatkan. Halaman ini hanya untuk dilihat." />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card label="Jumlah klien" :value="$jumlahKlien" hint="Seluruh klien yang tercatat" />
        <x-ui.stat-card label="Klien aktif" :value="$jumlahKlienAktif" hint="Kerja sama berstatus Aktif" tone="success" />
        <x-ui.stat-card label="Orang ditempatkan" :value="$jumlahDitempatkan" hint="Karyawan dengan penempatan berstatus Aktif" />
        <x-ui.stat-card label="Total profit" :value="'Rp ' . number_format($totalProfit, 0, ',', '.')" :hint="'Klien aktif, ' . $periode->translatedFormat('F Y')" tone="success" />
    </div>

    <form method="GET" action="{{ route('ceo.klien') }}" class="mb-4 flex flex-wrap items-end gap-2">
        <div>
            <label for="periode" class="form-label">Periode profit</label>
            <input type="month" id="periode" name="periode" value="{{ $periode->format('Y-m') }}" class="form-input">
        </div>
        <button type="submit" class="btn-primary">Tampilkan</button>
    </form>

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
                            <th class="text-right">Profit {{ $periode->translatedFormat('M Y') }}</th>
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
                                <td class="text-right font-semibold tabular-nums">
                                    @if ($profitPerKlien->has($item->id))
                                        Rp {{ number_format($profitPerKlien->get($item->id), 0, ',', '.') }}
                                    @else
                                        <span class="text-xs text-gray-400">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

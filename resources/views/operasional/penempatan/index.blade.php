@extends('layouts.app')

@section('title', 'Penempatan Karyawan')

@php
    $sedangMenyaring = $filter['q'] !== '' || $filter['id_klien'] !== null || $filter['id_jenis_layanan'] !== null || $filter['status'] !== null;
    $parameterTanpaStatus = array_filter(['q' => $filter['q'], 'id_klien' => $filter['id_klien'], 'id_jenis_layanan' => $filter['id_jenis_layanan']], static fn ($nilai) => $nilai !== null && $nilai !== '');
    $labelStatus = ['Aktif' => ['Aktif', 'success'], 'Selesai' => ['Selesai', 'secondary'], 'Rotasi' => ['Dirotasi', 'secondary']];
@endphp

@section('content')
    <x-ui.page-header title="Penempatan Karyawan"
        description="Penempatan karyawan di klien beserta lokasi dan periodenya.">
        <a href="{{ route('operasional.penempatan.create') }}" class="btn-primary">+ Penempatan Baru</a>
    </x-ui.page-header>

    <div class="card">
        <form method="GET" action="{{ route('operasional.penempatan.index') }}" class="card-body grid grid-cols-1 items-end gap-3 border-b border-gray-200 md:grid-cols-2 xl:grid-cols-5">
            <div class="xl:col-span-2">
                <label for="q" class="form-label">Cari</label>
                <input id="q" name="q" type="search" class="form-input" value="{{ $filter['q'] }}" placeholder="Nama karyawan, NIP, atau lokasi kerja">
            </div>
            <div>
                <label for="id_klien" class="form-label">Klien</label>
                <select id="id_klien" name="id_klien" class="form-input">
                    <option value="">Semua klien</option>
                    @foreach ($daftarKlien as $item)
                        <option value="{{ $item->id }}" @selected($filter['id_klien'] === (int) $item->id)>{{ $item->nama_perusahaan }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="id_jenis_layanan" class="form-label">Jenis layanan</label>
                <select id="id_jenis_layanan" name="id_jenis_layanan" class="form-input">
                    <option value="">Semua layanan</option>
                    @foreach ($daftarLayanan as $item)
                        <option value="{{ $item->id }}" @selected($filter['id_jenis_layanan'] === (int) $item->id)>{{ $item->nama_layanan }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($filter['status'] !== null)
                    <input type="hidden" name="status" value="{{ $filter['status'] }}">
                @endif
                <button type="submit" class="btn-secondary">Terapkan</button>
                @if ($sedangMenyaring)
                    <a href="{{ route('operasional.penempatan.index') }}" class="btn-secondary">Hapus filter</a>
                @endif
            </div>
        </form>

        <nav class="card-body flex flex-wrap gap-2 border-b border-gray-200 py-3" aria-label="Filter status penempatan">
            @foreach (['' => 'Semua', 'Aktif' => 'Aktif', 'Selesai' => 'Selesai', 'Rotasi' => 'Dirotasi'] as $nilai => $label)
                @php $aktif = ($filter['status'] ?? '') === $nilai; @endphp
                <a href="{{ route('operasional.penempatan.index', array_filter([...$parameterTanpaStatus, 'status' => $nilai])) }}"
                   @class(['btn-sm', 'btn-primary' => $aktif, 'btn-secondary' => ! $aktif]) @if ($aktif) aria-current="page" @endif>
                    {{ $label }} ({{ $nilai === '' ? $jumlahPerStatus->sum() : ($jumlahPerStatus[$nilai] ?? 0) }})
                </a>
            @endforeach
        </nav>

        @if ($penempatan->isEmpty())
            <div class="card-body">
                @if ($sedangMenyaring)
                    <x-ui.empty-state title="Tidak ada penempatan yang cocok" message="Ubah pencarian atau filter yang dipilih.">
                        <a href="{{ route('operasional.penempatan.index') }}" class="btn-secondary btn-sm">Tampilkan semua penempatan</a>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state title="Belum ada penempatan" message="Tempatkan karyawan lapangan dari data HRD ke klien aktif.">
                        <a href="{{ route('operasional.penempatan.create') }}" class="btn-primary btn-sm">+ Penempatan Baru</a>
                    </x-ui.empty-state>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Karyawan</th>
                            <th>Klien</th>
                            <th>Jenis Layanan</th>
                            <th>Lokasi Kerja</th>
                            <th>Periode</th>
                            <th>Status</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($penempatan as $item)
                            @php [$label, $warna] = $labelStatus[$item->status] ?? [$item->status, 'secondary']; @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('operasional.penempatan.show', $item->id) }}" class="font-medium text-sji-biru hover:underline">{{ $item->nama }}</a>
                                    <p class="text-xs text-gray-400">{{ $item->nip }}</p>
                                </td>
                                <td>{{ $item->nama_perusahaan }}</td>
                                <td>{{ $item->nama_layanan ?? '—' }}</td>
                                <td>{{ $item->lokasi_kerja ?? '—' }}</td>
                                <td class="whitespace-nowrap">
                                    {{ \Carbon\Carbon::parse($item->tgl_mulai)->format('d/m/Y') }} &ndash;
                                    {{ $item->tgl_selesai === null ? 'tanpa batas' : \Carbon\Carbon::parse($item->tgl_selesai)->format('d/m/Y') }}
                                </td>
                                <td><span class="badge badge-{{ $warna }}">{{ $label }}</span></td>
                                <td class="text-right"><a href="{{ route('operasional.penempatan.show', $item->id) }}" class="btn-secondary btn-sm">Detail</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer">
                <span>Menampilkan {{ $penempatan->firstItem() }}&ndash;{{ $penempatan->lastItem() }} dari {{ $penempatan->total() }} penempatan</span>
                @include('partials.pagination', ['paginator' => $penempatan])
            </div>
        @endif
    </div>
@endsection

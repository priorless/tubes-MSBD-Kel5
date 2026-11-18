@extends('layouts.app')

@section('title', 'Komponen Gaji')
@section('page-title', 'Komponen Gaji')

@section('content')
    <div class="callout callout-info mb-6">
        <p class="font-semibold">Data otomatis hanya-baca</p>
        <p class="mt-1 text-sm">Gaji pokok dan potongan absensi mengikuti sumber serta rumus Generate Payroll yang berlaku. Hanya Tunjangan dan Potongan Pinjaman / Koperasi yang diisi manual.</p>
        <p class="mt-2 text-sm"><span class="font-semibold">Upah lembur:</span> dari absensi valid yang sudah check-in dan check-out. Lembur dihitung per jam penuh setelah jam selesai shift (kelebihan di bawah 60 menit tidak dihitung). Upah per jam = gaji pokok / 173; jam pertama 1,5&times;, jam berikutnya 2&times;.</p>
        <p class="mt-1 text-sm"><span class="font-semibold">Potongan BPJS:</span> persentase BPJS klien penempatan &times; gaji pokok, sesuai pengaturan klien (Operasional &rsaquo; Klien). Bila persentase belum diatur, potongan tidak dihitung.</p>
        <p class="mt-1 text-sm"><span class="font-semibold">Upah back-up:</span> Rp0 karena data penugasan Back-up (Pengganti Kosong) beserta persetujuan Operasional dan aturan nominalnya belum tersedia di sistem. Tukar shift (barter) tidak termasuk back-up.</p>
    </div>

    <div class="card mb-6">
        <div class="card-header"><h2 class="card-title">Filter karyawan</h2></div>
        <form method="GET" action="{{ route('keuangan.komponen-gaji.index') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-5">
            <div>
                <label for="bulan" class="form-label">Periode Bulan</label>
                <select name="bulan" id="bulan" class="form-input w-full">
                    @foreach ($daftarBulan as $angka => $nama)
                        <option value="{{ $angka }}" @selected($angka === $bulan)>{{ $nama }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tahun" class="form-label">Periode Tahun</label>
                <input type="number" name="tahun" id="tahun" value="{{ $tahun }}" min="2000" max="2100" class="form-input w-full">
            </div>
            <div>
                <label for="penempatan" class="form-label">Penempatan</label>
                <select name="penempatan" id="penempatan" class="form-input w-full" required>
                    <option value="eksternal" @selected($penempatan === 'eksternal')>Eksternal (Semua Klien)</option>
                    @foreach ($klien as $item)
                        <option value="klien:{{ $item->id }}" @selected($penempatan === 'klien:'.$item->id)>Klien: {{ $item->nama_perusahaan }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="jenis_pekerjaan" class="form-label">Jenis Pekerjaan</label>
                <select name="jenis_pekerjaan" id="jenis_pekerjaan" class="form-input w-full">
                    <option value="">Semua jenis pekerjaan</option>
                    @foreach ($jenisPekerjaan as $jenis)
                        <option value="{{ $jenis->id }}" @selected($idJenisPekerjaan === (int) $jenis->id)>{{ $jenis->nama_layanan }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end"><button type="submit" class="btn-secondary w-full">Tampilkan</button></div>
        </form>
    </div>

    <form method="POST" action="{{ route('keuangan.komponen-gaji.store') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="bulan" value="{{ $bulan }}">
        <input type="hidden" name="tahun" value="{{ $tahun }}">
        <input type="hidden" name="penempatan" value="{{ $penempatan }}">
        <input type="hidden" name="jenis_pekerjaan" value="{{ $idJenisPekerjaan ?? '' }}">

        <div class="card">
            <div class="card-header flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="card-title">Komponen periode {{ $daftarBulan[$bulan] }} {{ $tahun }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ $karyawan->count() }} karyawan. Data otomatis tidak dapat diedit.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table min-w-[1650px] table-fixed">
                    <colgroup>
                        <col class="w-28"><col class="w-48"><col class="w-40"><col class="w-36">
                        <col class="w-40"><col class="w-36"><col class="w-36"><col class="w-40">
                        <col class="w-44"><col class="w-56">
                    </colgroup>
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="sticky left-0 z-30 bg-gray-50">NIP</th>
                            <th class="sticky left-28 z-30 bg-gray-50">Nama Karyawan</th>
                            <th class="sticky left-[19rem] z-30 bg-gray-50">Jenis Pekerjaan</th>
                            <th class="text-right">Gaji Pokok</th>
                            <th class="text-right">Tunjangan</th>
                            <th class="text-right">Upah Back-up</th>
                            <th class="text-right">Upah Lembur</th>
                            <th class="text-right">Potongan Absensi</th>
                            <th class="text-right">Potongan BPJS</th>
                            <th class="text-right">Potongan Pinjaman / Koperasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($karyawan as $item)
                            <tr>
                                <td class="sticky left-0 z-10 bg-white font-mono text-gray-600">{{ $item->nip }}</td>
                                <td class="sticky left-28 z-10 bg-white whitespace-nowrap">{{ $item->nama }}</td>
                                <td class="sticky left-[19rem] z-10 bg-white">{{ $item->nama_layanan ?? 'Belum diatur' }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">Rp {{ number_format($item->gaji_pokok, 0, ',', '.') }}@if ($item->status_gaji)<span class="block text-xs text-amber-700">{{ $item->status_gaji }}</span>@endif</td>
                                <td>
                                    <label class="sr-only" for="tunjangan-{{ $item->id }}">Tunjangan {{ $item->nama }}</label>
                                    <div class="grid grid-cols-[2rem_minmax(0,1fr)] items-center gap-1"><span>Rp</span><input id="tunjangan-{{ $item->id }}" class="form-input min-w-0 w-full text-right tabular-nums" type="number" name="komponen[{{ $item->id }}][tunjangan]" min="0" max="9999999999.99" step="0.01" value="{{ old('komponen.'.$item->id.'.tunjangan', $item->tunjangan) }}" required></div>
                                </td>
                                <td class="whitespace-nowrap text-right tabular-nums">Rp {{ number_format($item->upah_backup, 0, ',', '.') }}<span class="block text-xs text-gray-400">Aturan nominal belum tersedia</span></td>
                                <td class="whitespace-nowrap text-right tabular-nums">Rp {{ number_format($item->upah_lembur, 0, ',', '.') }}@if ($item->jam_lembur > 0)<span class="block text-xs text-gray-400">{{ $item->jam_lembur }} jam lembur</span>@endif</td>
                                <td class="whitespace-nowrap text-right text-red-600 tabular-nums">Rp {{ number_format($item->potongan_absen, 0, ',', '.') }}</td>
                                <td class="text-right tabular-nums">
                                    @if ($item->potongan_bpjs !== null)
                                        <span class="whitespace-nowrap text-red-600">Rp {{ number_format($item->potongan_bpjs, 0, ',', '.') }}</span>
                                        <span class="block text-xs text-gray-400">{{ $item->keterangan_bpjs }}</span>
                                    @else
                                        <span class="text-gray-400">&mdash;</span>
                                        <span class="block text-xs text-amber-700">{{ $item->keterangan_bpjs }}</span>
                                    @endif
                                </td>
                                <td>
                                    <label class="sr-only" for="pinjaman-{{ $item->id }}">Potongan Pinjaman / Koperasi {{ $item->nama }}</label>
                                    <div class="grid grid-cols-[2rem_minmax(0,1fr)] items-center gap-1"><span>Rp</span><input id="pinjaman-{{ $item->id }}" class="form-input min-w-0 w-full text-right tabular-nums" type="number" name="komponen[{{ $item->id }}][potongan_pinjaman]" min="0" max="9999999999.99" step="0.01" value="{{ old('komponen.'.$item->id.'.potongan_pinjaman', $item->potongan_pinjaman) }}" required></div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="table-empty">Tidak ada karyawan yang sesuai untuk periode, klien, dan jenis pekerjaan ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($karyawan->isNotEmpty())
                <div class="card-footer flex justify-end"><button type="submit" class="btn-primary px-6 py-2.5">Simpan Komponen Gaji</button></div>
            @endif
        </div>
    </form>
@endsection

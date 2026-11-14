@extends('layouts.app')

@section('title', 'Payroll')
@section('page-title', 'Payroll Karyawan')

@section('content')
    <div class="callout callout-info mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="max-w-3xl">
            <p class="font-semibold">Generate payroll</p>
            <p class="mt-1">
                Payroll dihitung otomatis oleh database untuk karyawan Internal berstatus Aktif dan karyawan Lapangan berstatus Ditempatkan,
                termasuk lembur dan potongan absensi. Karyawan yang sudah memiliki payroll pada periode ini akan dilewati.
            </p>
        </div>
        <form method="POST" action="{{ route('keuangan.payroll.generate') }}"
              onsubmit="return confirm(@js('Generate payroll untuk '.$daftarBulan[$bulanIni].' '.$tahunIni.'?'));">
            @csrf
            <button type="submit" class="btn-primary">
                Generate Payroll Bulan Ini ({{ $daftarBulan[$bulanIni] }} {{ $tahunIni }})
            </button>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Slip gaji periode {{ $daftarBulan[$bulan] }} {{ $tahun }}</h2>
            <form method="GET" action="{{ route('keuangan.payroll.index') }}" class="flex flex-wrap items-center gap-2">
                <label for="bulan" class="sr-only">Bulan</label>
                <select name="bulan" id="bulan" class="form-input w-auto py-1.5">
                    @foreach ($daftarBulan as $angka => $nama)
                        <option value="{{ $angka }}" @selected($angka === $bulan)>{{ $nama }}</option>
                    @endforeach
                </select>
                <label for="tahun" class="sr-only">Tahun</label>
                <input type="number" name="tahun" id="tahun" value="{{ $tahun }}" min="2000" max="2100" class="form-input w-24 py-1.5">
                <button type="submit" class="btn-secondary btn-sm">Tampilkan</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>NIP</th>
                        <th>Nama</th>
                        <th>Kategori</th>
                        <th class="text-right">Gaji Pokok</th>
                        <th class="text-right">Tunjangan</th>
                        <th class="text-right">Lembur</th>
                        <th class="text-right">Potongan Absen</th>
                        <th class="text-right">Total Gaji</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payroll as $item)
                        <tr>
                            <td class="font-mono text-gray-600">{{ $item->nip }}</td>
                            <td class="whitespace-nowrap">{{ $item->nama }}</td>
                            <td>
                                <span @class(['badge', 'badge-info' => $item->kategori === 'Internal', 'badge-secondary' => $item->kategori !== 'Internal'])>
                                    {{ $item->kategori }}
                                </span>
                            </td>
                            <td class="text-right whitespace-nowrap tabular-nums">Rp {{ number_format((float) $item->gaji_pokok, 0, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums">Rp {{ number_format((float) $item->tunjangan, 0, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums">Rp {{ number_format((float) $item->total_lembur, 0, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap text-red-600 tabular-nums">Rp {{ number_format((float) $item->potongan_absen, 0, ',', '.') }}</td>
                            <td class="text-right font-semibold whitespace-nowrap text-gray-800 tabular-nums">Rp {{ number_format((float) $item->total_gaji, 0, ',', '.') }}</td>
                            <td class="whitespace-nowrap">
                                @if ($item->status_bayar === 'Sudah Dibayar')
                                    <span class="badge badge-success">Sudah Dibayar</span>
                                    <p class="mt-0.5 text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($item->tgl_bayar)->format('d/m/Y') }}</p>
                                @else
                                    <span class="badge badge-warning">Belum Dibayar</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if ($item->status_bayar !== 'Sudah Dibayar')
                                    <form method="POST" action="{{ route('keuangan.payroll.cair', $item->id) }}"
                                          onsubmit="return confirm(@js('Tandai gaji '.$item->nama.' sudah cair hari ini?'));">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn-success btn-sm">Tandai Cair</button>
                                    </form>
                                @else
                                    <span class="text-gray-400">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="table-empty">Belum ada data payroll untuk periode {{ $daftarBulan[$bulan] }} {{ $tahun }}.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $payroll->firstItem() ?? 0 }}&ndash;{{ $payroll->lastItem() ?? 0 }} dari {{ $payroll->total() }} slip gaji</span>
            @include('partials.pagination', ['paginator' => $payroll])
        </div>
    </div>
@endsection

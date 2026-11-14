@extends('layouts.adminlte')

@section('title', 'Payroll')
@section('modul', 'Keuangan')
@section('heading', 'Payroll Karyawan')
@section('breadcrumb', 'Payroll')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="callout callout-info">
                <h5><i class="fas fa-info-circle mr-1"></i> Generate payroll</h5>
                <p class="mb-2">
                    Payroll dihitung otomatis oleh database untuk karyawan Internal berstatus Aktif dan karyawan Lapangan berstatus Ditempatkan,
                    termasuk lembur dan potongan absensi. Karyawan yang sudah memiliki payroll pada periode ini akan dilewati.
                </p>
                <form method="POST" action="{{ route('keuangan.payroll.generate') }}"
                      onsubmit="return confirm(@js('Generate payroll untuk '.$daftarBulan[$bulanIni].' '.$tahunIni.'?'));">
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-cogs mr-1"></i> Generate Payroll Bulan Ini
                        <small>({{ $daftarBulan[$bulanIni] }} {{ $tahunIni }})</small>
                    </button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Slip gaji periode {{ $daftarBulan[$bulan] }} {{ $tahun }}</h3>
                    <div class="card-tools">
                        <form method="GET" action="{{ route('keuangan.payroll.index') }}" class="form-inline">
                            <label for="bulan" class="sr-only">Bulan</label>
                            <select name="bulan" id="bulan" class="form-control form-control-sm mr-1">
                                @foreach ($daftarBulan as $angka => $nama)
                                    <option value="{{ $angka }}" @selected($angka === $bulan)>{{ $nama }}</option>
                                @endforeach
                            </select>
                            <label for="tahun" class="sr-only">Tahun</label>
                            <input type="number" name="tahun" id="tahun" value="{{ $tahun }}" min="2000" max="2100"
                                   class="form-control form-control-sm mr-1" style="width: 90px;">
                            <button type="submit" class="btn btn-default btn-sm"><i class="fas fa-filter mr-1"></i> Tampilkan</button>
                        </form>
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover text-nowrap">
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
                                    <td>{{ $item->nip }}</td>
                                    <td>{{ $item->nama }}</td>
                                    <td>
                                        <span @class(['badge', 'badge-info' => $item->kategori === 'Internal', 'badge-secondary' => $item->kategori !== 'Internal'])>
                                            {{ $item->kategori }}
                                        </span>
                                    </td>
                                    <td class="text-right">Rp {{ number_format((float) $item->gaji_pokok, 0, ',', '.') }}</td>
                                    <td class="text-right">Rp {{ number_format((float) $item->tunjangan, 0, ',', '.') }}</td>
                                    <td class="text-right">Rp {{ number_format((float) $item->total_lembur, 0, ',', '.') }}</td>
                                    <td class="text-right text-danger">Rp {{ number_format((float) $item->potongan_absen, 0, ',', '.') }}</td>
                                    <td class="text-right font-weight-bold">Rp {{ number_format((float) $item->total_gaji, 0, ',', '.') }}</td>
                                    <td>
                                        @if ($item->status_bayar === 'Sudah Dibayar')
                                            <span class="badge badge-success">Sudah Dibayar</span>
                                            <small class="text-muted d-block">{{ \Illuminate\Support\Carbon::parse($item->tgl_bayar)->format('d/m/Y') }}</small>
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
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    <i class="fas fa-check mr-1"></i> Tandai Cair
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-muted">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted p-4">
                                        Belum ada data payroll untuk periode {{ $daftarBulan[$bulan] }} {{ $tahun }}.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer clearfix">
                    <span class="text-muted">Menampilkan {{ $payroll->firstItem() ?? 0 }}&ndash;{{ $payroll->lastItem() ?? 0 }} dari {{ $payroll->total() }} slip gaji</span>
                    <div class="float-right">
                        @include('partials.pagination', ['paginator' => $payroll])
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

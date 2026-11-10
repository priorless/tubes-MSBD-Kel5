@extends('layouts.tenaga-kerja')

@section('title', 'Slip Gaji')
@section('heading', 'Slip Gaji')

@section('content')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Riwayat slip gaji</h3>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Gaji pokok</th>
                        <th>Tunjangan</th>
                        <th>Lembur</th>
                        <th>Potongan absensi</th>
                        <th>Total gaji</th>
                        <th>Status pembayaran</th>
                        <th>Tanggal dibayar</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($slipGaji as $slip)
                        <tr>
                            <td>{{ sprintf('%02d/%d', $slip->periode_bulan, $slip->periode_tahun) }}</td>
                            <td>Rp {{ number_format((float) $slip->gaji_pokok, 0, ',', '.') }}</td>
                            <td>Rp {{ number_format((float) $slip->tunjangan, 0, ',', '.') }}</td>
                            <td>Rp {{ number_format((float) $slip->total_lembur, 0, ',', '.') }}</td>
                            <td>Rp {{ number_format((float) $slip->potongan_absen, 0, ',', '.') }}</td>
                            <td><strong>Rp {{ number_format((float) $slip->total_gaji, 0, ',', '.') }}</strong></td>
                            <td>{{ $slip->status_bayar }}</td>
                            <td>{{ $slip->tgl_bayar ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center">Belum ada data slip gaji.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

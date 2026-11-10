@extends('layouts.tenaga-kerja')

@section('title', 'Riwayat Absensi')
@section('heading', 'Riwayat Absensi')

@section('content')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Rekap absensi bulanan</h3>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Total catatan</th>
                        <th>Hadir</th>
                        <th>Terlambat</th>
                        <th>Tidak hadir</th>
                        <th>Total lembur (jam)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($riwayat as $baris)
                        <tr>
                            <td>{{ sprintf('%02d/%d', $baris->bulan, $baris->tahun) }}</td>
                            <td>{{ $baris->total_baris }}</td>
                            <td>{{ $baris->hari_hadir }}</td>
                            <td>{{ $baris->hari_terlambat }}</td>
                            <td>{{ $baris->hari_tidak_hadir }}</td>
                            <td>{{ $baris->total_jam_lembur }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center">Belum ada riwayat absensi.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

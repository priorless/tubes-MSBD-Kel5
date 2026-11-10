@extends('layouts.tenaga-kerja')

@section('title', 'Absensi')
@section('heading', 'Absensi Harian')

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">Absensi {{ now()->format('d-m-Y') }}</h3>
                </div>
                <div class="card-body">
                    <p class="mb-4">Silakan catat waktu masuk dan pulang kerja Anda.</p>
                    @if ($absensiHariIni)
                        <dl class="row">
                            <dt class="col-sm-4">Jam masuk</dt>
                            <dd class="col-sm-8">{{ $absensiHariIni->jam_masuk ?? '-' }}</dd>
                            <dt class="col-sm-4">Jam keluar</dt>
                            <dd class="col-sm-8">{{ $absensiHariIni->jam_keluar ?? 'Belum check-out' }}</dd>
                        </dl>
                    @else
                        <p class="text-muted">Anda belum melakukan absensi hari ini.</p>
                    @endif

                    @if (! $absensiHariIni)
                        <form method="POST" action="{{ route('tenaga-kerja.absensi.check-in') }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-sign-in-alt mr-1"></i> Check-in
                            </button>
                        </form>
                    @elseif (! $absensiHariIni->jam_keluar)
                        <form method="POST" action="{{ route('tenaga-kerja.absensi.check-out') }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-sign-out-alt mr-1"></i> Check-out
                            </button>
                        </form>
                    @else
                        <div class="alert alert-info mb-0">Absensi hari ini sudah lengkap.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@extends('layouts.adminlte')

@section('title', 'Dashboard HRD')
@section('modul', 'HRD')
@section('heading', 'Dashboard HRD')
@section('breadcrumb', 'Dashboard')

@section('content')
    <div class="row">
        <div class="col-lg-3 col-6">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>{{ $ringkasan->lapangan_siap ?? 0 }}</h3>
                    <p>Lapangan Tersedia</p>
                </div>
                <div class="icon"><i class="fas fa-user-check"></i></div>
                <a href="{{ route('hrd.pool') }}" class="small-box-footer">Lihat pool <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-primary">
                <div class="inner">
                    <h3>{{ $ringkasan->lapangan_bertugas ?? 0 }}</h3>
                    <p>Lapangan Ditempatkan</p>
                </div>
                <div class="icon"><i class="fas fa-hard-hat"></i></div>
                <a href="{{ route('hrd.karyawan.index', ['kategori' => 'Lapangan', 'status' => 'Ditempatkan']) }}" class="small-box-footer">Lihat data <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>{{ $ringkasan->internal_aktif ?? 0 }}</h3>
                    <p>Staf Internal Aktif</p>
                </div>
                <div class="icon"><i class="fas fa-briefcase"></i></div>
                <a href="{{ route('hrd.karyawan.index', ['kategori' => 'Internal']) }}" class="small-box-footer">Lihat data <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-danger">
                <div class="inner">
                    <h3>{{ $jumlahDokumenHampirHabis }}</h3>
                    <p>Dokumen &lt; {{ $batasHari }} Hari</p>
                </div>
                <div class="icon"><i class="fas fa-id-card"></i></div>
                <a href="{{ route('hrd.sertifikat.index', ['hampir_habis' => 1]) }}" class="small-box-footer">Tinjau dokumen <i class="fas fa-arrow-circle-right"></i></a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-7">
            <div class="card card-outline card-danger">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-1"></i> Dokumen hampir kedaluwarsa</h3>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                <th>Dokumen</th>
                                <th>Kedaluwarsa</th>
                                <th>Sisa</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($dokumenHampirHabis as $dokumen)
                                <tr>
                                    <td>{{ $dokumen->nama }} <span class="text-muted small">({{ $dokumen->nip }})</span></td>
                                    <td>{{ $dokumen->nama_dokumen }}</td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($dokumen->tgl_kadaluarsa)->format('d/m/Y') }}</td>
                                    <td>
                                        <span class="badge badge-danger">{{ (int) $dokumen->sisa_hari === 0 ? 'Kedaluwarsa' : $dokumen->sisa_hari.' hari' }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted p-3">Tidak ada dokumen yang akan habis dalam {{ $batasHari }} hari.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Karyawan terbaru</h3>
                    <div class="card-tools">
                        <a href="{{ route('hrd.karyawan.create') }}" class="btn btn-tool" title="Tambah karyawan"><i class="fas fa-plus"></i></a>
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>NIP</th>
                                <th>Nama</th>
                                <th>Kategori</th>
                                <th>Status</th>
                                <th>Tgl Masuk</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($karyawanTerbaru as $item)
                                <tr>
                                    <td>{{ $item->nip }}</td>
                                    <td><a href="{{ route('hrd.karyawan.edit', $item->id) }}">{{ $item->nama }}</a></td>
                                    <td>{{ $item->kategori }}</td>
                                    <td>{{ $item->status }}</td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($item->tgl_masuk)->format('d/m/Y') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted p-3">Belum ada karyawan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Komposisi karyawan Lapangan per layanan</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Total seluruh karyawan terdaftar: <strong>{{ $totalKaryawan }}</strong></p>
                    @php $jumlahLapangan = max(1, $komposisiLayanan->sum('jumlah')); @endphp
                    @foreach ($komposisiLayanan as $layanan)
                        <div class="progress-group">
                            {{ $layanan->nama_layanan }}
                            <span class="float-right"><b>{{ $layanan->jumlah }}</b> orang</span>
                            <div class="progress progress-sm">
                                <div class="progress-bar bg-primary" style="width: {{ round($layanan->jumlah / $jumlahLapangan * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Pool tersedia &mdash; rating tertinggi</h3>
                </div>
                <ul class="list-group list-group-flush">
                    @forelse ($poolTeratas as $item)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>{{ $item->nama }} <span class="text-muted small">&middot; {{ $item->nama_layanan }}</span></span>
                            <span><i class="fas fa-star text-warning"></i> {{ number_format((float) $item->rating, 1) }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-center text-muted">Pool kosong.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection

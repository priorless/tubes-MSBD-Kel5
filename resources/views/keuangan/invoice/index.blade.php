@extends('layouts.adminlte')

@section('title', 'Invoice Klien')
@section('modul', 'Keuangan')
@section('heading', 'Invoice Klien')
@section('breadcrumb', 'Invoice')

@section('content')
    <div class="row">
        <div class="col-lg-4">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">Generate Invoice</h3>
                </div>
                <form method="POST" action="{{ route('keuangan.invoice.generate') }}">
                    @csrf
                    <div class="card-body">
                        <div class="form-group">
                            <label for="id_klien">Klien</label>
                            <select name="id_klien" id="id_klien" class="form-control @error('id_klien') is-invalid @enderror" required>
                                <option value="">&mdash; Pilih klien &mdash;</option>
                                @foreach ($klien as $item)
                                    <option value="{{ $item->id }}" @selected((int) old('id_klien') === (int) $item->id)>
                                        {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                                    </option>
                                @endforeach
                            </select>
                            @error('id_klien')
                                <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="form-row">
                            <div class="form-group col-7">
                                <label for="bulan">Bulan</label>
                                <select name="bulan" id="bulan" class="form-control @error('bulan') is-invalid @enderror" required>
                                    @foreach ($daftarBulan as $angka => $nama)
                                        <option value="{{ $angka }}" @selected((int) old('bulan', $bulanIni) === $angka)>{{ $nama }}</option>
                                    @endforeach
                                </select>
                                @error('bulan')
                                    <span class="invalid-feedback">{{ $message }}</span>
                                @enderror
                            </div>
                            <div class="form-group col-5">
                                <label for="tahun">Tahun</label>
                                <input type="number" name="tahun" id="tahun" min="2000" max="2100"
                                       value="{{ old('tahun', $tahunIni) }}"
                                       class="form-control @error('tahun') is-invalid @enderror" required>
                                @error('tahun')
                                    <span class="invalid-feedback">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>
                        <p class="text-muted small mb-0">
                            Jumlah tenaga dan total tagihan dihitung otomatis oleh database dari penempatan aktif klien pada periode tersebut.
                        </p>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-file-invoice mr-1"></i> Generate Invoice
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Daftar invoice</h3>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover text-nowrap">
                        <thead>
                            <tr>
                                <th>No. Invoice</th>
                                <th>Klien</th>
                                <th>Periode</th>
                                <th class="text-center">Tenaga</th>
                                <th class="text-right">Total Tagihan</th>
                                <th>Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($invoice as $item)
                                <tr>
                                    <td>{{ $item->nomor_invoice }}</td>
                                    <td>{{ $item->nama_perusahaan }}</td>
                                    <td>{{ $daftarBulan[$item->periode_bulan] ?? $item->periode_bulan }} {{ $item->periode_tahun }}</td>
                                    <td class="text-center">{{ $item->jumlah_tenaga }}</td>
                                    <td class="text-right">Rp {{ number_format((float) $item->total_tagihan, 0, ',', '.') }}</td>
                                    <td>
                                        @if ($item->status === 'Lunas')
                                            <span class="badge badge-success">Lunas</span>
                                            <small class="text-muted d-block">{{ \Illuminate\Support\Carbon::parse($item->tgl_pembayaran)->format('d/m/Y') }}</small>
                                        @else
                                            <span class="badge badge-danger">Belum Lunas</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if ($item->status !== 'Lunas')
                                            <form method="POST" action="{{ route('keuangan.invoice.lunas', $item->id) }}" class="form-inline justify-content-center"
                                                  onsubmit="return confirm(@js('Tandai invoice '.$item->nomor_invoice.' sebagai lunas?'));">
                                                @csrf
                                                @method('PATCH')
                                                <label for="tgl_pembayaran_{{ $item->id }}" class="sr-only">Tanggal pembayaran</label>
                                                <input type="date" name="tgl_pembayaran" id="tgl_pembayaran_{{ $item->id }}"
                                                       value="{{ now()->toDateString() }}" class="form-control form-control-sm mr-1" required>
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    <i class="fas fa-check mr-1"></i> Tandai Lunas
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-muted">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted p-4">Belum ada invoice.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer clearfix">
                    <span class="text-muted">Menampilkan {{ $invoice->firstItem() ?? 0 }}&ndash;{{ $invoice->lastItem() ?? 0 }} dari {{ $invoice->total() }} invoice</span>
                    <div class="float-right">
                        @include('partials.pagination', ['paginator' => $invoice])
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

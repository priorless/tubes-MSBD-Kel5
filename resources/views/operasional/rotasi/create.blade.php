@extends('layouts.adminlte')

@section('title', 'Rotasi Karyawan')
@section('heading', 'Rotasi Karyawan')
@section('breadcrumb', 'Rotasi')

@section('content')
    <div class="card card-warning">
        <div class="card-header">
            <h3 class="card-title">Pindahkan penempatan aktif ke klien baru</h3>
        </div>
        <form action="{{ route('operasional.rotasi.store') }}" method="POST">
            @csrf
            <div class="card-body">
                <p class="text-muted">Rotasi akan menutup penempatan lama dan membuat penempatan baru melalui prosedur database.</p>
                <div class="form-group">
                    <label for="id_penempatan_lama">Penempatan Aktif</label>
                    <select id="id_penempatan_lama" name="id_penempatan_lama" class="form-control @error('id_penempatan_lama') is-invalid @enderror" required>
                        <option value="">Pilih penempatan</option>
                        @foreach ($penempatan as $item)
                            <option value="{{ $item->id }}" @selected((string) old('id_penempatan_lama') === (string) $item->id)>
                                {{ $item->nip }} &mdash; {{ $item->nama_karyawan }} | {{ $item->nama_perusahaan }} ({{ $item->lokasi_kerja }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_penempatan_lama') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @if ($penempatan->isEmpty())
                        <small class="form-text text-muted">Tidak ada penempatan aktif yang dapat dirotasi.</small>
                    @endif
                </div>
                <div class="form-group">
                    <label for="id_klien_baru">Klien Tujuan</label>
                    <select id="id_klien_baru" name="id_klien_baru" class="form-control @error('id_klien_baru') is-invalid @enderror" required>
                        <option value="">Pilih klien</option>
                        @foreach ($klien as $item)
                            <option value="{{ $item->id }}" @selected((string) old('id_klien_baru') === (string) $item->id)>
                                {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_klien_baru') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @if ($klien->isEmpty())
                        <small class="form-text text-muted">Belum ada klien aktif sebagai tujuan rotasi.</small>
                    @endif
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="tgl_mulai_baru">Tanggal Mulai di Klien Baru</label>
                        <input id="tgl_mulai_baru" name="tgl_mulai_baru" type="date" required
                            class="form-control @error('tgl_mulai_baru') is-invalid @enderror" value="{{ old('tgl_mulai_baru') }}">
                        @error('tgl_mulai_baru') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="lokasi_baru">Lokasi Kerja Baru</label>
                        <input id="lokasi_baru" name="lokasi_baru" type="text" maxlength="255" required
                            class="form-control @error('lokasi_baru') is-invalid @enderror" value="{{ old('lokasi_baru') }}">
                        @error('lokasi_baru') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-warning" @disabled($penempatan->isEmpty() || $klien->isEmpty())>Proses Rotasi</button>
                <a href="{{ route('operasional.monitoring') }}" class="btn btn-default">Batal</a>
            </div>
        </form>
    </div>
@endsection

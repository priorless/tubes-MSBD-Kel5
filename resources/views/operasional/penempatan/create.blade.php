@extends('layouts.adminlte')

@section('title', 'Penempatan Karyawan')
@section('heading', 'Penempatan Karyawan')
@section('breadcrumb', 'Penempatan')

@section('content')
    <div class="card card-primary">
        <div class="card-header">
            <h3 class="card-title">Buat penempatan baru</h3>
        </div>
        <form action="{{ route('operasional.penempatan.store') }}" method="POST">
            @csrf
            <div class="card-body">
                <p class="text-muted">Penempatan diproses oleh prosedur database sesuai aturan operasional.</p>
                <div class="form-group">
                    <label for="id_karyawan">Karyawan Lapangan</label>
                    <select id="id_karyawan" name="id_karyawan" class="form-control @error('id_karyawan') is-invalid @enderror" required>
                        <option value="">Pilih karyawan</option>
                        @foreach ($karyawan as $item)
                            <option value="{{ $item->id }}" @selected((string) old('id_karyawan') === (string) $item->id)>
                                {{ $item->nip }} &mdash; {{ $item->nama }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_karyawan') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @if ($karyawan->isEmpty())
                        <small class="form-text text-muted">Tidak ada karyawan lapangan berstatus tersedia.</small>
                    @endif
                </div>
                <div class="form-group">
                    <label for="id_klien">Klien</label>
                    <select id="id_klien" name="id_klien" class="form-control @error('id_klien') is-invalid @enderror" required>
                        <option value="">Pilih klien</option>
                        @foreach ($klien as $item)
                            <option value="{{ $item->id }}" @selected((string) old('id_klien') === (string) $item->id)>
                                {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_klien') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @if ($klien->isEmpty())
                        <small class="form-text text-muted">Belum ada klien aktif. Tambahkan atau aktifkan data klien terlebih dahulu.</small>
                    @endif
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="tgl_mulai">Tanggal Mulai</label>
                        <input id="tgl_mulai" name="tgl_mulai" type="date" required
                            class="form-control @error('tgl_mulai') is-invalid @enderror" value="{{ old('tgl_mulai') }}">
                        @error('tgl_mulai') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="tgl_selesai">Tanggal Selesai Kontrak</label>
                        <input id="tgl_selesai" name="tgl_selesai" type="date"
                            class="form-control @error('tgl_selesai') is-invalid @enderror" value="{{ old('tgl_selesai') }}">
                        @error('tgl_selesai') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="form-group">
                    <label for="lokasi">Lokasi Kerja</label>
                    <input id="lokasi" name="lokasi" type="text" maxlength="255" required
                        class="form-control @error('lokasi') is-invalid @enderror" value="{{ old('lokasi') }}">
                    @error('lokasi') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary" @disabled($karyawan->isEmpty() || $klien->isEmpty())>Proses Penempatan</button>
                <a href="{{ route('operasional.monitoring') }}" class="btn btn-default">Batal</a>
            </div>
        </form>
    </div>
@endsection

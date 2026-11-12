@extends('layouts.adminlte')

@section('title', 'Tambah Dokumen')
@section('modul', 'HRD')
@section('heading', 'Tambah Dokumen Sertifikat')
@section('breadcrumb', 'Dokumen Sertifikat')

@section('content')
    <div class="card card-primary">
        <div class="card-header">
            <h3 class="card-title">Masukkan data dokumen karyawan</h3>
        </div>
        <form action="{{ route('hrd.sertifikat.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="card-body">
                <div class="form-group">
                    <label for="id_karyawan">Karyawan</label>
                    <select id="id_karyawan" name="id_karyawan" required class="form-control @error('id_karyawan') is-invalid @enderror">
                        <option value="">&mdash; Pilih karyawan &mdash;</option>
                        @foreach ($karyawan as $item)
                            <option value="{{ $item->id }}" @selected((string) old('id_karyawan', request('karyawan')) === (string) $item->id)>
                                {{ $item->nama }} ({{ $item->nip }}, {{ $item->kategori }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_karyawan') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="form-row">
                    <div class="form-group col-md-7">
                        <label for="nama_dokumen">Nama Dokumen</label>
                        <input id="nama_dokumen" name="nama_dokumen" type="text" maxlength="100" required placeholder="mis. Sertifikat Gada Pratama, SIM B1"
                            class="form-control @error('nama_dokumen') is-invalid @enderror" value="{{ old('nama_dokumen') }}">
                        @error('nama_dokumen') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group col-md-5">
                        <label for="nomor_dokumen">Nomor Dokumen</label>
                        <input id="nomor_dokumen" name="nomor_dokumen" type="text" maxlength="50"
                            class="form-control @error('nomor_dokumen') is-invalid @enderror" value="{{ old('nomor_dokumen') }}">
                        @error('nomor_dokumen') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="tgl_terbit">Tanggal Terbit</label>
                        <input id="tgl_terbit" name="tgl_terbit" type="date" required
                            class="form-control @error('tgl_terbit') is-invalid @enderror" value="{{ old('tgl_terbit') }}">
                        @error('tgl_terbit') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="tgl_kadaluarsa">Tanggal Kedaluwarsa</label>
                        <input id="tgl_kadaluarsa" name="tgl_kadaluarsa" type="date" required
                            class="form-control @error('tgl_kadaluarsa') is-invalid @enderror" value="{{ old('tgl_kadaluarsa') }}">
                        @error('tgl_kadaluarsa') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="form-group">
                    <label for="file_upload">Berkas Dokumen</label>
                    <div class="custom-file">
                        <input id="file_upload" name="file_upload" type="file" accept="application/pdf,image/png,image/jpeg"
                            class="custom-file-input @error('file_upload') is-invalid @enderror">
                        <label class="custom-file-label" for="file_upload">Pilih berkas&hellip;</label>
                        @error('file_upload') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <small class="form-text text-muted">PDF, JPG, atau PNG, maksimal 4 MB.</small>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan</button>
                <a href="{{ route('hrd.sertifikat.index') }}" class="btn btn-default">Batal</a>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        document.getElementById('file_upload').addEventListener('change', function (event) {
            event.target.nextElementSibling.textContent = event.target.files[0]?.name ?? 'Pilih berkas…';
        });
    </script>
@endpush

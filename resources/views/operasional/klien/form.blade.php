@extends('layouts.adminlte')

@php
    $isEditing = $klien !== null;
@endphp

@section('title', $isEditing ? 'Ubah Klien' : 'Tambah Klien')
@section('heading', $isEditing ? 'Ubah Data Klien' : 'Tambah Klien')
@section('breadcrumb', 'Data Klien')

@section('content')
    <div class="card card-primary">
        <div class="card-header">
            <h3 class="card-title">{{ $isEditing ? 'Perbarui informasi klien' : 'Masukkan informasi klien baru' }}</h3>
        </div>
        <form action="{{ $isEditing ? route('operasional.klien.update', $klien->id) : route('operasional.klien.store') }}" method="POST">
            @csrf
            @if ($isEditing)
                @method('PUT')
            @endif
            <div class="card-body">
                <div class="form-group">
                    <label for="kode_klien">Kode Klien</label>
                    <input id="kode_klien" name="kode_klien" type="text" maxlength="10" required
                        class="form-control @error('kode_klien') is-invalid @enderror"
                        value="{{ old('kode_klien', $klien?->kode_klien) }}">
                    @error('kode_klien') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label for="nama_perusahaan">Nama Perusahaan</label>
                    <input id="nama_perusahaan" name="nama_perusahaan" type="text" maxlength="150" required
                        class="form-control @error('nama_perusahaan') is-invalid @enderror"
                        value="{{ old('nama_perusahaan', $klien?->nama_perusahaan) }}">
                    @error('nama_perusahaan') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label for="alamat">Alamat</label>
                    <textarea id="alamat" name="alamat" rows="3" required
                        class="form-control @error('alamat') is-invalid @enderror">{{ old('alamat', $klien?->alamat) }}</textarea>
                    @error('alamat') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="pic_nama">Nama PIC</label>
                        <input id="pic_nama" name="pic_nama" type="text" maxlength="100" required
                            class="form-control @error('pic_nama') is-invalid @enderror"
                            value="{{ old('pic_nama', $klien?->pic_nama) }}">
                        @error('pic_nama') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="pic_jabatan">Jabatan PIC</label>
                        <input id="pic_jabatan" name="pic_jabatan" type="text" maxlength="50"
                            class="form-control @error('pic_jabatan') is-invalid @enderror"
                            value="{{ old('pic_jabatan', $klien?->pic_jabatan) }}">
                        @error('pic_jabatan') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="pic_telp">Nomor Telepon PIC</label>
                        <input id="pic_telp" name="pic_telp" type="text" maxlength="15" required
                            class="form-control @error('pic_telp') is-invalid @enderror"
                            value="{{ old('pic_telp', $klien?->pic_telp) }}">
                        @error('pic_telp') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="pic_email">Email PIC</label>
                        <input id="pic_email" name="pic_email" type="email" maxlength="100"
                            class="form-control @error('pic_email') is-invalid @enderror"
                            value="{{ old('pic_email', $klien?->pic_email) }}">
                        @error('pic_email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="form-group">
                    <label for="status">Status Klien</label>
                    <select id="status" name="status" class="form-control @error('status') is-invalid @enderror" required>
                        @foreach (['Aktif', 'Nonaktif'] as $status)
                            <option value="{{ $status }}" @selected(old('status', $klien?->status ?? 'Aktif') === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">{{ $isEditing ? 'Simpan Perubahan' : 'Simpan Klien' }}</button>
                <a href="{{ route('operasional.klien.index') }}" class="btn btn-default">Batal</a>
            </div>
        </form>
    </div>
@endsection

@extends('layouts.app')

@php
    $isEditing = $klien !== null;
@endphp

@section('title', $isEditing ? 'Ubah Klien' : 'Tambah Klien')
@section('page-title', $isEditing ? 'Ubah Data Klien' : 'Tambah Klien')

@section('content')
    <form action="{{ $isEditing ? route('operasional.klien.update', $klien->id) : route('operasional.klien.store') }}" method="POST" class="card max-w-3xl">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <div class="card-header">
            <h2 class="card-title">{{ $isEditing ? 'Perbarui informasi klien' : 'Masukkan informasi klien baru' }}</h2>
        </div>

        <div class="card-body space-y-4">
            <div>
                <label for="kode_klien" class="form-label">Kode Klien</label>
                <input id="kode_klien" name="kode_klien" type="text" maxlength="10" required
                    @class(['form-input', 'form-input-error' => $errors->has('kode_klien')]) value="{{ old('kode_klien', $klien?->kode_klien) }}">
                @error('kode_klien') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="nama_perusahaan" class="form-label">Nama Perusahaan</label>
                <input id="nama_perusahaan" name="nama_perusahaan" type="text" maxlength="150" required
                    @class(['form-input', 'form-input-error' => $errors->has('nama_perusahaan')]) value="{{ old('nama_perusahaan', $klien?->nama_perusahaan) }}">
                @error('nama_perusahaan') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="alamat" class="form-label">Alamat</label>
                <textarea id="alamat" name="alamat" rows="3" required
                    @class(['form-input', 'form-input-error' => $errors->has('alamat')])>{{ old('alamat', $klien?->alamat) }}</textarea>
                @error('alamat') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="pic_nama" class="form-label">Nama PIC</label>
                    <input id="pic_nama" name="pic_nama" type="text" maxlength="100" required
                        @class(['form-input', 'form-input-error' => $errors->has('pic_nama')]) value="{{ old('pic_nama', $klien?->pic_nama) }}">
                    @error('pic_nama') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="pic_jabatan" class="form-label">Jabatan PIC</label>
                    <input id="pic_jabatan" name="pic_jabatan" type="text" maxlength="50"
                        @class(['form-input', 'form-input-error' => $errors->has('pic_jabatan')]) value="{{ old('pic_jabatan', $klien?->pic_jabatan) }}">
                    @error('pic_jabatan') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="pic_telp" class="form-label">Nomor Telepon PIC</label>
                    <input id="pic_telp" name="pic_telp" type="text" maxlength="15" required
                        @class(['form-input', 'form-input-error' => $errors->has('pic_telp')]) value="{{ old('pic_telp', $klien?->pic_telp) }}">
                    @error('pic_telp') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="pic_email" class="form-label">Email PIC</label>
                    <input id="pic_email" name="pic_email" type="email" maxlength="100"
                        @class(['form-input', 'form-input-error' => $errors->has('pic_email')]) value="{{ old('pic_email', $klien?->pic_email) }}">
                    @error('pic_email') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="status" class="form-label">Status Klien</label>
                <select id="status" name="status" @class(['form-input', 'form-input-error' => $errors->has('status')]) required>
                    @foreach (['Aktif', 'Nonaktif'] as $status)
                        <option value="{{ $status }}" @selected(old('status', $klien?->status ?? 'Aktif') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
                @error('status') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="card-footer justify-start">
            <button type="submit" class="btn-primary">{{ $isEditing ? 'Simpan Perubahan' : 'Simpan Klien' }}</button>
            <a href="{{ route('operasional.klien.index') }}" class="btn-secondary">Batal</a>
        </div>
    </form>
@endsection

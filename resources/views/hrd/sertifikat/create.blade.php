@extends('layouts.app')

@section('title', 'Tambah Dokumen')
@section('page-title', 'Tambah Dokumen Sertifikat')

@section('content')
    <x-ui.page-header title="Tambah dokumen sertifikat" description="Masukkan data dokumen karyawan." />

    <form action="{{ route('hrd.sertifikat.store') }}" method="POST" enctype="multipart/form-data" class="card max-w-3xl">
        @csrf

        <div class="card-body space-y-4">
            <div>
                <label for="id_karyawan" class="form-label">Karyawan</label>
                <select id="id_karyawan" name="id_karyawan" required @class(['form-input', 'form-input-error' => $errors->has('id_karyawan')])>
                    <option value="">&mdash; Pilih karyawan &mdash;</option>
                    @foreach ($karyawan as $item)
                        <option value="{{ $item->id }}" @selected((string) old('id_karyawan', request('karyawan')) === (string) $item->id)>
                            {{ $item->nama }} ({{ $item->nip }}, {{ $item->kategori }})
                        </option>
                    @endforeach
                </select>
                @error('id_karyawan') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-12">
                <div class="md:col-span-7">
                    <label for="nama_dokumen" class="form-label">Nama Dokumen</label>
                    <input id="nama_dokumen" name="nama_dokumen" type="text" maxlength="100" required placeholder="mis. Sertifikat Gada Pratama, SIM B1"
                        @class(['form-input', 'form-input-error' => $errors->has('nama_dokumen')]) value="{{ old('nama_dokumen') }}">
                    @error('nama_dokumen') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-5">
                    <label for="nomor_dokumen" class="form-label">Nomor Dokumen</label>
                    <input id="nomor_dokumen" name="nomor_dokumen" type="text" maxlength="50"
                        @class(['form-input', 'form-input-error' => $errors->has('nomor_dokumen')]) value="{{ old('nomor_dokumen') }}">
                    @error('nomor_dokumen') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="tgl_terbit" class="form-label">Tanggal Terbit</label>
                    <input id="tgl_terbit" name="tgl_terbit" type="date" required
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_terbit')]) value="{{ old('tgl_terbit') }}">
                    @error('tgl_terbit') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="tgl_kadaluarsa" class="form-label">Tanggal Kedaluwarsa</label>
                    <input id="tgl_kadaluarsa" name="tgl_kadaluarsa" type="date" required
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_kadaluarsa')]) value="{{ old('tgl_kadaluarsa') }}">
                    @error('tgl_kadaluarsa') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="file_upload" class="form-label">Berkas Dokumen</label>
                <input id="file_upload" name="file_upload" type="file" accept="application/pdf,image/png,image/jpeg"
                    class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200">
                @error('file_upload') <p class="form-error">{{ $message }}</p> @enderror
                <p class="form-hint">PDF, JPG, atau PNG, maksimal 4 MB.</p>
            </div>
        </div>

        <div class="card-footer justify-start">
            <button type="submit" class="btn-primary">Simpan</button>
            <a href="{{ route('hrd.sertifikat.index') }}" class="btn-secondary">Batal</a>
        </div>
    </form>
@endsection

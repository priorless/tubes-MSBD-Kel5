@extends('layouts.app')

@section('title', 'Ubah Dokumen')
@section('page-title', 'Ubah Dokumen Sertifikat')

@section('content')
    <x-ui.page-header title="Ubah dokumen sertifikat" description="Perbarui masa berlaku atau ganti berkas dokumen. Karyawan dan nama dokumen tidak berubah." />

    <form action="{{ route('hrd.sertifikat.update', $sertifikat->id) }}" method="POST" enctype="multipart/form-data" class="card max-w-3xl">
        @csrf
        @method('PUT')

        <div class="card-body space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-12">
                <div class="md:col-span-5">
                    <span class="form-label">Karyawan</span>
                    <p class="font-medium text-gray-800">{{ $sertifikat->nama }}</p>
                    <p class="text-xs text-gray-400">NIP {{ $sertifikat->nip }}</p>
                </div>
                <div class="md:col-span-7">
                    <span class="form-label">Dokumen</span>
                    <p class="font-medium text-gray-800">{{ $sertifikat->nama_dokumen }}</p>
                    @if ($sertifikat->nomor_dokumen)
                        <p class="text-xs text-gray-400">No. {{ $sertifikat->nomor_dokumen }}</p>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="tgl_terbit" class="form-label">Tanggal Terbit</label>
                    <input id="tgl_terbit" name="tgl_terbit" type="date" required
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_terbit')]) value="{{ old('tgl_terbit', $sertifikat->tgl_terbit) }}">
                    @error('tgl_terbit') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="tgl_kadaluarsa" class="form-label">Tanggal Kedaluwarsa</label>
                    <input id="tgl_kadaluarsa" name="tgl_kadaluarsa" type="date" required
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_kadaluarsa')]) value="{{ old('tgl_kadaluarsa', $sertifikat->tgl_kadaluarsa) }}">
                    @error('tgl_kadaluarsa') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="file_upload" class="form-label">Berkas Dokumen Baru (opsional)</label>
                @if ($sertifikat->file_upload)
                    <p class="mb-2 text-sm text-gray-600">
                        Berkas saat ini:
                        <a href="{{ route('sertifikat.berkas', $sertifikat->id) }}" target="_blank" rel="noopener" class="font-medium text-sji-biru hover:underline">Lihat</a>
                    </p>
                @endif
                <input id="file_upload" name="file_upload" type="file" accept="application/pdf,image/png,image/jpeg"
                    class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200">
                @error('file_upload') <p class="form-error">{{ $message }}</p> @enderror
                <p class="form-hint">PDF, JPG, atau PNG, maksimal 4 MB. Kosongkan bila berkas tidak diganti; berkas lama tetap dipakai.</p>
            </div>
        </div>

        <div class="card-footer justify-start">
            <button type="submit" class="btn-primary">Simpan Perubahan</button>
            <a href="{{ route('hrd.sertifikat.index') }}" class="btn-secondary">Batal</a>
        </div>
    </form>
@endsection

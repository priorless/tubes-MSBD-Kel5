@extends('layouts.app')

@section('title', 'Rotasi Karyawan')

@section('content')
    <form action="{{ route('operasional.rotasi.store') }}" method="POST" class="card max-w-3xl">
        @csrf

        <div class="card-header">
            <div>
                <h2 class="card-title">Pindahkan penempatan aktif ke klien baru</h2>
                <p class="text-sm text-gray-500">Rotasi akan menutup penempatan lama dan membuat penempatan baru melalui prosedur database.</p>
            </div>
        </div>

        <div class="card-body space-y-4">
            <div>
                <label for="id_penempatan_lama" class="form-label">Penempatan Aktif</label>
                <select id="id_penempatan_lama" name="id_penempatan_lama" @class(['form-input', 'form-input-error' => $errors->has('id_penempatan_lama')]) required>
                    <option value="">Pilih penempatan</option>
                    @foreach ($penempatan as $item)
                        <option value="{{ $item->id }}" @selected((string) old('id_penempatan_lama') === (string) $item->id)>
                            {{ $item->nip }} &mdash; {{ $item->nama_karyawan }} | {{ $item->nama_perusahaan }} ({{ $item->lokasi_kerja }})
                        </option>
                    @endforeach
                </select>
                @error('id_penempatan_lama') <p class="form-error">{{ $message }}</p> @enderror
                @if ($penempatan->isEmpty())
                    <p class="form-hint">Tidak ada penempatan aktif yang dapat dirotasi.</p>
                @endif
            </div>
            <div>
                <label for="id_klien_baru" class="form-label">Klien Tujuan</label>
                <select id="id_klien_baru" name="id_klien_baru" @class(['form-input', 'form-input-error' => $errors->has('id_klien_baru')]) required>
                    <option value="">Pilih klien</option>
                    @foreach ($klien as $item)
                        <option value="{{ $item->id }}" @selected((string) old('id_klien_baru') === (string) $item->id)>
                            {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                        </option>
                    @endforeach
                </select>
                @error('id_klien_baru') <p class="form-error">{{ $message }}</p> @enderror
                @if ($klien->isEmpty())
                    <p class="form-hint">Belum ada klien aktif sebagai tujuan rotasi.</p>
                @endif
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="tgl_mulai_baru" class="form-label">Tanggal Mulai di Klien Baru</label>
                    <input id="tgl_mulai_baru" name="tgl_mulai_baru" type="date" required
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_mulai_baru')]) value="{{ old('tgl_mulai_baru') }}">
                    @error('tgl_mulai_baru') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="lokasi_baru" class="form-label">Lokasi Kerja Baru</label>
                    <input id="lokasi_baru" name="lokasi_baru" type="text" maxlength="255" required
                        @class(['form-input', 'form-input-error' => $errors->has('lokasi_baru')]) value="{{ old('lokasi_baru') }}">
                    @error('lokasi_baru') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="card-footer justify-start">
            <button type="submit" class="btn-warning" @disabled($penempatan->isEmpty() || $klien->isEmpty())>Proses Rotasi</button>
            <a href="{{ route('operasional.monitoring') }}" class="btn-secondary">Batal</a>
        </div>
    </form>
@endsection

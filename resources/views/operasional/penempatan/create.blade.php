@extends('layouts.app')

@section('title', 'Penempatan Karyawan')

@section('content')
    <form action="{{ route('operasional.penempatan.store') }}" method="POST" class="card max-w-3xl">
        @csrf

        <div class="card-header">
            <div>
                <h2 class="card-title">Buat penempatan baru</h2>
                <p class="text-sm text-gray-500">Penempatan diproses oleh prosedur database sesuai aturan operasional.</p>
            </div>
        </div>

        <div class="card-body space-y-4">
            <div>
                <label for="id_karyawan" class="form-label">Karyawan Lapangan</label>
                <select id="id_karyawan" name="id_karyawan" @class(['form-input', 'form-input-error' => $errors->has('id_karyawan')]) required>
                    <option value="">Pilih karyawan</option>
                    @foreach ($karyawan as $item)
                        <option value="{{ $item->id }}" @selected((string) old('id_karyawan') === (string) $item->id)>
                            {{ $item->nip }} &mdash; {{ $item->nama }}
                        </option>
                    @endforeach
                </select>
                @error('id_karyawan') <p class="form-error">{{ $message }}</p> @enderror
                @if ($karyawan->isEmpty())
                    <p class="form-hint">Tidak ada karyawan lapangan berstatus tersedia.</p>
                @endif
            </div>
            <div>
                <label for="id_klien" class="form-label">Klien</label>
                <select id="id_klien" name="id_klien" @class(['form-input', 'form-input-error' => $errors->has('id_klien')]) required>
                    <option value="">Pilih klien</option>
                    @foreach ($klien as $item)
                        <option value="{{ $item->id }}" @selected((string) old('id_klien') === (string) $item->id)>
                            {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                        </option>
                    @endforeach
                </select>
                @error('id_klien') <p class="form-error">{{ $message }}</p> @enderror
                @if ($klien->isEmpty())
                    <p class="form-hint">Belum ada klien aktif. Tambahkan atau aktifkan data klien terlebih dahulu.</p>
                @endif
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="tgl_mulai" class="form-label">Tanggal Mulai</label>
                    <input id="tgl_mulai" name="tgl_mulai" type="date" required
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_mulai')]) value="{{ old('tgl_mulai') }}">
                    @error('tgl_mulai') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="tgl_selesai" class="form-label">Tanggal Selesai Kontrak</label>
                    <input id="tgl_selesai" name="tgl_selesai" type="date"
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_selesai')]) value="{{ old('tgl_selesai') }}">
                    @error('tgl_selesai') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="lokasi" class="form-label">Lokasi Kerja</label>
                <input id="lokasi" name="lokasi" type="text" maxlength="255" required
                    @class(['form-input', 'form-input-error' => $errors->has('lokasi')]) value="{{ old('lokasi') }}">
                @error('lokasi') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="card-footer justify-start">
            <button type="submit" class="btn-primary" @disabled($karyawan->isEmpty() || $klien->isEmpty())>Proses Penempatan</button>
            <a href="{{ route('operasional.monitoring') }}" class="btn-secondary">Batal</a>
        </div>
    </form>
@endsection

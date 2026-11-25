@extends('layouts.app')

@php
    $isEditing = $klien !== null;
@endphp

@section('title', $isEditing ? 'Ubah Klien' : 'Tambah Klien')
@section('page-title', $isEditing ? 'Ubah Data Klien' : 'Tambah Klien')

@section('content')
    <x-ui.page-header :title="$isEditing ? 'Edit informasi '.$klien->nama_perusahaan : 'Tambah klien baru'" />

    <form action="{{ $isEditing ? route($ruteKlien.'.update', $klien->id) : route($ruteKlien.'.store') }}" method="POST" class="card max-w-3xl">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <div class="card-body space-y-4">
            <div>
                <h3 class="font-semibold text-gray-800">Informasi perusahaan</h3>
            </div>
            <div>
                <label for="kode_klien" class="form-label">Kode Klien</label>
                <input id="kode_klien" name="kode_klien" type="text" maxlength="10" required placeholder="Contoh: KL001"
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
            <div class="border-t border-gray-200 pt-4">
                <h3 class="font-semibold text-gray-800">Kontak perusahaan</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="pic_nama" class="form-label">Nama Penanggung Jawab (PIC)</label>
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
            <div class="border-t border-gray-200 pt-4">
                <h3 class="font-semibold text-gray-800">Status kerja sama</h3>
                <p class="form-hint">Hanya klien Aktif dapat dipilih untuk penempatan.</p>
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
            @if ($managementFeeTersedia)
                <div>
                    <label for="management_fee" class="form-label">Management Fee (opsional)</label>
                    <div class="flex max-w-48 items-center gap-2">
                        <input id="management_fee" name="management_fee" type="number" inputmode="decimal" min="0" max="100" step="0.01"
                            placeholder="Contoh: 7.50"
                            @class(['form-input', 'form-input-error' => $errors->has('management_fee')]) value="{{ old('management_fee', $klien?->management_fee) }}">
                        <span class="text-sm text-gray-600">%</span>
                    </div>
                    @error('management_fee') <p class="form-error">{{ $message }}</p> @enderror
                    <p class="form-hint">Persentase yang disepakati dengan klien. Kosongkan bila belum ditentukan; isi 0 bila disepakati tanpa fee.</p>
                </div>
            @else
                <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800" role="status">
                    Isian Management Fee belum tersedia karena database belum dimigrasi. Hubungi admin untuk menjalankan <code>php artisan migrate</code>.
                </p>
            @endif

            <div class="border-t border-gray-200 pt-4">
                <h3 class="font-semibold text-gray-800">Pengaturan BPJS</h3>
                <p class="form-hint">Nilai default BPJS klien. Dipotong dari gaji karyawan yang ditempatkan di klien ini (setelah potongan absensi) dan ditagihkan dengan nilai yang sama pada invoice klien.</p>
            </div>
            @if ($bpjsTersedia)
                @php
                    $metodeBpjs = old('bpjs_metode', $klien?->bpjs_metode ?? '');
                    $nilaiBpjs = old('bpjs_nilai', $klien?->bpjs_nilai);
                @endphp
                <fieldset>
                    <legend class="form-label">Metode Perhitungan</legend>
                    <div class="flex flex-wrap gap-x-6 gap-y-2">
                        @foreach (['' => 'Tanpa BPJS', 'Nominal' => 'Nominal', 'Persentase' => 'Persentase'] as $nilaiMetode => $labelMetode)
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="radio" name="bpjs_metode" value="{{ $nilaiMetode }}" class="form-check" data-bpjs-metode @checked((string) $metodeBpjs === $nilaiMetode)>
                                {{ $labelMetode }}
                            </label>
                        @endforeach
                    </div>
                    @error('bpjs_metode') <p class="form-error">{{ $message }}</p> @enderror
                </fieldset>
                <div id="bpjs_nilai_wrapper" @class(['hidden' => (string) $metodeBpjs === ''])>
                    <label for="bpjs_nilai" class="form-label">Nilai BPJS</label>
                    <div class="flex max-w-64 items-center gap-2">
                        <span id="bpjs_prefix" @class(['text-sm text-gray-600', 'hidden' => $metodeBpjs !== 'Nominal'])>Rp</span>
                        <input id="bpjs_nilai" name="bpjs_nilai" type="number" inputmode="decimal" min="0.01" step="0.01"
                            @if ($metodeBpjs === 'Persentase') max="100" @endif
                            placeholder="{{ $metodeBpjs === 'Nominal' ? 'Contoh: 100000' : 'Contoh: 4' }}"
                            @disabled((string) $metodeBpjs === '')
                            @class(['form-input', 'form-input-error' => $errors->has('bpjs_nilai')]) value="{{ $nilaiBpjs }}">
                        <span id="bpjs_suffix" @class(['text-sm text-gray-600', 'hidden' => $metodeBpjs !== 'Persentase'])>%</span>
                    </div>
                    @error('bpjs_nilai') <p class="form-error">{{ $message }}</p> @enderror
                    <p class="form-hint" id="bpjs_hint">
                        {{ $metodeBpjs === 'Nominal'
                            ? 'Nominal dipotong per karyawan setiap periode payroll.'
                            : 'Persentase dari gaji pokok + lembur setelah dikurangi potongan absensi.' }}
                    </p>
                </div>
                <p class="form-hint">Perubahan pengaturan hanya berlaku untuk payroll dan invoice yang dibuat setelahnya.</p>

                @push('scripts')
                    <script>
                        (() => {
                            const pilihan = document.querySelectorAll('[data-bpjs-metode]');
                            const wrapper = document.getElementById('bpjs_nilai_wrapper');
                            const input = document.getElementById('bpjs_nilai');
                            const prefix = document.getElementById('bpjs_prefix');
                            const suffix = document.getElementById('bpjs_suffix');
                            const hint = document.getElementById('bpjs_hint');

                            const terapkan = () => {
                                const metode = document.querySelector('[data-bpjs-metode]:checked')?.value ?? '';
                                const persen = metode === 'Persentase';
                                wrapper.classList.toggle('hidden', metode === '');
                                input.disabled = metode === '';
                                input.required = metode !== '';
                                prefix.classList.toggle('hidden', metode !== 'Nominal');
                                suffix.classList.toggle('hidden', !persen);
                                persen ? input.setAttribute('max', '100') : input.removeAttribute('max');
                                input.placeholder = persen ? 'Contoh: 4' : 'Contoh: 100000';
                                hint.textContent = persen
                                    ? 'Persentase dari gaji pokok + lembur setelah dikurangi potongan absensi.'
                                    : 'Nominal dipotong per karyawan setiap periode payroll.';
                            };

                            pilihan.forEach((radio) => radio.addEventListener('change', terapkan));
                            terapkan();
                        })();
                    </script>
                @endpush
            @else
                <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800" role="status">
                    Pengaturan BPJS belum tersedia karena database belum dimigrasi. Hubungi admin untuk menjalankan <code>php artisan migrate</code>.
                </p>
            @endif
        </div>

        <div class="card-footer justify-start">
            <button type="submit" class="btn-primary">{{ $isEditing ? 'Simpan Perubahan' : 'Simpan Klien' }}</button>
            <a href="{{ $isEditing ? route($ruteKlien.'.show', $klien->id) : route($ruteKlien.'.index') }}" class="btn-secondary">Batal</a>
        </div>
    </form>
@endsection

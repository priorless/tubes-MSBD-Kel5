@extends('layouts.app')

@php
    $isEditing = $karyawan !== null;
    $kategoriAktif = $isEditing ? $karyawan->kategori : old('kategori', 'Lapangan');
@endphp

@section('title', $isEditing ? 'Ubah Karyawan' : 'Tambah Karyawan')
@section('page-title', $isEditing ? 'Ubah Data Karyawan' : 'Tambah Karyawan')

@section('content')
    <form action="{{ $isEditing ? route('hrd.karyawan.update', $karyawan->id) : route('hrd.karyawan.store') }}"
          method="POST" enctype="multipart/form-data">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Data Kepegawaian</h2>
                    </div>
                    <div class="card-body space-y-4">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <div>
                                <label for="nip" class="form-label">NIP</label>
                                @if ($isEditing)
                                    <input id="nip" type="text" class="form-input" value="{{ $karyawan->nip }}" disabled>
                                    <p class="form-hint">NIP tidak dapat diubah.</p>
                                @else
                                    <input id="nip" name="nip" type="text" maxlength="20" required
                                        @class(['form-input', 'form-input-error' => $errors->has('nip')]) value="{{ old('nip') }}">
                                    @error('nip') <p class="form-error">{{ $message }}</p> @enderror
                                @endif
                            </div>
                            <div class="md:col-span-2">
                                <label for="nama" class="form-label">Nama Lengkap</label>
                                <input id="nama" name="nama" type="text" maxlength="100" required
                                    @class(['form-input', 'form-input-error' => $errors->has('nama')]) value="{{ old('nama', $karyawan?->nama) }}">
                                @error('nama') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <span class="form-label">Kategori</span>
                            @if ($isEditing)
                                <span class="badge badge-{{ $karyawan->kategori === 'Internal' ? 'dark' : 'info' }}">{{ $karyawan->kategori }}</span>
                                <p class="form-hint">Kategori tidak dapat diubah setelah karyawan terdaftar.</p>
                            @else
                                <div class="flex flex-wrap gap-x-6 gap-y-2">
                                    @foreach (['Lapangan' => 'Lapangan (satpam, driver, cleaning service)', 'Internal' => 'Internal (staf kantor)'] as $nilai => $label)
                                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="radio" name="kategori" value="{{ $nilai }}" class="form-check js-kategori" @checked($kategoriAktif === $nilai)>
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                                @error('kategori') <p class="form-error">{{ $message }}</p> @enderror
                            @endif
                        </div>

                        <div class="js-bagian-lapangan">
                            <label for="id_jenis_layanan" class="form-label">Jenis Layanan</label>
                            <select id="id_jenis_layanan" name="id_jenis_layanan" @class(['form-input', 'form-input-error' => $errors->has('id_jenis_layanan')])>
                                <option value="">&mdash; Pilih jenis layanan &mdash;</option>
                                @foreach ($jenisLayanan as $layanan)
                                    <option value="{{ $layanan->id }}" @selected((string) old('id_jenis_layanan', $karyawan?->id_jenis_layanan) === (string) $layanan->id)>
                                        {{ $layanan->nama_layanan }}
                                    </option>
                                @endforeach
                            </select>
                            @error('id_jenis_layanan') <p class="form-error">{{ $message }}</p> @enderror
                            <p class="form-hint">Wajib untuk karyawan Lapangan. Gaji karyawan Lapangan diatur lewat kontrak penempatan.</p>
                        </div>

                        <div class="js-bagian-internal">
                            <label for="gaji_pokok" class="form-label">Gaji Pokok (Rp)</label>
                            <input id="gaji_pokok" name="gaji_pokok" type="number" min="0" step="1000"
                                @class(['form-input', 'form-input-error' => $errors->has('gaji_pokok')])
                                value="{{ old('gaji_pokok', $karyawan?->gaji_pokok) }}">
                            @error('gaji_pokok') <p class="form-error">{{ $message }}</p> @enderror
                            <p class="form-hint">Wajib untuk karyawan Internal. Gaji yang dibayarkan = gaji pokok + lembur &minus; potongan absen.</p>
                        </div>

                        <div>
                            <label for="tgl_masuk" class="form-label">Tanggal Masuk</label>
                            <input id="tgl_masuk" name="tgl_masuk" type="date" required
                                @class(['form-input', 'form-input-error' => $errors->has('tgl_masuk')])
                                value="{{ old('tgl_masuk', $karyawan?->tgl_masuk ?? now()->toDateString()) }}">
                            @error('tgl_masuk') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Data Pribadi</h2>
                    </div>
                    <div class="card-body space-y-4">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label for="no_ktp" class="form-label">Nomor KTP</label>
                                <input id="no_ktp" name="no_ktp" type="text" inputmode="numeric" maxlength="16" required
                                    @class(['form-input', 'form-input-error' => $errors->has('no_ktp')]) value="{{ old('no_ktp', $karyawan?->no_ktp) }}">
                                @error('no_ktp') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <span class="form-label">Jenis Kelamin</span>
                                <div class="flex gap-6 pt-2">
                                    @foreach (['L' => 'Laki-laki', 'P' => 'Perempuan'] as $nilai => $label)
                                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="radio" name="jenis_kelamin" value="{{ $nilai }}" class="form-check"
                                                @checked(old('jenis_kelamin', $karyawan?->jenis_kelamin) === $nilai)>
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                                @error('jenis_kelamin') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label for="tempat_lahir" class="form-label">Tempat Lahir</label>
                                <input id="tempat_lahir" name="tempat_lahir" type="text" maxlength="50"
                                    @class(['form-input', 'form-input-error' => $errors->has('tempat_lahir')]) value="{{ old('tempat_lahir', $karyawan?->tempat_lahir) }}">
                                @error('tempat_lahir') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="tgl_lahir" class="form-label">Tanggal Lahir</label>
                                <input id="tgl_lahir" name="tgl_lahir" type="date" required
                                    @class(['form-input', 'form-input-error' => $errors->has('tgl_lahir')]) value="{{ old('tgl_lahir', $karyawan?->tgl_lahir) }}">
                                @error('tgl_lahir') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div>
                            <label for="alamat" class="form-label">Alamat</label>
                            <textarea id="alamat" name="alamat" rows="2"
                                @class(['form-input', 'form-input-error' => $errors->has('alamat')])>{{ old('alamat', $karyawan?->alamat) }}</textarea>
                            @error('alamat') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label for="no_telp" class="form-label">Nomor Telepon</label>
                                <input id="no_telp" name="no_telp" type="tel" maxlength="15" required
                                    @class(['form-input', 'form-input-error' => $errors->has('no_telp')]) value="{{ old('no_telp', $karyawan?->no_telp) }}">
                                @error('no_telp') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="email" class="form-label">Email</label>
                                <input id="email" name="email" type="email" maxlength="100"
                                    @class(['form-input', 'form-input-error' => $errors->has('email')]) value="{{ old('email', $karyawan?->email) }}">
                                @error('email') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label for="status_pernikahan" class="form-label">Status Pernikahan</label>
                                <select id="status_pernikahan" name="status_pernikahan" @class(['form-input', 'form-input-error' => $errors->has('status_pernikahan')])>
                                    <option value="" disabled @selected(old('status_pernikahan', $karyawan?->status_pernikahan) === null || old('status_pernikahan', $karyawan?->status_pernikahan) === '')>Belum diisi</option>
                                    @foreach (['Belum Menikah', 'Menikah'] as $statusPernikahan)
                                        <option value="{{ $statusPernikahan }}" @selected(old('status_pernikahan', $karyawan?->status_pernikahan) === $statusPernikahan)>{{ $statusPernikahan }}</option>
                                    @endforeach
                                </select>
                                @error('status_pernikahan') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="nama_ibu_kandung" class="form-label">Nama Ibu Kandung</label>
                                <input id="nama_ibu_kandung" name="nama_ibu_kandung" type="text" maxlength="100"
                                    @class(['form-input', 'form-input-error' => $errors->has('nama_ibu_kandung')]) value="{{ old('nama_ibu_kandung', $karyawan?->nama_ibu_kandung) }}">
                                @error('nama_ibu_kandung') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Foto</h2>
                    </div>
                    <div class="card-body space-y-3">
                        <img id="pratinjau_foto" alt="Pratinjau foto karyawan"
                            src="{{ $karyawan?->foto ? asset('storage/'.$karyawan->foto) : '' }}"
                            @class(['mx-auto max-h-52 rounded-lg border border-gray-200 object-cover', 'hidden' => ! $karyawan?->foto])>
                        <label for="foto" class="form-label">Pilih foto</label>
                        <input id="foto" name="foto" type="file" accept="image/png,image/jpeg"
                            @class([
                                'block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200',
                                'form-input-error' => $errors->has('foto'),
                            ])>
                        @error('foto') <p class="form-error">{{ $message }}</p> @enderror
                        <p class="form-hint">JPG atau PNG, maksimal 2 MB.{{ $isEditing ? ' Kosongkan bila tidak ingin mengganti.' : '' }}</p>
                    </div>
                </div>

                @if ($isEditing)
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Status Karyawan</h2>
                        </div>
                        <div class="card-body space-y-2">
                            @if ($penempatanAktif)
                                <p class="text-sm"><span class="badge badge-primary">{{ $karyawan->status }}</span></p>
                                <p class="form-hint">Karyawan masih memiliki penempatan Aktif, sehingga status tidak dapat diubah. Minta bagian Operasional mengakhiri penempatannya terlebih dahulu.</p>
                            @else
                                <label for="status" class="form-label">Status</label>
                                <select id="status" name="status" @class(['form-input', 'form-input-error' => $errors->has('status')])>
                                    @foreach ($pilihanStatus as $status)
                                        <option value="{{ $status }}" @selected(old('status', $karyawan->status) === $status)>{{ $status }}</option>
                                    @endforeach
                                </select>
                                @error('status') <p class="form-error">{{ $message }}</p> @enderror
                                <p class="form-hint">
                                    Nonaktif juga menonaktifkan akun login karyawan ini; mengaktifkan kembali akan mengaktifkan akunnya lagi.
                                    @if ($karyawan->kategori === 'Lapangan') Status Ditempatkan diatur otomatis saat penempatan dibuat. @endif
                                </p>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="card js-bagian-lapangan">
                        <div class="card-header">
                            <h2 class="card-title">Akun Login</h2>
                        </div>
                        <div class="card-body text-sm text-gray-600">
                            Akun karyawan Lapangan <span class="font-semibold">dibuat otomatis oleh sistem</span> setelah data disimpan.
                            Username dan password awal = NIP.
                        </div>
                    </div>

                    <div class="card js-bagian-internal">
                        <div class="card-header">
                            <h2 class="card-title">Akun Login Staf</h2>
                        </div>
                        <div class="card-body space-y-4">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="hidden" name="buat_akun" value="0">
                                <input type="checkbox" class="form-check" id="buat_akun" name="buat_akun" value="1" @checked(old('buat_akun'))>
                                Buat akun login untuk staf ini
                            </label>
                            <div class="js-bagian-akun space-y-4">
                                <div>
                                    <label for="id_role" class="form-label">Role</label>
                                    <select id="id_role" name="id_role" @class(['form-input', 'form-input-error' => $errors->has('id_role')])>
                                        <option value="">&mdash; Pilih role &mdash;</option>
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->id }}" @selected((string) old('id_role') === (string) $role->id)>{{ $role->nama_role }}</option>
                                        @endforeach
                                    </select>
                                    @error('id_role') <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="username" class="form-label">Username</label>
                                    <input id="username" name="username" type="text" maxlength="50" autocomplete="off"
                                        @class(['form-input', 'form-input-error' => $errors->has('username')]) value="{{ old('username') }}">
                                    @error('username') <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="password" class="form-label">Password</label>
                                    <input id="password" name="password" type="password" autocomplete="new-password"
                                        @class(['form-input', 'form-input-error' => $errors->has('password')])>
                                    @error('password') <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="password_confirmation" class="form-label">Ulangi Password</label>
                                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="form-input">
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary">Simpan</button>
            <a href="{{ route('hrd.karyawan.index') }}" class="btn-secondary">Batal</a>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        (function () {
            const kategoriTetap = @json($isEditing ? $karyawan->kategori : null);

            function kategoriTerpilih() {
                return kategoriTetap ?? document.querySelector('.js-kategori:checked')?.value ?? 'Lapangan';
            }

            function aturBagian(selector, tampil) {
                document.querySelectorAll(selector).forEach(function (el) {
                    el.classList.toggle('hidden', !tampil);
                    el.querySelectorAll('input, select, textarea').forEach(function (input) {
                        if (input.type !== 'hidden') {
                            input.disabled = !tampil;
                        }
                    });
                });
            }

            function perbarui() {
                const kategori = kategoriTerpilih();
                aturBagian('.js-bagian-lapangan', kategori === 'Lapangan');
                aturBagian('.js-bagian-internal', kategori === 'Internal');

                const sakelarAkun = document.getElementById('buat_akun');
                if (sakelarAkun && kategori === 'Internal') {
                    aturBagian('.js-bagian-akun', sakelarAkun.checked);
                }
            }

            document.querySelectorAll('.js-kategori').forEach(function (radio) {
                radio.addEventListener('change', perbarui);
            });
            document.getElementById('buat_akun')?.addEventListener('change', perbarui);

            document.getElementById('foto').addEventListener('change', function (event) {
                const berkas = event.target.files[0];
                const pratinjau = document.getElementById('pratinjau_foto');
                if (berkas) {
                    pratinjau.src = URL.createObjectURL(berkas);
                    pratinjau.classList.remove('hidden');
                }
            });

            perbarui();
        })();
    </script>
@endpush

@extends('layouts.adminlte')

@php
    $isEditing = $karyawan !== null;
    $kategoriAktif = $isEditing ? $karyawan->kategori : old('kategori', 'Lapangan');
@endphp

@section('title', $isEditing ? 'Ubah Karyawan' : 'Tambah Karyawan')
@section('modul', 'HRD')
@section('heading', $isEditing ? 'Ubah Data Karyawan' : 'Tambah Karyawan')
@section('breadcrumb', 'Data Karyawan')

@section('content')
    <form action="{{ $isEditing ? route('hrd.karyawan.update', $karyawan->id) : route('hrd.karyawan.store') }}"
          method="POST" enctype="multipart/form-data">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-lg-8">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title">Data Kepegawaian</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label for="nip">NIP</label>
                                @if ($isEditing)
                                    <input id="nip" type="text" class="form-control" value="{{ $karyawan->nip }}" disabled>
                                    <small class="form-text text-muted">NIP tidak dapat diubah.</small>
                                @else
                                    <input id="nip" name="nip" type="text" maxlength="20" required
                                        class="form-control @error('nip') is-invalid @enderror" value="{{ old('nip') }}">
                                    @error('nip') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @endif
                            </div>
                            <div class="form-group col-md-8">
                                <label for="nama">Nama Lengkap</label>
                                <input id="nama" name="nama" type="text" maxlength="100" required
                                    class="form-control @error('nama') is-invalid @enderror" value="{{ old('nama', $karyawan?->nama) }}">
                                @error('nama') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="d-block">Kategori</label>
                            @if ($isEditing)
                                <span class="badge badge-{{ $karyawan->kategori === 'Internal' ? 'dark' : 'info' }} p-2">{{ $karyawan->kategori }}</span>
                                <small class="form-text text-muted">Kategori tidak dapat diubah setelah karyawan terdaftar.</small>
                            @else
                                @foreach (['Lapangan' => 'Lapangan (satpam, driver, cleaning service)', 'Internal' => 'Internal (staf kantor)'] as $nilai => $label)
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="kategori_{{ $nilai }}" name="kategori" value="{{ $nilai }}"
                                            class="custom-control-input js-kategori" @checked($kategoriAktif === $nilai)>
                                        <label class="custom-control-label" for="kategori_{{ $nilai }}">{{ $label }}</label>
                                    </div>
                                @endforeach
                                @error('kategori') <div class="text-danger small">{{ $message }}</div> @enderror
                            @endif
                        </div>

                        <div class="js-bagian-lapangan">
                            <div class="form-group">
                                <label for="id_jenis_layanan">Jenis Layanan</label>
                                <select id="id_jenis_layanan" name="id_jenis_layanan"
                                    class="form-control @error('id_jenis_layanan') is-invalid @enderror">
                                    <option value="">&mdash; Pilih jenis layanan &mdash;</option>
                                    @foreach ($jenisLayanan as $layanan)
                                        <option value="{{ $layanan->id }}" @selected((string) old('id_jenis_layanan', $karyawan?->id_jenis_layanan) === (string) $layanan->id)>
                                            {{ $layanan->nama_layanan }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('id_jenis_layanan') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <small class="form-text text-muted">Wajib untuk karyawan Lapangan. Gaji karyawan Lapangan diatur lewat kontrak penempatan.</small>
                            </div>
                        </div>

                        <div class="js-bagian-internal">
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="gaji_pokok">Gaji Pokok (Rp)</label>
                                    <input id="gaji_pokok" name="gaji_pokok" type="number" min="0" step="1000"
                                        class="form-control @error('gaji_pokok') is-invalid @enderror"
                                        value="{{ old('gaji_pokok', $karyawan?->gaji_pokok) }}">
                                    @error('gaji_pokok') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <small class="form-text text-muted">Wajib untuk karyawan Internal.</small>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="tunjangan">Tunjangan (Rp)</label>
                                    <input id="tunjangan" name="tunjangan" type="number" min="0" step="1000"
                                        class="form-control @error('tunjangan') is-invalid @enderror"
                                        value="{{ old('tunjangan', $karyawan?->tunjangan) }}">
                                    @error('tunjangan') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="tgl_masuk">Tanggal Masuk</label>
                            <input id="tgl_masuk" name="tgl_masuk" type="date" required
                                class="form-control @error('tgl_masuk') is-invalid @enderror"
                                value="{{ old('tgl_masuk', $karyawan?->tgl_masuk ?? now()->toDateString()) }}">
                            @error('tgl_masuk') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>

                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title">Data Pribadi</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="no_ktp">Nomor KTP</label>
                                <input id="no_ktp" name="no_ktp" type="text" inputmode="numeric" maxlength="16" required
                                    class="form-control @error('no_ktp') is-invalid @enderror" value="{{ old('no_ktp', $karyawan?->no_ktp) }}">
                                @error('no_ktp') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-group col-md-6">
                                <label class="d-block">Jenis Kelamin</label>
                                @foreach (['L' => 'Laki-laki', 'P' => 'Perempuan'] as $nilai => $label)
                                    <div class="custom-control custom-radio custom-control-inline mt-2">
                                        <input type="radio" id="jk_{{ $nilai }}" name="jenis_kelamin" value="{{ $nilai }}" class="custom-control-input"
                                            @checked(old('jenis_kelamin', $karyawan?->jenis_kelamin) === $nilai)>
                                        <label class="custom-control-label" for="jk_{{ $nilai }}">{{ $label }}</label>
                                    </div>
                                @endforeach
                                @error('jenis_kelamin') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="tempat_lahir">Tempat Lahir</label>
                                <input id="tempat_lahir" name="tempat_lahir" type="text" maxlength="50"
                                    class="form-control @error('tempat_lahir') is-invalid @enderror" value="{{ old('tempat_lahir', $karyawan?->tempat_lahir) }}">
                                @error('tempat_lahir') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-group col-md-6">
                                <label for="tgl_lahir">Tanggal Lahir</label>
                                <input id="tgl_lahir" name="tgl_lahir" type="date" required
                                    class="form-control @error('tgl_lahir') is-invalid @enderror" value="{{ old('tgl_lahir', $karyawan?->tgl_lahir) }}">
                                @error('tgl_lahir') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="alamat">Alamat</label>
                            <textarea id="alamat" name="alamat" rows="2"
                                class="form-control @error('alamat') is-invalid @enderror">{{ old('alamat', $karyawan?->alamat) }}</textarea>
                            @error('alamat') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="no_telp">Nomor Telepon</label>
                                <input id="no_telp" name="no_telp" type="tel" maxlength="15" required
                                    class="form-control @error('no_telp') is-invalid @enderror" value="{{ old('no_telp', $karyawan?->no_telp) }}">
                                @error('no_telp') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="form-group col-md-6">
                                <label for="email">Email</label>
                                <input id="email" name="email" type="email" maxlength="100"
                                    class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $karyawan?->email) }}">
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <h3 class="card-title">Foto</h3>
                    </div>
                    <div class="card-body text-center">
                        <img id="pratinjau_foto" alt="Pratinjau foto karyawan"
                            src="{{ $karyawan?->foto ? asset('storage/'.$karyawan->foto) : '' }}"
                            class="img-thumbnail mb-3 {{ $karyawan?->foto ? '' : 'd-none' }}" style="max-height: 200px;">
                        <div class="custom-file text-left">
                            <input id="foto" name="foto" type="file" accept="image/png,image/jpeg"
                                class="custom-file-input @error('foto') is-invalid @enderror">
                            <label class="custom-file-label" for="foto">Pilih foto&hellip;</label>
                            @error('foto') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <small class="form-text text-muted text-left">JPG atau PNG, maksimal 2 MB.{{ $isEditing ? ' Kosongkan bila tidak ingin mengganti.' : '' }}</small>
                    </div>
                </div>

                @if ($isEditing)
                    <div class="callout callout-info">
                        <p class="mb-1"><strong>Status:</strong> {{ $karyawan->status }}</p>
                        <p class="mb-0 small text-muted">Status dikelola otomatis oleh sistem (penempatan, rotasi).</p>
                    </div>
                @else
                    <div class="card card-outline card-info js-bagian-lapangan">
                        <div class="card-header">
                            <h3 class="card-title">Akun Login</h3>
                        </div>
                        <div class="card-body">
                            <p class="mb-0">
                                Akun karyawan Lapangan <strong>dibuat otomatis oleh sistem</strong> setelah data disimpan.
                                Username dan password awal = NIP.
                            </p>
                        </div>
                    </div>

                    <div class="card card-outline card-dark js-bagian-internal">
                        <div class="card-header">
                            <h3 class="card-title">Akun Login Staf</h3>
                        </div>
                        <div class="card-body">
                            <div class="custom-control custom-switch mb-3">
                                <input type="hidden" name="buat_akun" value="0">
                                <input type="checkbox" class="custom-control-input" id="buat_akun" name="buat_akun" value="1" @checked(old('buat_akun'))>
                                <label class="custom-control-label" for="buat_akun">Buat akun login untuk staf ini</label>
                            </div>
                            <div class="js-bagian-akun">
                                <div class="form-group">
                                    <label for="id_role">Role</label>
                                    <select id="id_role" name="id_role" class="form-control @error('id_role') is-invalid @enderror">
                                        <option value="">&mdash; Pilih role &mdash;</option>
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->id }}" @selected((string) old('id_role') === (string) $role->id)>{{ $role->nama_role }}</option>
                                        @endforeach
                                    </select>
                                    @error('id_role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input id="username" name="username" type="text" maxlength="50" autocomplete="off"
                                        class="form-control @error('username') is-invalid @enderror" value="{{ old('username') }}">
                                    @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="form-group">
                                    <label for="password">Password</label>
                                    <input id="password" name="password" type="password" autocomplete="new-password"
                                        class="form-control @error('password') is-invalid @enderror">
                                    @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="form-group mb-0">
                                    <label for="password_confirmation">Ulangi Password</label>
                                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="mb-4">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan</button>
            <a href="{{ route('hrd.karyawan.index') }}" class="btn btn-default">Batal</a>
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
                    el.classList.toggle('d-none', !tampil);
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
                event.target.nextElementSibling.textContent = berkas ? berkas.name : 'Pilih foto…';
                if (berkas) {
                    pratinjau.src = URL.createObjectURL(berkas);
                    pratinjau.classList.remove('d-none');
                }
            });

            perbarui();
        })();
    </script>
@endpush

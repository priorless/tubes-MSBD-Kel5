{{--
    Form bersama Tambah/Ubah User.
    Variabel: $roles (Collection<Role>), $user (?User), $isEditingSelf (bool)
--}}
@php
    $user ??= null;
    $isEditingSelf ??= false;
    $isEdit = $user !== null;
@endphp

<div class="form-group">
    <label for="nama">Nama <span class="text-danger">*</span></label>
    <input type="text" id="nama" name="nama" value="{{ old('nama', $user?->nama) }}"
           class="form-control @error('nama') is-invalid @enderror" maxlength="100" required autofocus>
    @error('nama')
        <span class="invalid-feedback">{{ $message }}</span>
    @enderror
</div>

<div class="form-group">
    <label for="username">Username <span class="text-danger">*</span></label>
    <input type="text" id="username" name="username" value="{{ old('username', $user?->username) }}"
           class="form-control @error('username') is-invalid @enderror" maxlength="50" autocomplete="off" required>
    @error('username')
        <span class="invalid-feedback">{{ $message }}</span>
    @enderror
    <small class="form-text text-muted">4–50 karakter: huruf, angka, titik (.), tanda hubung (-), atau garis bawah (_).</small>
</div>

<div class="form-group">
    <label for="id_role">Role <span class="text-danger">*</span></label>
    @if ($isEditingSelf)
        <input type="hidden" name="id_role" value="{{ $user->id_role }}">
        <input type="text" id="id_role" class="form-control" value="{{ $user->role?->nama_role }}" disabled>
        <small class="form-text text-muted">Role akun sendiri tidak dapat diubah.</small>
    @else
        <select id="id_role" name="id_role" class="form-control @error('id_role') is-invalid @enderror" required>
            <option value="">-- Pilih Role --</option>
            @foreach ($roles as $role)
                <option value="{{ $role->id }}" @selected((string) old('id_role', $user?->id_role) === (string) $role->id)>
                    {{ $role->nama_role }}@if ($role->deskripsi) — {{ $role->deskripsi }}@endif
                </option>
            @endforeach
        </select>
        @error('id_role')
            <span class="invalid-feedback">{{ $message }}</span>
        @enderror
    @endif
</div>

@if ($isEdit)
    <div class="form-group">
        <label for="status">Status <span class="text-danger">*</span></label>
        @if ($isEditingSelf)
            <input type="hidden" name="status" value="{{ \App\Models\User::StatusAktif }}">
            <input type="text" id="status" class="form-control" value="{{ \App\Models\User::StatusAktif }}" disabled>
            <small class="form-text text-muted">Akun sendiri tidak dapat dinonaktifkan.</small>
        @else
            <select id="status" name="status" class="form-control @error('status') is-invalid @enderror" required>
                @foreach ([\App\Models\User::StatusAktif, \App\Models\User::StatusNonaktif] as $status)
                    <option value="{{ $status }}" @selected(old('status', $user->status) === $status)>{{ $status }}</option>
                @endforeach
            </select>
            @error('status')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        @endif
    </div>
@endif

<div class="form-row">
    <div class="form-group col-md-6">
        <label for="password">
            {{ $isEdit ? 'Reset Password' : 'Password' }}
            @unless ($isEdit)<span class="text-danger">*</span>@endunless
        </label>
        <input type="password" id="password" name="password"
               class="form-control @error('password') is-invalid @enderror" autocomplete="new-password" @required(! $isEdit)>
        @error('password')
            <span class="invalid-feedback">{{ $message }}</span>
        @enderror
        <small class="form-text text-muted">
            Minimal 8 karakter, berisi huruf dan angka.
            @if ($isEdit) Kosongkan jika tidak ingin mengubah password. @endif
        </small>
    </div>
    <div class="form-group col-md-6">
        <label for="password_confirmation">Konfirmasi Password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" autocomplete="new-password" @required(! $isEdit)>
    </div>
</div>

<div class="callout callout-info mb-0">
    <i class="fas fa-info-circle mr-1"></i>
    @if ($isEdit)
        Jika password direset, user wajib menggantinya saat login berikutnya.
    @else
        User wajib mengganti password ini saat login pertama.
    @endif
</div>

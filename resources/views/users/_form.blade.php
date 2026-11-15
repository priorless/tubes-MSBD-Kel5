{{--
    Form bersama Tambah/Ubah User.
    Variabel: $roles (Collection<Role>), $user (?User), $isEditingSelf (bool)
--}}
@php
    $user ??= null;
    $isEditingSelf ??= false;
    $isEdit = $user !== null;
@endphp

<div>
    <label for="nama" class="form-label">Nama <span class="text-red-500">*</span></label>
    <input type="text" id="nama" name="nama" value="{{ old('nama', $user?->nama) }}"
           @class(['form-input', 'form-input-error' => $errors->has('nama')]) maxlength="100" required autofocus>
    @error('nama')
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>

<div>
    <label for="username" class="form-label">Username <span class="text-red-500">*</span></label>
    <input type="text" id="username" name="username" value="{{ old('username', $user?->username) }}"
           @class(['form-input', 'form-input-error' => $errors->has('username')]) maxlength="50" autocomplete="off" required>
    @error('username')
        <p class="form-error">{{ $message }}</p>
    @enderror
    <p class="form-hint">4–50 karakter: huruf, angka, titik (.), tanda hubung (-), atau garis bawah (_).</p>
</div>

<div>
    <label for="id_role" class="form-label">Role <span class="text-red-500">*</span></label>
    @if ($isEditingSelf)
        <input type="hidden" name="id_role" value="{{ $user->id_role }}">
        <input type="text" id="id_role" class="form-input" value="{{ $user->role?->nama_role }}" disabled>
        <p class="form-hint">Role akun sendiri tidak dapat diubah.</p>
    @else
        <select id="id_role" name="id_role" @class(['form-input', 'form-input-error' => $errors->has('id_role')]) required>
            <option value="">-- Pilih Role --</option>
            @foreach ($roles as $role)
                <option value="{{ $role->id }}" @selected((string) old('id_role', $user?->id_role) === (string) $role->id)>
                    {{ $role->nama_role }}@if ($role->deskripsi) — {{ $role->deskripsi }}@endif
                </option>
            @endforeach
        </select>
        @error('id_role')
            <p class="form-error">{{ $message }}</p>
        @enderror
    @endif
</div>

@if ($isEdit)
    <div>
        <label for="status" class="form-label">Status <span class="text-red-500">*</span></label>
        @if ($isEditingSelf)
            <input type="hidden" name="status" value="{{ \App\Models\User::StatusAktif }}">
            <input type="text" id="status" class="form-input" value="{{ \App\Models\User::StatusAktif }}" disabled>
            <p class="form-hint">Akun sendiri tidak dapat dinonaktifkan.</p>
        @else
            <select id="status" name="status" @class(['form-input', 'form-input-error' => $errors->has('status')]) required>
                @foreach ([\App\Models\User::StatusAktif, \App\Models\User::StatusNonaktif] as $status)
                    <option value="{{ $status }}" @selected(old('status', $user->status) === $status)>{{ $status }}</option>
                @endforeach
            </select>
            @error('status')
                <p class="form-error">{{ $message }}</p>
            @enderror
        @endif
    </div>
@endif

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label for="password" class="form-label">
            {{ $isEdit ? 'Reset Password' : 'Password' }}
            @unless ($isEdit)<span class="text-red-500">*</span>@endunless
        </label>
        <input type="password" id="password" name="password"
               @class(['form-input', 'form-input-error' => $errors->has('password')]) autocomplete="new-password" @required(! $isEdit)>
        @error('password')
            <p class="form-error">{{ $message }}</p>
        @enderror
        <p class="form-hint">
            Minimal 8 karakter, berisi huruf dan angka.
            @if ($isEdit) Kosongkan jika tidak ingin mengubah password. @endif
        </p>
    </div>
    <div>
        <label for="password_confirmation" class="form-label">Konfirmasi Password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" class="form-input" autocomplete="new-password" @required(! $isEdit)>
    </div>
</div>

<div class="callout callout-info">
    @if ($isEdit)
        Jika password direset, user wajib menggantinya saat login berikutnya.
    @else
        User wajib mengganti password ini saat login pertama.
    @endif
</div>

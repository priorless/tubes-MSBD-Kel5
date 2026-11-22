@extends('layouts.app')

@section('title', 'Ganti Password')

@section('content')
    <x-ui.page-header title="Ganti Password" description="Gunakan password yang kuat dan jangan bagikan kepada siapa pun." />

    <div class="max-w-xl space-y-6">
        @if ($user->must_change_password)
            <div class="callout callout-warning">
                <p class="font-semibold">Wajib ganti password</p>
                <p class="mt-1">Akun Anda masih memakai password awal. Menu lain akan terbuka setelah Anda mengganti password.</p>
            </div>
        @endif

        <form method="POST" action="{{ route('password.change.update') }}" class="card" novalidate>
            @csrf
            @method('PUT')

            <div class="card-header">
                <h2 class="card-title">Password Baru</h2>
            </div>

            <div class="card-body space-y-4">
                <div>
                    <label for="current_password" class="form-label">Password Lama</label>
                    <input type="password" id="current_password" name="current_password"
                           @class(['form-input', 'form-input-error' => $errors->has('current_password')])
                           autocomplete="current-password" required>
                    @error('current_password')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="form-label">Password Baru</label>
                    <input type="password" id="password" name="password"
                           @class(['form-input', 'form-input-error' => $errors->has('password')])
                           autocomplete="new-password" required>
                    @error('password')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                    <p class="form-hint">Minimal 8 karakter, berisi huruf dan angka.</p>
                </div>

                <div>
                    <label for="password_confirmation" class="form-label">Konfirmasi Password Baru</label>
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           class="form-input" autocomplete="new-password" required>
                </div>
            </div>

            <div class="card-footer justify-end">
                <button type="submit" class="btn-primary">Simpan Password</button>
            </div>
        </form>
    </div>
@endsection

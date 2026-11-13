@extends('layouts.admin')

@section('title', 'Ganti Password')
@section('heading', 'Ganti Password')

@section('content')
<div class="row">
    <div class="col-lg-6">
        @if ($user->must_change_password)
            <div class="callout callout-warning">
                <h5><i class="fas fa-shield-alt mr-1"></i> Wajib ganti password</h5>
                <p class="mb-0">Akun Anda masih memakai password awal dari administrator. Menu lain akan terbuka setelah Anda mengganti password.</p>
            </div>
        @endif

        <div class="card card-primary">
            <div class="card-header">
                <h3 class="card-title">Password Baru</h3>
            </div>

            <form method="POST" action="{{ route('password.change.update') }}" novalidate>
                @csrf
                @method('PUT')

                <div class="card-body">
                    <div class="form-group">
                        <label for="current_password">Password Lama</label>
                        <input type="password" id="current_password" name="current_password"
                               class="form-control @error('current_password') is-invalid @enderror"
                               autocomplete="current-password" required>
                        @error('current_password')
                            <span class="invalid-feedback">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="password">Password Baru</label>
                        <input type="password" id="password" name="password"
                               class="form-control @error('password') is-invalid @enderror"
                               autocomplete="new-password" required>
                        @error('password')
                            <span class="invalid-feedback">{{ $message }}</span>
                        @enderror
                        <small class="form-text text-muted">Minimal 8 karakter, berisi huruf dan angka.</small>
                    </div>

                    <div class="form-group mb-0">
                        <label for="password_confirmation">Konfirmasi Password Baru</label>
                        <input type="password" id="password_confirmation" name="password_confirmation"
                               class="form-control" autocomplete="new-password" required>
                    </div>
                </div>

                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-1"></i> Simpan Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

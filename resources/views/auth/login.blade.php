@extends('layouts.guest')

@section('title', 'Masuk')

@section('content')
<div class="card card-outline card-primary">
    <div class="card-body login-card-body">
        <p class="login-box-msg">Silakan masuk untuk memulai sesi Anda</p>

        <form method="POST" action="{{ route('login.store') }}" novalidate>
            @csrf

            <div class="input-group mb-3">
                <input type="text" name="username" value="{{ old('username') }}"
                       class="form-control @error('username') is-invalid @enderror"
                       placeholder="Username" autocomplete="username" autofocus required>
                <div class="input-group-append">
                    <div class="input-group-text"><span class="fas fa-user"></span></div>
                </div>
                @error('username')
                    <span class="invalid-feedback" role="alert">{{ $message }}</span>
                @enderror
            </div>

            <div class="input-group mb-3">
                <input type="password" name="password"
                       class="form-control @error('password') is-invalid @enderror"
                       placeholder="Password" autocomplete="current-password" required>
                <div class="input-group-append">
                    <div class="input-group-text"><span class="fas fa-lock"></span></div>
                </div>
                @error('password')
                    <span class="invalid-feedback" role="alert">{{ $message }}</span>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-sign-in-alt mr-1"></i> Masuk
            </button>
        </form>

        <p class="mt-3 mb-0 text-muted small text-center">
            Belum punya akun atau lupa password? Hubungi bagian HRD.
        </p>
    </div>
</div>
@endsection

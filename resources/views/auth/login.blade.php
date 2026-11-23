@extends('layouts.guest')

@section('title', 'Masuk')

@section('content')
    <a href="{{ route('landing') }}" class="mb-4 inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-sji-biru">&larr; Kembali ke Beranda</a>

    <div class="card p-6 sm:p-8">
        <h1 class="text-xl font-semibold text-gray-800">Masuk</h1>
        <p class="mt-1 text-sm text-gray-500">Silakan masuk untuk memulai sesi Anda.</p>

        <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4" novalidate>
            @csrf

            <div>
                <label for="username" class="form-label">Username</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}"
                       @class(['form-input', 'form-input-error' => $errors->has('username')])
                       autocomplete="username" autofocus required>
                @error('username')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="form-label">Password</label>
                <input type="password" id="password" name="password"
                       @class(['form-input', 'form-input-error' => $errors->has('password')])
                       autocomplete="current-password" required>
                @error('password')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="btn-primary w-full">Masuk</button>
        </form>
    </div>

    <p class="mt-4 text-center text-sm text-gray-500">Belum punya akun atau lupa password? Hubungi bagian HRD.</p>
@endsection

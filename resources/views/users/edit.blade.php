@extends('layouts.app')

@section('title', 'Ubah User')

@section('content')
    <form method="POST" action="{{ route('users.update', $user) }}" class="card max-w-2xl" novalidate>
        @csrf
        @method('PUT')

        <div class="card-header">
            <h2 class="card-title">Ubah Akun: <span class="font-mono">{{ $user->username }}</span></h2>
        </div>

        <div class="card-body space-y-4">
            @include('users._form', ['roles' => $roles, 'user' => $user, 'isEditingSelf' => $isEditingSelf])
        </div>

        <div class="card-footer justify-end">
            <a href="{{ route('users.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan Perubahan</button>
        </div>
    </form>
@endsection

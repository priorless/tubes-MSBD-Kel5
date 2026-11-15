@extends('layouts.app')

@section('title', 'Tambah User')

@section('content')
    <form method="POST" action="{{ route('users.store') }}" class="card max-w-2xl" novalidate>
        @csrf

        <div class="card-header">
            <h2 class="card-title">Data Akun Staff Baru</h2>
        </div>

        <div class="card-body space-y-4">
            @include('users._form', ['roles' => $roles])
        </div>

        <div class="card-footer justify-end">
            <a href="{{ route('users.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan</button>
        </div>
    </form>
@endsection

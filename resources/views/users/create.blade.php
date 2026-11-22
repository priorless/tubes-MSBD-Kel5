@extends('layouts.app')

@section('title', 'Tambah User')

@section('content')
    <x-ui.page-header title="Data Akun Staff Baru" />

    <form method="POST" action="{{ route('users.store') }}" class="card max-w-2xl" novalidate>
        @csrf

        <div class="card-body space-y-4">
            @include('users._form', ['roles' => $roles])
        </div>

        <div class="card-footer justify-end">
            <a href="{{ route('users.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan</button>
        </div>
    </form>
@endsection

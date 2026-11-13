@extends('layouts.admin')

@section('title', 'Ubah User')
@section('heading', 'Ubah User')

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card card-warning">
            <div class="card-header">
                <h3 class="card-title">Ubah Akun: {{ $user->username }}</h3>
            </div>

            <form method="POST" action="{{ route('users.update', $user) }}" novalidate>
                @csrf
                @method('PUT')

                <div class="card-body">
                    @include('users._form', ['roles' => $roles, 'user' => $user, 'isEditingSelf' => $isEditingSelf])
                </div>

                <div class="card-footer">
                    <button type="submit" class="btn btn-warning"><i class="fas fa-save mr-1"></i> Simpan Perubahan</button>
                    <a href="{{ route('users.index') }}" class="btn btn-default">Batal</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

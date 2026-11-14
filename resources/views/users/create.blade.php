@extends('layouts.admin')

@section('title', 'Tambah User')
@section('heading', 'Tambah User')

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card card-primary">
            <div class="card-header">
                <h3 class="card-title">Data Akun Staff Baru</h3>
            </div>

            <form method="POST" action="{{ route('users.store') }}" novalidate>
                @csrf

                <div class="card-body">
                    @include('users._form', ['roles' => $roles])
                </div>

                <div class="card-footer">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan</button>
                    <a href="{{ route('users.index') }}" class="btn btn-default">Batal</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

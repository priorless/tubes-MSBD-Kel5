@extends('layouts.admin')

@section('title', 'Beranda')
@section('heading', 'Beranda')

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card card-primary card-outline">
            <div class="card-body">
                <h4 class="mb-1">Selamat datang, {{ $user->namaTampilan() }}</h4>
                <p class="text-muted mb-3">Anda login sebagai <strong>{{ $user->role?->nama_role }}</strong>.</p>
                <p class="mb-0">
                    @if ($user->role?->deskripsi)
                        Cakupan tugas: {{ $user->role->deskripsi }}.
                    @endif
                    Gunakan menu di sebelah kiri untuk mengakses modul yang tersedia untuk Anda.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection

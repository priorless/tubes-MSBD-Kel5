@extends('layouts.app')

@section('title', 'Beranda')

@section('content')
    <x-ui.page-header :kicker="now()->locale('id')->translatedFormat('l, j F Y').' · Beranda'"
        :title="'Selamat datang, '.$user->namaTampilan()"
        :description="'Anda login sebagai '.($user->role?->nama_role ?? '-').'.'" />

    <div class="card max-w-2xl p-6">
        <p class="text-sm text-gray-600">
            @if ($user->role?->deskripsi)
                Cakupan tugas: {{ $user->role->deskripsi }}.
            @endif
            Gunakan menu di sebelah kiri untuk mengakses modul yang tersedia untuk Anda.
        </p>
    </div>
@endsection

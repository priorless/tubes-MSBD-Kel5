@extends('layouts.app')

@section('title', 'Beranda')

@section('content')
    <div class="card max-w-2xl p-6">
        <h2 class="text-lg font-semibold text-gray-800">Selamat datang, {{ $user->namaTampilan() }}</h2>
        <p class="mt-1 text-sm text-gray-500">Anda login sebagai <span class="font-medium text-gray-700">{{ $user->role?->nama_role }}</span>.</p>
        <p class="mt-4 text-sm text-gray-600">
            @if ($user->role?->deskripsi)
                Cakupan tugas: {{ $user->role->deskripsi }}.
            @endif
            Gunakan menu di sebelah kiri untuk mengakses modul yang tersedia untuk Anda.
        </p>
    </div>
@endsection

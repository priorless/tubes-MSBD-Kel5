@extends('layouts.tenaga-kerja')

@section('title', 'Profil Saya')

@php
    $judulGrup = [
        'identitas'  => 'Data Pribadi',
        'pekerjaan'  => 'Informasi Pekerjaan',
        'kompensasi' => 'Kompensasi',
        'lainnya'    => 'Informasi Lainnya',
    ];
@endphp

@section('content')
@if (! $karyawan)
    <section class="tk-reveal card px-6 py-12 text-center">
        <p class="font-semibold text-gray-800">Data karyawan tidak ditemukan</p>
        <p class="mt-1 text-sm text-gray-500">Hubungi bagian HRD untuk memastikan akun Anda terhubung dengan data karyawan.</p>
    </section>
@else
    {{-- ===================== Kartu identitas ===================== --}}
    <section class="tk-reveal relative mb-6 overflow-hidden rounded-lg bg-sji-biru-tua p-6 text-white shadow-sm">
        <div class="absolute inset-y-0 left-0 w-1.5 bg-sji-merah"></div>
        <div class="flex flex-wrap items-center gap-5">
            <div class="flex size-20 items-center justify-center rounded-full bg-white/15 text-3xl font-bold ring-4 ring-sji-emas/60">{{ $inisial }}</div>
            <div>
                <h2 class="text-2xl font-bold">{{ $namaKaryawan }}</h2>
                <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-blue-200">
                    @if ($jabatan)<span>{{ $jabatan }}</span>@endif
                    @if ($kategori)
                        <span class="rounded-full border border-white/30 bg-white/10 px-3 py-0.5 text-white">Karyawan {{ $kategori }}</span>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- ===================== Detail per kelompok ===================== --}}
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
        @foreach ($kelompok as $grup => $isi)
            @continue(empty($isi))
            <section @class(['tk-reveal card', 'xl:col-span-7' => $grup === 'identitas', 'xl:col-span-5' => $grup !== 'identitas'])>
                <div class="card-header">
                    <h2 class="card-title">{{ $judulGrup[$grup] ?? ucfirst($grup) }}</h2>
                </div>
                <dl class="divide-y divide-gray-100">
                    @foreach ($isi as $item)
                        <div class="grid grid-cols-3 gap-4 px-6 py-3 text-sm">
                            <dt class="text-gray-500">{{ $item['label'] }}</dt>
                            <dd class="col-span-2 font-medium text-gray-800">{{ $item['nilai'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>

    <div class="tk-reveal callout callout-info mt-6">
        Data profil dikelola oleh bagian HRD. Jika ada data yang tidak sesuai, silakan hubungi HRD.
    </div>
@endif
@endsection

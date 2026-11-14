@extends('layouts.tenaga-kerja')

@section('title', 'Profil Saya')

@php
    $judulGrup = [
        'identitas'  => ['Data Pribadi', 'bi-person-lines-fill'],
        'pekerjaan'  => ['Informasi Pekerjaan', 'bi-briefcase'],
        'kompensasi' => ['Kompensasi', 'bi-wallet2'],
        'lainnya'    => ['Informasi Lainnya', 'bi-three-dots'],
    ];
@endphp

@section('content')
@if (!$karyawan)
    <section class="tk-card tk-reveal">
        <div class="tk-kosong">
            <div class="tk-kosong-ilustrasi"><i class="bi bi-person-x"></i></div>
            <p class="tk-panel-judul">Data karyawan tidak ditemukan</p>
            <p class="tk-panel-sub">Hubungi bagian HRD untuk memastikan akun Anda terhubung dengan data karyawan.</p>
        </div>
    </section>
@else
    {{-- ===================== KARTU IDENTITAS ===================== --}}
    <section class="tk-profil-cover tk-reveal">
        <div class="tk-hero-pola"></div>
        <div class="tk-profil-isi">
            <div class="tk-avatar tk-avatar-xl">{{ $inisial }}</div>
            <div class="tk-profil-teks">
                <h2 class="tk-profil-nama">{{ $namaKaryawan }}</h2>
                <div class="tk-profil-meta">
                    @if ($jabatan)<span><i class="bi bi-briefcase"></i> {{ $jabatan }}</span>@endif
                    @if ($kategori)
                        <span class="tk-badge tk-badge-kaca">
                            <i class="bi {{ strtolower($kategori) === 'internal' ? 'bi-building' : 'bi-geo-alt' }}"></i>
                            Karyawan {{ $kategori }}
                        </span>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- ===================== DETAIL PER KELOMPOK ===================== --}}
    <div class="row g-4 mt-1">
        @foreach ($kelompok as $grup => $isi)
            @continue(empty($isi))
            <div class="col-12 {{ $grup === 'identitas' ? 'col-xl-7' : 'col-xl-5' }} tk-reveal">
                <section class="tk-card h-100">
                    <h2 class="tk-card-title"><i class="bi {{ $judulGrup[$grup][1] }}"></i> {{ $judulGrup[$grup][0] }}</h2>
                    <dl class="tk-info-list">
                        @foreach ($isi as $item)
                            <div class="tk-info-item">
                                <dt><i class="bi {{ $item['ikon'] }}"></i> {{ $item['label'] }}</dt>
                                <dd>{{ $item['nilai'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            </div>
        @endforeach
    </div>

    <div class="tk-catatan mt-4 tk-reveal">
        <i class="bi bi-info-circle"></i>
        Data profil dikelola oleh bagian HRD. Jika ada data yang tidak sesuai, silakan hubungi HRD.
    </div>
@endif
@endsection

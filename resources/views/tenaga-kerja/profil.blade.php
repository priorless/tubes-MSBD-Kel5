@extends('layouts.tenaga-kerja')

@section('title', 'Profil Saya')
@section('heading', 'Profil Saya')

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">Data karyawan</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">NIP</dt>
                        <dd class="col-sm-8">{{ $karyawan->nip }}</dd>
                        <dt class="col-sm-4">Nama</dt>
                        <dd class="col-sm-8">{{ $karyawan->nama }}</dd>
                        <dt class="col-sm-4">Kategori</dt>
                        <dd class="col-sm-8">{{ $karyawan->kategori }}</dd>
                        <dt class="col-sm-4">Tanggal lahir</dt>
                        <dd class="col-sm-8">{{ $karyawan->tgl_lahir }}</dd>
                        <dt class="col-sm-4">Jenis kelamin</dt>
                        <dd class="col-sm-8">{{ $karyawan->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan' }}</dd>
                        <dt class="col-sm-4">Alamat</dt>
                        <dd class="col-sm-8">{{ $karyawan->alamat ?? '-' }}</dd>
                        <dt class="col-sm-4">Nomor telepon</dt>
                        <dd class="col-sm-8">{{ $karyawan->no_telp }}</dd>
                        <dt class="col-sm-4">Email</dt>
                        <dd class="col-sm-8">{{ $karyawan->email ?? '-' }}</dd>
                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">{{ $karyawan->status }}</dd>
                        <dt class="col-sm-4">Tanggal masuk</dt>
                        <dd class="col-sm-8">{{ $karyawan->tgl_masuk }}</dd>
                        @if ($karyawan->kategori === 'Internal')
                            <dt class="col-sm-4">Gaji pokok</dt>
                            <dd class="col-sm-8">Rp {{ number_format((float) $karyawan->gaji_pokok, 0, ',', '.') }}</dd>
                            <dt class="col-sm-4">Tunjangan</dt>
                            <dd class="col-sm-8">Rp {{ number_format((float) $karyawan->tunjangan, 0, ',', '.') }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection

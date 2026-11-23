{{--
    Layout Portal Karyawan (modul Tenaga Kerja).
    Memakai layout utama (sidebar, header, notifikasi) dan menambahkan interaksi khusus modul ini:
    jam server, check-in/out tanpa reload, pencarian riwayat, dan pemilih periode slip gaji.
--}}
@extends('layouts.app')

@push('scripts')
    <script src="{{ asset('js/tenaga-kerja.js') }}?v=10"></script>
@endpush

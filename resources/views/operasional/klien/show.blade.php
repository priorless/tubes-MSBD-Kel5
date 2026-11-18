@extends('layouts.app')

@section('title', 'Detail Klien')

@php
    $aktif = $klien->status === 'Aktif';
    $pesanKonfirmasi = $aktif
        ? 'Nonaktifkan kerja sama dengan '.$klien->nama_perusahaan.'?'
            .($penempatanAktif->isEmpty() ? '' : ' Masih ada '.$penempatanAktif->count().' karyawan yang ditempatkan; penempatan mereka tidak berubah.')
        : 'Aktifkan kembali kerja sama dengan '.$klien->nama_perusahaan.'?';
@endphp

@section('content')
    <x-ui.page-header :title="$klien->nama_perusahaan" :description="'Kode klien '.$klien->kode_klien.' · Status kerja sama: '.$klien->status">
        <a href="{{ route('operasional.klien.index') }}" class="btn-secondary">Daftar Klien</a>
        <a href="{{ route('operasional.klien.edit', $klien->id) }}" class="btn-secondary">Edit Informasi</a>
        @if ($aktif)
            <a href="{{ route('operasional.penempatan.create', ['id_klien' => $klien->id]) }}" class="btn-primary">+ Buat Penempatan</a>
        @endif
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section class="card">
            <div class="card-header"><h3 class="card-title">Informasi perusahaan</h3></div>
            <dl class="card-body space-y-3 text-sm">
                <div><dt class="text-gray-500">Nama perusahaan</dt><dd class="font-medium text-gray-800">{{ $klien->nama_perusahaan }}</dd></div>
                <div><dt class="text-gray-500">Alamat</dt><dd class="text-gray-800">{{ $klien->alamat }}</dd></div>
                <div>
                    <dt class="text-gray-500">Status kerja sama</dt>
                    <dd><span class="badge badge-{{ $aktif ? 'success' : 'secondary' }}">{{ $klien->status }}</span></dd>
                </div>
                <div>
                    <dt class="text-gray-500">Management fee</dt>
                    @if (! $managementFeeTersedia)
                        <dd class="text-amber-700">Belum tersedia &mdash; database belum dimigrasi</dd>
                    @elseif ($klien->management_fee === null)
                        <dd class="text-gray-500">Belum ditentukan</dd>
                    @else
                        <dd class="font-medium text-gray-800">{{ number_format((float) $klien->management_fee, 2, ',', '.') }}%</dd>
                    @endif
                </div>
                <div>
                    <dt class="text-gray-500">BPJS</dt>
                    @if (! $bpjsTersedia)
                        <dd class="text-amber-700">Belum tersedia &mdash; database belum dimigrasi</dd>
                    @elseif ($klien->bpjs_metode === null)
                        <dd class="text-gray-500">Tanpa BPJS</dd>
                    @else
                        <dd class="font-medium text-gray-800">{{ \App\Support\LabelBpjs::aturan($klien->bpjs_metode, $klien->bpjs_nilai) }}</dd>
                    @endif
                </div>
            </dl>
        </section>

        <section class="card">
            <div class="card-header"><h3 class="card-title">Kontak perusahaan</h3></div>
            <dl class="card-body space-y-3 text-sm">
                <div><dt class="text-gray-500">Penanggung jawab (PIC)</dt><dd class="font-medium text-gray-800">{{ $klien->pic_nama }}@if ($klien->pic_jabatan) <span class="font-normal text-gray-500">&mdash; {{ $klien->pic_jabatan }}</span>@endif</dd></div>
                <div><dt class="text-gray-500">Telepon</dt><dd class="text-gray-800">{{ $klien->pic_telp }}</dd></div>
                <div><dt class="text-gray-500">Email</dt><dd class="text-gray-800">{{ $klien->pic_email ?? '—' }}</dd></div>
            </dl>
        </section>

        <section class="card">
            <div class="card-header"><h3 class="card-title">Jenis layanan</h3></div>
            <div class="card-body text-sm">
                @forelse ($layanan as $baris)
                    <div class="flex items-center justify-between border-b border-gray-100 py-2 last:border-0">
                        <span class="badge badge-info">{{ $baris->nama_layanan ?? 'Tanpa layanan' }}</span>
                        <span class="text-gray-700">{{ $baris->jumlah }} karyawan</span>
                    </div>
                @empty
                    <p class="text-gray-500">Belum ada karyawan yang ditempatkan, sehingga jenis layanan belum terlihat.</p>
                @endforelse
                <p class="form-hint mt-2">Jenis layanan diambil dari data HRD karyawan yang sedang ditempatkan.</p>
            </div>
        </section>
    </div>

    <section class="card mt-6">
        <div class="card-header">
            <div>
                <h3 class="card-title">Karyawan yang ditempatkan</h3>
                <p class="mt-1 text-sm text-gray-500">{{ $penempatanAktif->count() }} karyawan dengan penempatan Aktif.</p>
            </div>
        </div>
        @if ($penempatanAktif->isEmpty())
            <div class="card-body">
                <x-ui.empty-state title="Belum ada karyawan yang ditempatkan"
                    :message="$aktif ? 'Buat penempatan untuk mulai menugaskan karyawan di klien ini.' : 'Aktifkan kembali kerja sama sebelum membuat penempatan baru.'">
                    @if ($aktif)
                        <a href="{{ route('operasional.penempatan.create', ['id_klien' => $klien->id]) }}" class="btn-primary btn-sm">+ Buat Penempatan</a>
                    @endif
                </x-ui.empty-state>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Karyawan</th><th>Jenis Layanan</th><th>Lokasi Kerja</th><th>Periode</th><th class="text-right">Jadwal</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($penempatanAktif as $item)
                            <tr>
                                <td>{{ $item->nama }}<p class="text-xs text-gray-400">{{ $item->nip }}</p></td>
                                <td>{{ $item->nama_layanan ?? '—' }}</td>
                                <td>{{ $item->lokasi_kerja ?? '—' }}</td>
                                <td class="whitespace-nowrap">
                                    {{ \Carbon\Carbon::parse($item->tgl_mulai)->format('d/m/Y') }} &ndash;
                                    {{ $item->tgl_selesai === null ? 'sekarang' : \Carbon\Carbon::parse($item->tgl_selesai)->format('d/m/Y') }}
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('operasional.jadwal-kerja', ['id_karyawan' => $item->id_karyawan]) }}" class="btn-secondary btn-sm">Lihat Jadwal</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card mt-6">
        <div class="card-header">
            <div>
                <h3 class="card-title">Riwayat kerja sama</h3>
                <p class="mt-1 text-sm text-gray-500">
                    @if ($kerjaSamaSejak)
                        Penempatan pertama dimulai {{ \Carbon\Carbon::parse($kerjaSamaSejak)->format('d/m/Y') }}.
                    @else
                        Belum ada penempatan di klien ini.
                    @endif
                </p>
            </div>
        </div>
        @if ($riwayatPenempatan->isEmpty())
            <p class="card-body text-sm text-gray-500">Belum ada penempatan yang selesai atau dirotasi.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Karyawan</th><th>Periode</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($riwayatPenempatan as $item)
                            <tr>
                                <td>{{ $item->nama }}<p class="text-xs text-gray-400">{{ $item->nip }}</p></td>
                                <td class="whitespace-nowrap">
                                    {{ \Carbon\Carbon::parse($item->tgl_mulai)->format('d/m/Y') }} &ndash;
                                    {{ $item->tgl_selesai === null ? '—' : \Carbon\Carbon::parse($item->tgl_selesai)->format('d/m/Y') }}
                                </td>
                                <td><span class="badge badge-secondary">{{ $item->status === 'Rotasi' ? 'Dirotasi' : $item->status }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card mt-6">
        <div class="card-body flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="font-semibold text-gray-800">{{ $aktif ? 'Nonaktifkan kerja sama' : 'Aktifkan kembali kerja sama' }}</h3>
                <p class="text-sm text-gray-500">
                    {{ $aktif
                        ? 'Klien tidak dapat dipilih untuk penempatan baru. Data tetap tersimpan.'
                        : 'Klien dapat dipilih kembali untuk penempatan.' }}
                </p>
            </div>
            <form method="POST" action="{{ route('operasional.klien.status', $klien->id) }}" onsubmit="return confirm(@js($pesanKonfirmasi))">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="{{ $aktif ? 'Nonaktif' : 'Aktif' }}">
                <button type="submit" class="{{ $aktif ? 'btn-outline-danger' : 'btn-success' }}">{{ $aktif ? 'Nonaktifkan' : 'Aktifkan Kembali' }}</button>
            </form>
        </div>
    </section>
@endsection

@extends('layouts.app')

@section('title', 'Gunakan Pola Jadwal')

@php
    $pribadi = $template->jenis === 'Pribadi';
    $jumlahKonflik = $pratinjau === null ? 0 : collect($pratinjau['karyawan'])->sum('jumlah_konflik');
    $labelKondisi = [
        'baru' => ['Baru', 'success'],
        'konflik' => ['Sudah berjadwal', 'warning'],
        'di_luar_penempatan' => ['Di luar penempatan', 'secondary'],
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Gunakan Pola: {{ $template->nama_template }}</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Pola {{ $template->jenis }} &mdash;
                        @if ($pribadi)
                            {{ $template->nip }} {{ $template->nama_karyawan }}
                        @else
                            {{ $template->nama_perusahaan }} ({{ $template->nama_layanan ?? 'semua jenis layanan' }})
                        @endif
                    </p>
                </div>
                <a href="{{ route('operasional.jadwal-kerja.template-jadwal.show', $template->id) }}" class="btn-secondary btn-sm">Kembali ke Detail</a>
            </div>

            <form method="GET" action="{{ route('operasional.jadwal-kerja.template-jadwal.apply.preview', $template->id) }}" class="card-body space-y-4">
                <h3 class="font-semibold text-gray-800">1. Pilih bulan{{ $pribadi ? '' : ' dan karyawan' }}</h3>
                <div class="max-w-xs">
                    <label for="bulan" class="form-label">Bulan &amp; Tahun Tujuan</label>
                    <input id="bulan" name="bulan" type="month" class="form-input" value="{{ $bulanAwal }}" required>
                </div>

                @unless ($pribadi)
                    <fieldset>
                        <legend class="form-label">Karyawan Penerima</legend>
                        <p class="form-hint mb-2">Hanya karyawan dengan penempatan Aktif di klien ini{{ $template->id_jenis_layanan === null ? '' : ' dan layanan '.$template->nama_layanan }} yang ditampilkan.</p>
                        @forelse ($kandidatKaryawan as $item)
                            <label class="flex items-center gap-2 py-1 text-sm">
                                <input type="checkbox" name="id_karyawan[]" value="{{ $item->id }}" @checked(in_array((int) $item->id, $idKaryawanTerpilih, true))>
                                {{ $item->nip }} &mdash; {{ $item->nama }}
                            </label>
                        @empty
                            <p class="text-sm text-gray-500">Tidak ada karyawan yang memenuhi syarat pola ini.</p>
                        @endforelse
                        @if ($bulan !== null && $idKaryawanTerpilih === [])
                            <p class="form-error">Pilih minimal satu karyawan penerima.</p>
                        @endif
                    </fieldset>
                @endunless

                <button type="submit" class="btn-secondary">Tampilkan Pratinjau</button>
            </form>
        </div>

        @if ($pratinjau !== null)
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">2. Pratinjau {{ \Carbon\Carbon::parse($pratinjau['bulan'].'-01')->locale('id')->translatedFormat('F Y') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">Hari yang Belum Diatur pada pola tidak mengubah jadwal karyawan.</p>
                    </div>
                </div>
                <div class="card-body space-y-4">
                    @if ($pratinjau['hari_tidak_tersedia'] !== [])
                        <div class="callout callout-info">
                            Hari ke-{{ implode(', ', $pratinjau['hari_tidak_tersedia']) }} pada pola dilewati karena tidak ada pada bulan tujuan.
                        </div>
                    @endif

                    @foreach ($pratinjau['karyawan'] as $karyawan)
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h4 class="font-semibold text-gray-800">{{ $karyawan['nip'] }} &mdash; {{ $karyawan['nama'] ?? 'Karyawan #'.$karyawan['id_karyawan'] }}</h4>
                                @if ($karyawan['layak'])
                                    <div class="flex flex-wrap gap-1 text-xs">
                                        <span class="badge badge-success">{{ $karyawan['jumlah_baru'] }} baru</span>
                                        <span class="badge badge-warning">{{ $karyawan['jumlah_konflik'] }} sudah berjadwal</span>
                                        <span class="badge badge-secondary">{{ $karyawan['jumlah_di_luar_penempatan'] }} di luar penempatan</span>
                                    </div>
                                @else
                                    <span class="badge badge-danger">Tidak memenuhi syarat</span>
                                @endif
                            </div>

                            @if (! $karyawan['layak'])
                                <p class="mt-2 text-sm text-red-700">{{ $karyawan['alasan'] }}</p>
                            @else
                                <div class="mt-3 overflow-x-auto">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Tanggal</th>
                                                <th>Pola</th>
                                                <th>Jadwal Saat Ini</th>
                                                <th>Kondisi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($karyawan['tanggal'] as $baris)
                                                @php
                                                    $polaHari = $baris['pola'];
                                                    $lama = $baris['jadwal_lama'];
                                                    [$label, $warna] = $labelKondisi[$baris['kondisi']];
                                                @endphp
                                                <tr>
                                                    <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($baris['tanggal'])->format('d/m/Y') }}</td>
                                                    <td>
                                                        @if ($polaHari->status === 'Kerja')
                                                            Kerja {{ substr($polaHari->jam_masuk, 0, 5) }}&ndash;{{ substr($polaHari->jam_pulang, 0, 5) }}
                                                            @if (substr($polaHari->jam_pulang, 0, 5) <= substr($polaHari->jam_masuk, 0, 5)) (+1 hari) @endif
                                                        @else
                                                            Libur
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if ($lama === null)
                                                            <span class="text-gray-400">Belum Diatur</span>
                                                        @elseif ($lama->status === 'Kerja')
                                                            Kerja {{ substr($lama->jam_masuk, 0, 5) }}&ndash;{{ substr($lama->jam_pulang, 0, 5) }}
                                                        @else
                                                            Libur
                                                        @endif
                                                    </td>
                                                    <td><span class="badge badge-{{ $warna }}">{{ $label }}</span></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <form method="POST" action="{{ route('operasional.jadwal-kerja.template-jadwal.apply', $template->id) }}" class="card-footer flex-col items-start gap-4">
                    @csrf
                    <input type="hidden" name="bulan" value="{{ $pratinjau['bulan'] }}">
                    @foreach ($idKaryawanTerpilih as $idKaryawan)
                        <input type="hidden" name="id_karyawan[]" value="{{ $idKaryawan }}">
                    @endforeach

                    <fieldset class="space-y-2 text-sm text-gray-700">
                        <legend class="font-semibold text-gray-800">3. Jadwal yang sudah ada ({{ $jumlahKonflik }} tanggal sudah berjadwal)</legend>
                        <label class="flex items-start gap-2">
                            <input type="radio" name="mode_konflik" value="Lewati" class="mt-1" required @checked(old('mode_konflik', $jumlahKonflik === 0 ? 'Lewati' : null) === 'Lewati')>
                            <span><strong>Pertahankan jadwal lama</strong> &mdash; tanggal yang sudah berjadwal tidak diubah.</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="radio" name="mode_konflik" value="Timpa" class="mt-1" required @checked(old('mode_konflik') === 'Timpa')>
                            <span><strong>Ganti jadwal lama</strong> &mdash; tanggal yang sudah berjadwal diganti dengan pola ini (jadwal lama tetap tercatat di riwayat).</span>
                        </label>
                        @error('mode_konflik') <p class="form-error">{{ $message }}</p> @enderror
                    </fieldset>

                    @unless ($semuaLayak)
                        <p class="form-error">Hapus centang karyawan yang tidak memenuhi syarat, lalu tampilkan pratinjau kembali.</p>
                    @endunless
                    <button type="submit" class="btn-primary" @disabled(! $semuaLayak)>Konfirmasi &amp; Gunakan Pola</button>
                </form>
            </div>
        @endif
    </div>
@endsection

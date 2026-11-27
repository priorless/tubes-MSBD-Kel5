@extends('layouts.tenaga-kerja')

@section('title', 'Izin / Sakit / Cuti')

@use('App\Http\Requests\TenagaKerja\StorePengajuanKetidakhadiranRequest', 'Aturan')

@php
    $tanggal = static fn (string $tgl): string => \Carbon\Carbon::parse($tgl)->locale('id')->translatedFormat('D, d/m/Y');
    $warna = ['Menunggu' => 'badge-warning', 'Disetujui' => 'badge-success', 'Ditolak' => 'badge-danger', 'Dibatalkan' => 'badge-secondary'];
@endphp

@section('content')
<div class="space-y-6">
    <section class="card p-6">
        <h2 class="card-title text-lg font-bold text-gray-900">Ajukan izin, sakit, atau cuti</h2>
        <p class="mt-1 text-sm text-gray-500">
            Pengajuan diputuskan HRD. Bila disetujui, hari kerja dalam rentang tanggal tercatat di absensi Anda (hari libur di jadwal dilewati).
            Cuti dan sakit tidak memotong gaji; izin dihitung tidak masuk.
        </p>

        <form method="POST" action="{{ route('tenaga-kerja.ketidakhadiran.store') }}" enctype="multipart/form-data" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
            @csrf
            <div>
                <label for="jenis" class="form-label">Jenis</label>
                <select id="jenis" name="jenis" class="form-input" required>
                    <option value="">Pilih&hellip;</option>
                    @foreach ($daftarJenis as $jenis)
                        <option value="{{ $jenis }}" @selected(old('jenis') === $jenis)>{{ $jenis }}</option>
                    @endforeach
                </select>
                @error('jenis') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="tgl_mulai" class="form-label">Tanggal mulai</label>
                <input id="tgl_mulai" name="tgl_mulai" type="date" class="form-input" required value="{{ old('tgl_mulai') }}"
                       min="{{ now()->subDays(Aturan::MUNDUR_HARI)->toDateString() }}" max="{{ now()->addDays(Aturan::MAJU_HARI)->toDateString() }}">
                @error('tgl_mulai') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="tgl_selesai" class="form-label">Tanggal selesai</label>
                <input id="tgl_selesai" name="tgl_selesai" type="date" class="form-input" required value="{{ old('tgl_selesai') }}">
                @error('tgl_selesai') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="md:col-span-2">
                <label for="alasan" class="form-label">Alasan</label>
                <input id="alasan" name="alasan" type="text" maxlength="500" class="form-input" required value="{{ old('alasan') }}"
                       placeholder="Contoh: demam, ada surat dokter">
                @error('alasan') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="file_bukti" class="form-label">Bukti (opsional)</label>
                <input id="file_bukti" name="file_bukti" type="file" accept="application/pdf,image/png,image/jpeg" class="form-input">
                <p class="form-hint">Surat dokter atau dokumen pendukung. PDF/JPG/PNG, maks. 4 MB.</p>
                @error('file_bukti') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="md:col-span-3">
                <p class="mb-3 text-xs text-gray-500">
                    Tanggal mulai paling lambat {{ Aturan::MUNDUR_HARI }} hari lalu dan paling jauh {{ Aturan::MAJU_HARI }} hari ke depan;
                    satu pengajuan maksimal {{ Aturan::MAKS_HARI }} hari.
                </p>
                <button type="submit" class="btn-primary">Kirim pengajuan</button>
            </div>
        </form>
    </section>

    <section class="card p-6">
        <h2 class="card-title text-lg font-bold text-gray-900">Riwayat pengajuan</h2>
        @error('pengajuan') <p class="form-error mt-2">{{ $message }}</p> @enderror

        @if ($pengajuan->isEmpty())
            <x-ui.empty-state title="Belum ada pengajuan." message="Pengajuan izin, sakit, atau cuti Anda akan tampil di sini." />
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Jenis</th><th>Tanggal</th><th>Alasan</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($pengajuan as $baris)
                            <tr class="align-top">
                                <td class="font-medium">{{ $baris->jenis }}</td>
                                <td class="whitespace-nowrap">
                                    {{ $tanggal($baris->tgl_mulai) }}
                                    @if ($baris->tgl_selesai !== $baris->tgl_mulai)
                                        &ndash; {{ $tanggal($baris->tgl_selesai) }}
                                    @endif
                                </td>
                                <td class="max-w-sm">
                                    {{ $baris->alasan }}
                                    @if ($baris->file_bukti)
                                        <a href="{{ route('ketidakhadiran.bukti', $baris->id) }}" target="_blank" rel="noopener" class="block text-xs font-medium text-sji-biru hover:underline">Lihat bukti</a>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $warna[$baris->status] ?? 'badge-secondary' }}">{{ $baris->status }}</span>
                                    @if ($baris->catatan_keputusan)
                                        <p class="mt-1 text-xs text-gray-500">{{ $baris->catatan_keputusan }}</p>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if ($baris->status === 'Menunggu')
                                        <form method="POST" action="{{ route('tenaga-kerja.ketidakhadiran.batalkan', $baris->id) }}" onsubmit="return confirm('Batalkan pengajuan ini?')">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn-outline-danger btn-sm">Batalkan</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection

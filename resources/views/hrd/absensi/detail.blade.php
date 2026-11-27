@extends('layouts.app')

@section('title', 'Detail Absensi')

@use('App\Support\FormatKolom')

@section('content')
    <x-ui.page-header title="Detail Absensi" description="Pemeriksaan catatan absensi dan bukti foto karyawan.">
        <a href="{{ route('hrd.absensi', request()->query()) }}" class="btn-secondary">Kembali ke Absensi Harian</a>
    </x-ui.page-header>

    @if (session('success'))
        <div class="alert alert-success mb-6" role="status">{{ session('success') }}</div>
    @endif

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">{{ $baris->nama }}</h2>
                <p class="text-sm text-gray-500">{{ $baris->nip }} &middot; {{ $baris->nama_layanan ?? 'Internal' }}</p>
            </div>
            <span class="badge badge-{{ ['Hadir' => 'success', 'Terlambat' => 'warning', 'Izin' => 'info', 'Sakit' => 'info', 'Cuti' => 'info', 'Alpha' => 'danger'][$baris->status_kehadiran] ?? 'secondary' }}">{{ $baris->status_kehadiran }}</span>
        </div>

        <div class="card-body space-y-6">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-xs font-medium uppercase text-gray-500">Tanggal</dt><dd class="mt-1">{{ FormatKolom::tanggal($baris->tgl, 'l, d F Y') }}</dd></div>
                <div><dt class="text-xs font-medium uppercase text-gray-500">Lokasi penempatan</dt><dd class="mt-1">{{ $baris->nama_klien ?? '—' }}{{ $baris->lokasi_kerja ? ' · '.$baris->lokasi_kerja : '' }}</dd></div>
                <div><dt class="text-xs font-medium uppercase text-gray-500">Jadwal</dt><dd class="mt-1 font-mono">{{ FormatKolom::jam($baris->jadwal_datang) }}–{{ FormatKolom::jam($baris->jadwal_pulang) }}</dd></div>
                <div><dt class="text-xs font-medium uppercase text-gray-500">Jam masuk</dt><dd class="mt-1 font-mono">{{ FormatKolom::jam($baris->jam_masuk) }}</dd></div>
                <div><dt class="text-xs font-medium uppercase text-gray-500">Jam checkout</dt><dd class="mt-1 font-mono">{{ FormatKolom::jam($baris->jam_keluar) }}</dd></div>
            </dl>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                @foreach (['masuk' => 'Check-in', 'keluar' => 'Checkout'] as $jenis => $label)
                    <section>
                        <h3 class="mb-2 text-sm font-semibold text-gray-700">Foto {{ $label }}</h3>
                        @if ($baris->{'foto_'.$jenis})
                            @php $urlFoto = route('absensi.foto', ['absensi' => $baris->id_absensi, 'jenis' => $jenis]); @endphp
                            <a href="{{ $urlFoto }}" target="_blank" rel="noopener" class="inline-block" title="Klik untuk memperbesar foto {{ $label }}">
                                <img src="{{ $urlFoto }}" alt="Foto {{ $label }} {{ $baris->nama }} {{ FormatKolom::tanggal($baris->tgl, 'd/m/Y') }}" loading="lazy" class="max-h-80 max-w-full cursor-zoom-in rounded-lg border border-gray-200 object-contain">
                            </a>
                            <p class="mt-1 text-xs text-gray-500">Klik foto untuk membuka ukuran penuh.</p>
                        @else
                            <p class="text-sm text-gray-400">Foto {{ strtolower($label) }} tidak tersedia.</p>
                        @endif
                    </section>
                @endforeach
            </div>

            @if (filled($baris->jam_keluar))
                <section class="rounded-lg border border-gray-200 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-800">Jam lembur hasil otomatis atau koreksi terakhir</h3>
                            <p class="text-sm text-gray-500">{{ (int) $baris->jam_lembur }} jam penuh</p>
                        </div>
                        @if ($payrollDibuat)
                            <span class="text-sm text-gray-500">Payroll periode ini sudah dibuat; koreksi ditutup.</span>
                        @else
                            <details @if ($errors->has('jam_lembur')) open @endif>
                                <summary class="btn-secondary btn-sm cursor-pointer">Edit Jam Lembur</summary>
                                <form method="POST" action="{{ route('hrd.absensi.jam-lembur', ['absensi' => $baris->id_absensi] + request()->query()) }}" class="mt-3 max-w-sm space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="id_absensi_lembur" value="{{ $baris->id_absensi }}">
                                    <label for="jam-lembur" class="form-label">Jumlah jam lembur (bilangan bulat)</label>
                                    <input id="jam-lembur" type="number" name="jam_lembur" min="0" step="1" inputmode="numeric" required value="{{ old('jam_lembur', (int) $baris->jam_lembur) }}" class="form-input w-full">
                                    @error('jam_lembur') <p class="form-error">{{ $message }}</p> @enderror
                                    <div class="flex gap-2">
                                        <button type="submit" class="btn-primary btn-sm">Simpan</button>
                                        <button type="button" class="btn-secondary btn-sm" onclick="this.form.reset(); this.closest('details').open = false;">Batal</button>
                                    </div>
                                </form>
                            </details>
                        @endif
                    </div>
                </section>
            @else
                <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">Jam lembur baru dapat diperiksa setelah checkout tercatat.</p>
            @endif
        </div>
    </div>
@endsection
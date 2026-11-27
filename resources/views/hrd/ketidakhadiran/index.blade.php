@extends('layouts.app')

@section('title', 'Pengajuan Izin/Cuti')

@php
    $tanggal = static fn (string $tgl): string => \Carbon\Carbon::parse($tgl)->format('d/m/Y');
    $warna = ['Disetujui' => 'badge-success', 'Ditolak' => 'badge-danger', 'Dibatalkan' => 'badge-secondary'];
@endphp

@section('content')
    <x-ui.page-header title="Pengajuan Izin / Sakit / Cuti"
        description="Pengajuan dari karyawan. Jika disetujui, setiap hari kerja dalam rentang tanggal dicatat di absensi (hari Libur di roster dilewati). Cuti dan Sakit tidak memotong gaji, Izin dihitung tidak masuk." />

    <form method="GET" action="{{ route('hrd.ketidakhadiran.index') }}" class="card card-body mb-6 grid grid-cols-1 items-end gap-4 md:grid-cols-[1fr_2fr_auto]">
        <div>
            <label for="status" class="form-label">Status</label>
            <select id="status" name="status" class="form-input">
                @foreach ($daftarStatus as $pilihan)
                    <option value="{{ $pilihan }}" @selected($status === $pilihan)>{{ $pilihan }}{{ $pilihan === 'Menunggu' ? ' ('.$jumlahMenunggu.')' : '' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="q" class="form-label">Cari karyawan</label>
            <input id="q" name="q" type="search" class="form-input" value="{{ $q }}" placeholder="Nama atau NIP...">
        </div>
        <button type="submit" class="btn-primary">Tampilkan</button>
    </form>

    @error('keputusan') <div class="callout callout-danger mb-6 text-sm">{{ $message }}</div> @enderror
    @error('catatan_keputusan') <div class="callout callout-danger mb-6 text-sm">{{ $message }}</div> @enderror

    @if ($pengajuan->isEmpty())
        <x-ui.empty-state title="Tidak ada pengajuan {{ strtolower($status) }}" message="Pengajuan izin, sakit, dan cuti dari karyawan akan tampil di sini." />
    @else
        <section class="card">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Karyawan</th>
                            <th>Jenis</th>
                            <th>Tanggal</th>
                            <th>Alasan</th>
                            <th>{{ $status === 'Menunggu' ? 'Keputusan' : 'Diproses' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pengajuan as $baris)
                            <tr class="align-top">
                                <td>
                                    <p class="font-medium">{{ $baris->nama }}</p>
                                    <p class="text-xs text-gray-400">{{ $baris->nip }}</p>
                                </td>
                                <td class="font-medium">{{ $baris->jenis }}</td>
                                <td class="whitespace-nowrap tabular-nums">
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
                                    @if ($baris->status === 'Menunggu')
                                        <div class="flex flex-col gap-2">
                                            <form method="POST" action="{{ route('hrd.ketidakhadiran.update', $baris->id) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="keputusan" value="Disetujui">
                                                <button type="submit" class="btn-primary btn-sm">Setujui</button>
                                            </form>
                                            <details>
                                                <summary class="btn-outline-danger btn-sm cursor-pointer list-none">Tolak&hellip;</summary>
                                                <form method="POST" action="{{ route('hrd.ketidakhadiran.update', $baris->id) }}" class="mt-2 flex gap-2">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="keputusan" value="Ditolak">
                                                    <input name="catatan_keputusan" type="text" maxlength="500" class="form-input py-1.5" placeholder="Alasan penolakan" required>
                                                    <button type="submit" class="btn-danger btn-sm">Tolak</button>
                                                </form>
                                            </details>
                                        </div>
                                    @else
                                        <span class="badge {{ $warna[$baris->status] ?? 'badge-secondary' }}">{{ $baris->status }}</span>
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ $baris->nama_pemroses ?? ($baris->status === 'Dibatalkan' ? 'Dibatalkan karyawan' : '') }}
                                            &middot; {{ \Carbon\Carbon::parse($baris->diproses_pada)->format('d/m/Y H:i') }}
                                        </p>
                                        @if ($baris->catatan_keputusan)
                                            <p class="text-xs text-gray-500">{{ $baris->catatan_keputusan }}</p>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-4">{{ $pengajuan->links() }}</div>
    @endif
@endsection

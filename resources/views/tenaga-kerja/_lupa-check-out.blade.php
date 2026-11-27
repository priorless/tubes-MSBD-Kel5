{{--
    Bagian "Lupa check-out" di halaman Absensi: absensi hari sebelumnya yang sudah check-in tetapi belum check-out
    diajukan jam keluarnya untuk disetujui HRD. Tampil hanya bila ada yang perlu diajukan atau ada pengajuan terbaru.
--}}
@use('App\Http\Controllers\TenagaKerja\KoreksiCheckOutController')

@php
    $tanggalKoreksi = static fn (string $tgl): string => \Carbon\Carbon::parse($tgl)->locale('id')->translatedFormat('l, d/m/Y');
    $warnaKoreksi = ['Menunggu' => 'badge-warning', 'Disetujui' => 'badge-success', 'Ditolak' => 'badge-danger'];
@endphp

@if ($lupaCheckOut->isNotEmpty() || $pengajuanKoreksi->isNotEmpty())
    <section class="tk-reveal card p-6" id="lupa-check-out">
        <h2 class="card-title text-lg font-bold text-gray-900">Lupa check-out</h2>

        @if ($lupaCheckOut->isNotEmpty())
            <p class="mt-1 text-sm text-gray-500">
                Absensi berikut sudah check-in tetapi belum check-out. Ajukan jam keluar yang sebenarnya untuk disetujui HRD.
                Jam keluar minimal 30 menit setelah check-in dan paling lambat 4 jam setelah jadwal pulang (16 jam setelah check-in bila tidak ada jadwal).
            </p>

            @error('id_absensi') <p class="form-error mt-3">{{ $message }}</p> @enderror

            <div class="mt-4 space-y-4">
                @foreach ($lupaCheckOut as $absensiLupa)
                    @php $formIni = (int) old('id_absensi') === (int) $absensiLupa->id; @endphp
                    <form method="POST" action="{{ route('tenaga-kerja.koreksi-check-out.store') }}" class="rounded-lg border border-amber-200 bg-amber-50/40 p-4">
                        @csrf
                        <input type="hidden" name="id_absensi" value="{{ $absensiLupa->id }}">
                        <p class="font-medium text-gray-800">{{ $tanggalKoreksi($absensiLupa->tgl) }}</p>
                        <p class="text-sm text-gray-500">Check-in {{ substr($absensiLupa->jam_masuk, 0, 5) }} &middot; belum check-out</p>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[10rem_1fr_auto] sm:items-end">
                            <div>
                                <label for="jam_keluar_{{ $absensiLupa->id }}" class="form-label">Jam keluar</label>
                                <input id="jam_keluar_{{ $absensiLupa->id }}" name="jam_keluar" type="time" class="form-input" required value="{{ $formIni ? old('jam_keluar') : '' }}">
                            </div>
                            <div>
                                <label for="alasan_{{ $absensiLupa->id }}" class="form-label">Alasan</label>
                                <input id="alasan_{{ $absensiLupa->id }}" name="alasan" type="text" maxlength="500" class="form-input" required value="{{ $formIni ? old('alasan') : '' }}" placeholder="Contoh: baterai HP habis saat pulang">
                            </div>
                            <button type="submit" class="btn-primary">Ajukan</button>
                        </div>
                        @if ($formIni)
                            @error('jam_keluar') <p class="form-error">{{ $message }}</p> @enderror
                            @error('alasan') <p class="form-error">{{ $message }}</p> @enderror
                        @endif
                    </form>
                @endforeach
            </div>
        @endif

        @if ($pengajuanKoreksi->isNotEmpty())
            <h3 class="mt-6 text-sm font-semibold text-gray-700">Pengajuan koreksi {{ KoreksiCheckOutController::BATAS_HARI }} hari terakhir</h3>
            <div class="mt-2 overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Tanggal absensi</th><th>Jam</th><th>Alasan</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($pengajuanKoreksi as $baris)
                            <tr>
                                <td class="whitespace-nowrap">{{ $tanggalKoreksi($baris->tgl) }}</td>
                                <td class="whitespace-nowrap tabular-nums">{{ substr($baris->jam_masuk, 0, 5) }} &ndash; {{ substr($baris->jam_keluar, 0, 5) }}</td>
                                <td>{{ $baris->alasan }}</td>
                                <td>
                                    <span class="badge {{ $warnaKoreksi[$baris->status] ?? 'badge-secondary' }}">{{ $baris->status }}</span>
                                    @if ($baris->catatan_keputusan)
                                        <p class="mt-1 text-xs text-gray-500">{{ $baris->catatan_keputusan }}</p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif

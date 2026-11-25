{{--
    Catatan perjalanan Driver: keterangan + foto kapan saja (tanpa tahapan dan tanpa kewajiban per jam) + ringkasan P2H.
    Check-out tidak bergantung pada catatan perjalanan.
    Variabel: $monitoring, $status, $maksUpload, $hariIni.
--}}
@use('App\Services\LaporanAktivitasService')

@php
    $p2h = $monitoring['p2h'];
    $warnaKondisi = ['Baik' => 'badge-success', 'Perlu Perhatian' => 'badge-warning', 'Rusak' => 'badge-danger'];
@endphp

<section class="tk-reveal card p-6 xl:col-span-12" id="monitoring">
    <div class="mb-4">
        <h2 class="card-title">Laporan Perjalanan</h2>
        <p class="mt-1 text-sm text-gray-500">
            Tulis catatan aktivitas perjalanan kapan saja diperlukan. Tidak ada tahapan wajib maupun laporan per jam,
            dan check-out tidak bergantung pada catatan ini. Waktu laporan dicatat otomatis oleh server.
        </p>
    </div>

    @if ($status === 'belum')
        <x-ui.empty-state title="Laporan perjalanan dibuka setelah check-in." message="Lengkapi P2H kendaraan lalu lakukan check-in terlebih dahulu." />
    @else
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
            <div class="xl:col-span-3">
                @if ($status === 'masuk')
                    <form method="POST" action="{{ route('tenaga-kerja.laporan.store') }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-4 rounded-md bg-gray-50 p-4 md:grid-cols-2">
                        @csrf
                        <div>
                            <label for="tkKeteranganPerjalanan" class="form-label">Keterangan / catatan <span class="text-red-600">*</span></label>
                            <textarea id="tkKeteranganPerjalanan" name="keterangan" rows="3" minlength="3" maxlength="500" required class="form-input"
                                      placeholder="mis. Berangkat dari kantor menuju PT ABC, Jl. Sudirman">{{ old('keterangan') }}</textarea>
                        </div>
                        @include('tenaga-kerja._input-foto', ['nama' => 'foto', 'id' => 'tkFotoPerjalanan', 'label' => 'Foto bukti aktivitas'])
                        <div class="flex justify-end md:col-span-2">
                            <button type="submit" class="btn-primary">Kirim Laporan</button>
                        </div>
                    </form>
                @else
                    <p class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600">Anda sudah check-out. Laporan perjalanan sesi ini sudah ditutup.</p>
                @endif

                <h3 class="mb-2 mt-6 text-sm font-semibold text-gray-700">Catatan Perjalanan Hari Ini</h3>
                @if ($monitoring['laporan']->isEmpty())
                    <p class="text-sm text-gray-400">Belum ada laporan perjalanan.</p>
                @else
                    <ol class="space-y-3 border-l-2 border-sji-biru/30 pl-4">
                        @foreach ($monitoring['laporan'] as $laporan)
                            @php $sisa = $maksUpload - (int) $laporan->jumlah_upload; @endphp
                            <li class="relative rounded-md border border-gray-200 bg-white p-3">
                                <span class="absolute top-4 -left-[1.4rem] size-3 rounded-full bg-sji-biru"></span>
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <p class="font-semibold text-gray-800">Catatan {{ $loop->iteration }}@if (filled($laporan->tahapan)) <span class="font-normal text-gray-500">&middot; {{ $laporan->tahapan }}</span>@endif</p>
                                </div>
                                @include('tenaga-kerja._foto-laporan', ['laporan' => $laporan])
                                @if ($status === 'masuk' && $sisa > 0)
                                    @include('tenaga-kerja._form-koreksi', ['laporan' => $laporan, 'judulFoto' => 'Foto bukti aktivitas'])
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>

            <div class="xl:col-span-2">
                <h3 class="mb-2 text-sm font-semibold text-gray-700">P2H Kendaraan</h3>
                @if (! $p2h)
                    <p class="text-sm text-gray-400">Tidak ada data P2H untuk sesi ini.</p>
                @else
                    <div class="mb-3 flex flex-wrap gap-3">
                        @foreach (['awal' => 'Sebelum digunakan', 'akhir' => 'Setelah digunakan'] as $jenis => $labelFoto)
                            @if (filled($p2h['p2h']->{'foto_kendaraan_'.$jenis}))
                                @php $urlFoto = route('absensi.p2h.foto', ['absensi' => $p2h['p2h']->id_absensi, 'jenis' => $jenis]); @endphp
                                <a href="{{ $urlFoto }}" target="_blank" rel="noopener" data-no-transisi class="block text-center">
                                    <img src="{{ $urlFoto }}" alt="Foto kendaraan {{ strtolower($labelFoto) }}" loading="lazy" class="h-16 w-24 rounded-md border border-gray-200 object-cover">
                                    <span class="text-xs text-gray-500">{{ $labelFoto }}</span>
                                </a>
                            @endif
                        @endforeach
                    </div>
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach (LaporanAktivitasService::KOMPONEN_P2H as $kunci => $label)
                            @php $item = $p2h['item']->get($kunci); @endphp
                            <li class="flex items-start justify-between gap-2 py-1.5">
                                <span class="text-gray-700">{{ $label }}
                                    @if ($item?->keterangan)
                                        <span class="block text-xs text-gray-500">{{ $item->keterangan }}</span>
                                    @endif
                                </span>
                                <span class="badge {{ $warnaKondisi[$item?->kondisi] ?? 'badge-secondary' }}">{{ $item?->kondisi ?? '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($p2h['p2h']->kondisi_akhir)
                        <p class="mt-3 text-sm text-gray-700">Kondisi akhir:
                            <span class="badge {{ $warnaKondisi[$p2h['p2h']->kondisi_akhir] ?? 'badge-secondary' }}">{{ $p2h['p2h']->kondisi_akhir }}</span>
                            @if ($p2h['p2h']->catatan_akhir)
                                <span class="block text-xs text-gray-500">{{ $p2h['p2h']->catatan_akhir }}</span>
                            @endif
                        </p>
                    @endif
                @endif
            </div>
        </div>
    @endif
</section>

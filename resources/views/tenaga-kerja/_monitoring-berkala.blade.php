{{--
    Monitoring Satpam & Cleaning Service: laporan foto + keterangan yang dapat dikirim kapan saja selama sesi,
    tanpa batasan interval waktu. Variabel: $monitoring, $status, $layanan, $maksUpload.
--}}
@php
    $cleaning = str_contains(mb_strtolower((string) $layanan), 'cleaning');
    $judulFoto = $cleaning ? 'Foto aktivitas & hasil pembersihan' : 'Foto patroli / kondisi lingkungan';
    $contohKeterangan = $cleaning
        ? 'Lantai/area yang dibersihkan, mis. Lantai 2 – toilet & koridor sudah bersih'
        : 'Area patroli & kondisi, mis. Pos depan aman, pagar belakang terkunci';
@endphp

<section class="tk-reveal card p-6 xl:col-span-12" id="monitoring">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="card-title">Monitoring Aktivitas</h2>
            <p class="mt-1 text-sm text-gray-500">
                {{ $cleaning ? 'Unggah foto aktivitas dan hasil pembersihan setiap lantai/area' : 'Unggah foto patroli atau kondisi lingkungan' }}
                kapan saja selama shift, sebanyak yang diperlukan. Waktu laporan dicatat otomatis oleh server.
            </p>
        </div>
        @if ($monitoring['shift'])
            <span class="badge">Shift {{ $monitoring['shift']->label() }}</span>
        @endif
    </div>

    @if ($status === 'belum')
        <x-ui.empty-state title="Monitoring dimulai setelah check-in." message="Setelah check-in, Anda dapat mengirim laporan kapan saja selama shift." />
    @else
        @if ($status === 'masuk')
            <form method="POST" action="{{ route('tenaga-kerja.laporan.store') }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-4 rounded-md bg-gray-50 p-4 md:grid-cols-2">
                @csrf
                @include('tenaga-kerja._input-foto', ['nama' => 'foto', 'id' => 'tkFotoMonitoring', 'label' => $judulFoto])
                <div>
                    <label for="tkKeteranganMonitoring" class="form-label">Keterangan <span class="text-red-600">*</span></label>
                    <textarea id="tkKeteranganMonitoring" name="keterangan" rows="3" minlength="3" maxlength="500" required
                              class="form-input" placeholder="{{ $contohKeterangan }}">{{ old('keterangan') }}</textarea>
                </div>
                <div class="flex justify-end md:col-span-2">
                    <button type="submit" class="btn-primary">Kirim Laporan</button>
                </div>
            </form>
        @else
            <p class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600">Anda sudah check-out. Monitoring untuk sesi ini sudah berhenti dan tidak menerima unggahan baru.</p>
        @endif

        <h3 class="mb-2 mt-6 text-sm font-semibold text-gray-700">Laporan Sesi Ini</h3>
        @if ($monitoring['laporan']->isEmpty())
            <p class="text-sm text-gray-400">Belum ada laporan monitoring.</p>
        @else
            <ol class="space-y-3">
                @foreach ($monitoring['laporan'] as $laporan)
                    @php $sisa = $maksUpload - (int) $laporan->jumlah_upload; @endphp
                    <li class="rounded-md border border-gray-200 bg-white p-3">
                        <p class="mb-2 font-semibold text-gray-800">Laporan {{ $laporan->periode_ke ?? $loop->iteration }}</p>
                        @include('tenaga-kerja._foto-laporan', ['laporan' => $laporan])
                        @if ($status === 'masuk' && $sisa > 0)
                            @include('tenaga-kerja._form-koreksi', ['laporan' => $laporan, 'judulFoto' => $judulFoto])
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    @endif
</section>

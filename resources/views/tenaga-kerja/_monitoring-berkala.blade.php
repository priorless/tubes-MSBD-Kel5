{{--
    Monitoring per jam Satpam & Cleaning Service: satu laporan foto per periode 1 jam dari JAM MULAI SHIFT.
    Periode ditentukan server; formulir hanya tampil pada periode yang sedang dibuka.
    Variabel: $monitoring, $status, $layanan, $maksUpload.
--}}
@use('App\Services\JadwalAbsensiService')

@php
    $cleaning = str_contains(mb_strtolower((string) $layanan), 'cleaning');
    $judulFoto = $cleaning ? 'Foto aktivitas & hasil pembersihan' : 'Foto patroli / kondisi lingkungan';
    $contohKeterangan = $cleaning
        ? 'Lantai/area yang dibersihkan, mis. Lantai 2 – toilet & koridor sudah bersih'
        : 'Area patroli & kondisi, mis. Pos depan aman, pagar belakang terkunci';
    $kelasStatus = [
        JadwalAbsensiService::PERIODE_DIBUKA => 'badge-success',
        JadwalAbsensiService::PERIODE_DITUTUP => 'badge-secondary',
        JadwalAbsensiService::PERIODE_AKAN_DATANG => 'badge-info',
    ];
    $intervalMenit = (int) config('tenaga_kerja.absensi.interval_menit', 60);
@endphp

<section class="tk-reveal card p-6 xl:col-span-12" id="monitoring"
         @if ($monitoring['muatUlangPada'] && $status === 'masuk') data-tk-muat-ulang-pada="{{ $monitoring['muatUlangPada'] }}" @endif>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="card-title">Monitoring Aktivitas per Jam</h2>
            <p class="mt-1 text-sm text-gray-500">
                {{ $cleaning ? 'Unggah foto aktivitas dan hasil pembersihan setiap lantai/area' : 'Unggah foto patroli atau kondisi lingkungan' }}
                satu kali di setiap periode. Periode dihitung tiap {{ $intervalMenit }} menit dari jam mulai shift; Anda bebas mengunggah kapan saja selama periode masih dibuka.
                Setiap periode maksimal {{ $maksUpload }} kali unggah (1 utama + 1 koreksi).
            </p>
        </div>
        @if ($monitoring['shift'])
            <span class="badge">Shift {{ $monitoring['shift']->label() }}</span>
        @endif
    </div>

    @if ($status === 'belum')
        <x-ui.empty-state title="Monitoring dimulai setelah check-in."
            :message="$monitoring['shift'] ? 'Laporan 1 dibuka pukul '.$monitoring['shift']->mulai->addMinutes($intervalMenit)->format('H:i').', lalu setiap '.$intervalMenit.' menit berikutnya.' : 'Periode laporan mengikuti jadwal shift Anda.'" />
    @elseif ($monitoring['periode'] === [])
        <x-ui.empty-state title="Belum ada periode laporan." message="Periode laporan mengikuti jadwal shift sesi absensi ini." />
    @else
        @if ($status === 'selesai')
            <p class="mb-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600">Anda sudah check-out. Monitoring untuk sesi ini sudah berhenti dan tidak menerima unggahan baru.</p>
        @endif

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Laporan</th>
                        <th>Periode</th>
                        <th>Status</th>
                        <th>Foto &amp; Keterangan</th>
                        <th>Sisa Unggah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($monitoring['periode'] as $periode)
                        @php
                            $laporan = $periode['laporan'];
                            $sisa = $maksUpload - (int) ($laporan->jumlah_upload ?? 0);
                            $dibuka = $periode['status'] === JadwalAbsensiService::PERIODE_DIBUKA && $status === 'masuk';
                        @endphp
                        <tr @class(['bg-emerald-50/50' => $dibuka])>
                            <td class="whitespace-nowrap font-semibold text-gray-800">Laporan {{ $periode['ke'] }}</td>
                            <td class="whitespace-nowrap font-mono text-sm">
                                {{ $periode['mulai']->format('H:i') }}&ndash;{{ $periode['selesai']->subMinute()->format('H:i') }}
                                <p class="font-sans text-xs text-gray-400">Dibuka {{ $periode['mulai']->format('H:i') }}, ditutup {{ $periode['selesai']->format('H:i') }}</p>
                            </td>
                            <td>
                                <span class="badge {{ $kelasStatus[$periode['status']] }}">{{ $periode['status'] }}</span>
                                @if (! $laporan && $periode['status'] === JadwalAbsensiService::PERIODE_DITUTUP)
                                    <p class="mt-1 text-xs text-red-600">Tidak ada laporan</p>
                                @endif
                            </td>
                            <td class="min-w-64">
                                @if ($laporan)
                                    @include('tenaga-kerja._foto-laporan', ['laporan' => $laporan])
                                @else
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @endif

                                @if ($dibuka && ! $laporan)
                                    <form method="POST" action="{{ route('tenaga-kerja.laporan.store') }}" enctype="multipart/form-data" class="mt-2 space-y-2 rounded-md bg-gray-50 p-3">
                                        @csrf
                                        @include('tenaga-kerja._input-foto', ['nama' => 'foto', 'id' => 'tkFotoPeriode'.$periode['ke'], 'label' => $judulFoto])
                                        <div>
                                            <label for="tkKeteranganPeriode{{ $periode['ke'] }}" class="form-label">Keterangan <span class="text-red-600">*</span></label>
                                            <textarea id="tkKeteranganPeriode{{ $periode['ke'] }}" name="keterangan" rows="2" minlength="3" maxlength="500" required
                                                      class="form-input" placeholder="{{ $contohKeterangan }}">{{ old('keterangan') }}</textarea>
                                        </div>
                                        <button type="submit" class="btn-primary">Kirim Laporan {{ $periode['ke'] }}</button>
                                    </form>
                                @elseif ($dibuka && $sisa > 0)
                                    @include('tenaga-kerja._form-koreksi', ['laporan' => $laporan, 'judulFoto' => $judulFoto])
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-sm">
                                @if ($periode['status'] === JadwalAbsensiService::PERIODE_AKAN_DATANG)
                                    <span class="text-gray-400">{{ $maksUpload }}&times;</span>
                                @elseif ($dibuka)
                                    <span @class(['font-semibold', 'text-emerald-700' => $sisa > 0, 'text-gray-500' => $sisa <= 0])>{{ max(0, $sisa) }} dari {{ $maksUpload }}</span>
                                @else
                                    <span class="text-gray-400">Ditutup</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($status === 'masuk')
            <p class="mt-3 text-xs text-gray-500">Halaman diperbarui otomatis saat periode berikutnya dibuka atau periode berjalan ditutup.</p>
        @endif
    @endif
</section>

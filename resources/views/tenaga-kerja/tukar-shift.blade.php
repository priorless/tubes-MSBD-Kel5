@extends('layouts.tenaga-kerja')

@section('title', 'Tukar Shift')

@use('App\Services\PertukaranShiftService')

@php
    $idSaya = \App\Support\KaryawanAktif::id();
    $hariId = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    $labelTanggal = static fn (string $tgl): string => $hariId[\Carbon\CarbonImmutable::parse($tgl)->dayOfWeek].', '.\Carbon\CarbonImmutable::parse($tgl)->format('d/m/Y');
@endphp

@section('content')
<div class="space-y-6">
    @if (! $tersedia)
        <section class="tk-reveal card p-6">
            <x-ui.empty-state title="Fitur tukar shift belum tersedia."
                message="Hubungi bagian Operasional." />
        </section>
    @else
        {{-- ===================== Form pengajuan ===================== --}}
        <section class="tk-reveal card p-6" id="tukar-shift">
            <h2 class="card-title text-lg font-bold text-gray-900">Ajukan Tukar Shift dengan Rekan</h2>
            <p class="mt-1.5 text-sm text-gray-500">
                Tukar salah satu jadwal Anda dengan jadwal rekan <b>satu jenis layanan</b> di lokasi yang sama; tanggal dan jam shift ikut bertukar.
                Ajukan paling lambat H-1; rekan menerima atau menolak, lalu Operasional memberi persetujuan akhir. Jadwal baru (beserta hak absensinya)
                berlaku hanya setelah disetujui Operasional.
            </p>

            <div class="mt-4">
                @if ($opsi === [])
                    <x-ui.empty-state title="Belum ada jadwal yang dapat ditukar."
                        message="Tukar shift tersedia untuk jadwal mulai besok yang sudah dijadwalkan Operasional dan memiliki rekan satu jenis layanan di lokasi yang sama yang jadwalnya dapat ditukar." />
                @else
                    <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.store') }}" id="formTukarShift" class="grid grid-cols-1 gap-4 lg:grid-cols-3"
                          onsubmit="return confirm('Kirim pengajuan tukar shift? Rekan Anda akan diminta menerima atau menolak.')">
                        @csrf
                        <div class="rounded-xl bg-gray-50 p-4">
                            <label for="id_jadwal_pengaju" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">1</span> Pilih jadwal Anda</label>
                            <select id="id_jadwal_pengaju" name="id_jadwal_pengaju" class="form-input" required>
                                <option value="">Pilih jadwal</option>
                                @foreach ($opsi as $pilihan)
                                    <option value="{{ $pilihan['id_jadwal'] }}" @selected((string) old('id_jadwal_pengaju') === (string) $pilihan['id_jadwal'])>
                                        {{ $labelTanggal($pilihan['tanggal']) }} &mdash; {{ $pilihan['shift'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="rounded-xl bg-gray-50 p-4">
                            <label for="id_jadwal_rekan" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">2</span> Pilih jadwal rekan</label>
                            <select id="id_jadwal_rekan" name="id_jadwal_rekan" class="form-input" required disabled>
                                <option value="">Pilih jadwal Anda terlebih dahulu</option>
                            </select>
                            <p class="form-hint" id="ringkasanTukar">Hanya jadwal rekan satu jenis layanan yang dapat ditukar dengan jadwal Anda yang ditampilkan.</p>
                        </div>

                        <div class="rounded-xl bg-gray-50 p-4">
                            <label for="alasan" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">3</span> Alasan</label>
                            <textarea id="alasan" name="alasan" rows="2" minlength="10" maxlength="500" required class="form-input"
                                      placeholder="Contoh: ada keperluan keluarga pada pagi hari">{{ old('alasan') }}</textarea>
                        </div>

                        <div class="flex justify-end lg:col-span-3">
                            <button type="submit" class="btn-primary">Ajukan Tukar Shift</button>
                        </div>
                    </form>

                    @push('scripts')
                        <script>
                            (() => {
                                const opsi = @js($opsi);
                                const rekanLama = @js((string) old('id_jadwal_rekan', ''));
                                const pilihJadwal = document.getElementById('id_jadwal_pengaju');
                                const pilihRekan = document.getElementById('id_jadwal_rekan');
                                const ringkasan = document.getElementById('ringkasanTukar');
                                const ringkasanAwal = ringkasan.textContent;
                                const hari = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
                                const labelTanggal = (tanggal) => {
                                    const [tahun, bulan, tgl] = tanggal.split('-').map(Number);
                                    return `${hari[new Date(tahun, bulan - 1, tgl).getDay()]}, ${String(tgl).padStart(2, '0')}/${String(bulan).padStart(2, '0')}/${tahun}`;
                                };
                                const jadwalTerpilih = () => opsi.find((item) => String(item.id_jadwal) === pilihJadwal.value);

                                const tampilkanRingkasan = () => {
                                    const saya = jadwalTerpilih();
                                    const rekan = saya?.rekan.find((item) => String(item.id_jadwal) === pilihRekan.value);
                                    ringkasan.textContent = saya && rekan
                                        ? `Anda: ${labelTanggal(saya.tanggal)} ${saya.shift} → ${labelTanggal(rekan.tanggal)} ${rekan.shift}. `
                                            + `${rekan.nama}: ${labelTanggal(rekan.tanggal)} ${rekan.shift} → ${labelTanggal(saya.tanggal)} ${saya.shift}.`
                                        : ringkasanAwal;
                                };
                                const isiRekan = (pilihanAwal = '') => {
                                    const saya = jadwalTerpilih();
                                    pilihRekan.replaceChildren(new Option(saya ? 'Pilih jadwal rekan' : 'Pilih jadwal Anda terlebih dahulu', ''));
                                    pilihRekan.disabled = !saya;
                                    if (saya) {
                                        saya.rekan.forEach((rekan) => {
                                            const pilihan = new Option(`${rekan.nama} (${rekan.nip}) — ${labelTanggal(rekan.tanggal)} · ${rekan.shift}`, rekan.id_jadwal);
                                            pilihan.selected = String(rekan.id_jadwal) === pilihanAwal;
                                            pilihRekan.add(pilihan);
                                        });
                                    }
                                    tampilkanRingkasan();
                                };

                                pilihJadwal.addEventListener('change', () => isiRekan());
                                pilihRekan.addEventListener('change', tampilkanRingkasan);
                                isiRekan(rekanLama);
                            })();
                        </script>
                    @endpush
                @endif
            </div>
        </section>

        {{-- ===================== Status pengajuan ===================== --}}
        <section class="tk-reveal card p-6">
            <h2 class="card-title text-lg font-bold text-gray-900">Status Pengajuan Tukar Shift</h2>

            @if ($riwayat === null || $riwayat->isEmpty())
                <x-ui.empty-state title="Belum ada pengajuan tukar shift."
                    message="Pengajuan yang Anda kirim maupun yang ditujukan kepada Anda akan tampil di sini." />
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Bertukar Dengan</th>
                                <th>Jadwal Anda</th>
                                <th>Menjadi</th>
                                <th>Status</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($riwayat as $item)
                                @php
                                    $sayaPengaju = (int) $item->id_karyawan_pengaju === $idSaya;
                                    $tahap = PertukaranShiftService::labelTahap($item);
                                    $masihBerjalan = $item->status === PertukaranShiftService::STATUS_MENUNGGU && $tahap !== 'Kedaluwarsa';
                                    $tanggalPengaju = (string) $item->tanggal_kerja;
                                    $tanggalRekan = $layananTukar->tanggalKerjaRekan($item);
                                @endphp
                                <tr>
                                    <td>
                                        {{ $sayaPengaju ? $item->nama_rekan : $item->nama_pengaju }}
                                        <p class="text-xs text-gray-400">{{ $sayaPengaju ? 'Anda yang mengajukan' : 'Diajukan oleh rekan' }}</p>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        {{ $labelTanggal($sayaPengaju ? $tanggalPengaju : $tanggalRekan) }}
                                        <p class="text-xs text-gray-500">{{ $layananTukar->labelShift($sayaPengaju ? $item->snapshot_pengaju : $item->snapshot_rekan) }}</p>
                                    </td>
                                    <td class="whitespace-nowrap font-medium text-gray-800">
                                        {{ $labelTanggal($sayaPengaju ? $tanggalRekan : $tanggalPengaju) }}
                                        <p class="text-xs font-normal text-gray-500">{{ $layananTukar->labelShift($sayaPengaju ? $item->snapshot_rekan : $item->snapshot_pengaju) }}</p>
                                    </td>
                                    <td>
                                        <span class="badge {{ $tahap === 'Kedaluwarsa' ? 'badge-secondary' : PertukaranShiftService::kelasBadge($item->status) }}">{{ $tahap }}</span>

                                        @if ($masihBerjalan && ! $sayaPengaju && ($item->status_rekan ?? null) === PertukaranShiftService::REKAN_MENUNGGU)
                                            <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.tanggapi', $item->id) }}" class="mt-2 space-y-2">
                                                @csrf
                                                @method('PATCH')
                                                <input type="text" name="catatan_rekan" maxlength="500" class="form-input text-sm" placeholder="Catatan (opsional)" aria-label="Catatan tanggapan">
                                                <div class="flex gap-2">
                                                    <button type="submit" name="tanggapan" value="terima" class="btn-primary px-3 py-1.5 text-xs"
                                                            onclick="return confirm('Terima pertukaran shift ini? Pengajuan akan diteruskan ke Operasional.')">Terima</button>
                                                    <button type="submit" name="tanggapan" value="tolak" class="btn-danger px-3 py-1.5 text-xs"
                                                            onclick="return confirm('Tolak pertukaran shift ini?')">Tolak</button>
                                                </div>
                                            </form>
                                        @endif

                                        @if ($masihBerjalan && $sayaPengaju)
                                            <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.batalkan', $item->id) }}" class="mt-2"
                                                  onsubmit="return confirm('Batalkan pengajuan tukar shift ini?')">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn-secondary px-3 py-1.5 text-xs">Batalkan</button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="text-sm text-gray-600">{{ $item->catatan_keputusan ?? ($item->catatan_rekan ?? '-') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $riwayat->links() }}</div>
            @endif
        </section>
    @endif
</div>
@endsection

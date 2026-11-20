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
                Tukar shift dengan rekan <b>satu jenis layanan</b> di lokasi yang sama. Tanggal jadwal Anda dan rekan boleh berbeda;
                yang ditukar adalah shift-nya, tanggal kerja masing-masing tetap. Ajukan paling lambat H-1 sebelum kedua tanggal;
                pengajuan langsung diperiksa Operasional untuk disetujui atau ditolak. Jadwal baru (beserta hak absensinya)
                berlaku hanya setelah disetujui Operasional.
            </p>

            <div class="mt-4">
                @if ($opsi === [])
                    <x-ui.empty-state title="Belum ada jadwal yang dapat ditukar."
                        message="Tukar shift tersedia untuk tanggal mulai besok yang sudah dijadwalkan Operasional dan memiliki rekan satu jenis layanan dengan shift berbeda di lokasi yang sama." />
                @else
                    <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.store') }}" id="formTukarShift" class="grid grid-cols-1 gap-4 lg:grid-cols-3"
                          onsubmit="return confirm('Kirim pengajuan tukar shift ke Operasional?')">
                        @csrf
                        <div class="rounded-xl bg-gray-50 p-4">
                            <label for="tanggal_kerja" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">1</span> Pilih jadwal Anda</label>
                            <select id="tanggal_kerja" name="tanggal_kerja" class="form-input" required>
                                <option value="">Pilih tanggal</option>
                                @foreach ($opsi as $pilihan)
                                    <option value="{{ $pilihan['tanggal'] }}" @selected(old('tanggal_kerja') === $pilihan['tanggal'])>
                                        {{ $labelTanggal($pilihan['tanggal']) }} &mdash; {{ $pilihan['shift'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="rounded-xl bg-gray-50 p-4">
                            <label for="id_karyawan_rekan" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">2</span> Pilih rekan</label>
                            <select id="id_karyawan_rekan" name="id_karyawan_rekan" class="form-input" required disabled>
                                <option value="">Pilih jadwal Anda terlebih dahulu</option>
                            </select>
                            <p class="form-hint">Hanya rekan satu jenis layanan di lokasi yang sama yang ditampilkan.</p>
                        </div>

                        <div class="rounded-xl bg-gray-50 p-4">
                            <label for="tanggal_kerja_rekan" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">3</span> Pilih jadwal rekan</label>
                            <select id="tanggal_kerja_rekan" name="tanggal_kerja_rekan" class="form-input" required disabled>
                                <option value="">Pilih rekan terlebih dahulu</option>
                            </select>
                            <p class="form-hint">Tanggal boleh berbeda; hanya shift yang berbeda dari shift Anda yang ditampilkan.</p>
                        </div>

                        <div class="rounded-xl bg-gray-50 p-4 lg:col-span-3">
                            <label for="alasan" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">4</span> Alasan</label>
                            <textarea id="alasan" name="alasan" rows="2" minlength="10" maxlength="500" required class="form-input"
                                      placeholder="Contoh: ada keperluan keluarga pada pagi hari">{{ old('alasan') }}</textarea>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 lg:col-span-3" id="ringkasanTukar">
                            <p class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">5</span> Periksa ringkasan</p>
                            <p class="text-sm text-gray-500" data-ringkasan-kosong>Lengkapi langkah 1–3 untuk melihat ringkasan pertukaran.</p>
                            <div class="hidden grid-cols-1 gap-3 text-sm md:grid-cols-2" data-ringkasan-isi>
                                <div class="rounded-lg bg-gray-50 p-3">
                                    <p class="text-xs text-gray-500">Jadwal Anda</p>
                                    <p class="mt-1 font-semibold text-gray-800" data-ringkasan="tanggal-saya"></p>
                                    <p class="text-gray-600">Shift awal: <span data-ringkasan="shift-saya"></span></p>
                                    <p class="text-gray-600">Menjadi: <span class="font-medium text-sji-biru" data-ringkasan="shift-saya-baru"></span></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3">
                                    <p class="text-xs text-gray-500">Jadwal <span data-ringkasan="nama-rekan"></span></p>
                                    <p class="mt-1 font-semibold text-gray-800" data-ringkasan="tanggal-rekan"></p>
                                    <p class="text-gray-600">Shift awal: <span data-ringkasan="shift-rekan"></span></p>
                                    <p class="text-gray-600">Menjadi: <span class="font-medium text-sji-biru" data-ringkasan="shift-rekan-baru"></span></p>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end lg:col-span-3">
                            <button type="submit" class="btn-primary">Ajukan Tukar Shift</button>
                        </div>
                    </form>

                    @push('scripts')
                        <script>
                            (() => {
                                const opsi = @js($opsi);
                                const rekanLama = @js((string) old('id_karyawan_rekan', ''));
                                const tanggalRekanLama = @js((string) old('tanggal_kerja_rekan', ''));
                                const hari = @js($hariId);
                                const pilihTanggal = document.getElementById('tanggal_kerja');
                                const pilihRekan = document.getElementById('id_karyawan_rekan');
                                const pilihTanggalRekan = document.getElementById('tanggal_kerja_rekan');
                                const ringkasan = document.getElementById('ringkasanTukar');
                                const isiRingkasan = (kunci, teks) => { ringkasan.querySelector(`[data-ringkasan="${kunci}"]`).textContent = teks; };
                                const labelTanggal = (tgl) => {
                                    const [tahun, bulan, tanggal] = tgl.split('-').map(Number);
                                    return `${hari[new Date(tahun, bulan - 1, tanggal).getDay()]}, ${tgl.split('-').reverse().join('/')}`;
                                };
                                const jadwalSaya = () => opsi.find((item) => item.tanggal === pilihTanggal.value);
                                const rekanTerpilih = () => jadwalSaya()?.rekan.find((rekan) => String(rekan.id_karyawan) === pilihRekan.value);
                                const jadwalRekanTerpilih = () => rekanTerpilih()?.jadwal.find((jadwal) => jadwal.tanggal === pilihTanggalRekan.value);

                                const tampilkanRingkasan = () => {
                                    const saya = jadwalSaya();
                                    const rekan = rekanTerpilih();
                                    const jadwalRekan = jadwalRekanTerpilih();
                                    const lengkap = Boolean(saya && rekan && jadwalRekan);
                                    ringkasan.querySelector('[data-ringkasan-kosong]').classList.toggle('hidden', lengkap);
                                    ringkasan.querySelector('[data-ringkasan-isi]').classList.toggle('hidden', !lengkap);
                                    ringkasan.querySelector('[data-ringkasan-isi]').classList.toggle('grid', lengkap);
                                    if (!lengkap) return;
                                    isiRingkasan('tanggal-saya', labelTanggal(saya.tanggal));
                                    isiRingkasan('shift-saya', saya.shift);
                                    isiRingkasan('shift-saya-baru', jadwalRekan.shift);
                                    isiRingkasan('nama-rekan', rekan.nama);
                                    isiRingkasan('tanggal-rekan', labelTanggal(jadwalRekan.tanggal));
                                    isiRingkasan('shift-rekan', jadwalRekan.shift);
                                    isiRingkasan('shift-rekan-baru', saya.shift);
                                };
                                const isiJadwalRekan = (pilihanAwal = '') => {
                                    const rekan = rekanTerpilih();
                                    pilihTanggalRekan.replaceChildren(new Option(rekan ? 'Pilih jadwal rekan' : 'Pilih rekan terlebih dahulu', ''));
                                    pilihTanggalRekan.disabled = !rekan;
                                    (rekan?.jadwal ?? []).forEach((jadwal) => {
                                        const pilihan = new Option(`${labelTanggal(jadwal.tanggal)} — ${jadwal.shift}`, jadwal.tanggal);
                                        pilihan.selected = jadwal.tanggal === pilihanAwal;
                                        pilihTanggalRekan.add(pilihan);
                                    });
                                    tampilkanRingkasan();
                                };
                                const isiRekan = (pilihanAwal = '', tanggalAwal = '') => {
                                    const saya = jadwalSaya();
                                    pilihRekan.replaceChildren(new Option(saya ? 'Pilih rekan' : 'Pilih jadwal Anda terlebih dahulu', ''));
                                    pilihRekan.disabled = !saya;
                                    (saya?.rekan ?? []).forEach((rekan) => {
                                        const pilihan = new Option(`${rekan.nama} (${rekan.nip})`, rekan.id_karyawan);
                                        pilihan.selected = String(rekan.id_karyawan) === pilihanAwal;
                                        pilihRekan.add(pilihan);
                                    });
                                    isiJadwalRekan(tanggalAwal);
                                };

                                pilihTanggal.addEventListener('change', () => isiRekan());
                                pilihRekan.addEventListener('change', () => isiJadwalRekan());
                                pilihTanggalRekan.addEventListener('change', tampilkanRingkasan);
                                isiRekan(rekanLama, tanggalRekanLama);
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
                                <th>Tanggal Shift</th>
                                <th>Bertukar Dengan</th>
                                <th>Shift Anda</th>
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
                                @endphp
                                <tr>
                                    @php
                                        $tanggalSaya = $sayaPengaju ? $item->tanggal_kerja : PertukaranShiftService::tanggalRekan($item);
                                        $tanggalLawan = $sayaPengaju ? PertukaranShiftService::tanggalRekan($item) : $item->tanggal_kerja;
                                    @endphp
                                    <td class="whitespace-nowrap">
                                        {{ $labelTanggal($tanggalSaya) }}
                                        <p class="text-xs text-gray-400">Jadwal rekan: {{ $labelTanggal($tanggalLawan) }}</p>
                                    </td>
                                    <td>
                                        {{ $sayaPengaju ? $item->nama_rekan : $item->nama_pengaju }}
                                        <p class="text-xs text-gray-400">{{ $sayaPengaju ? 'Anda yang mengajukan' : 'Diajukan oleh rekan' }}</p>
                                    </td>
                                    <td class="whitespace-nowrap">{{ $layananTukar->labelShift($sayaPengaju ? $item->snapshot_pengaju : $item->snapshot_rekan) }}</td>
                                    <td class="whitespace-nowrap font-medium text-gray-800">{{ $layananTukar->labelShift($sayaPengaju ? $item->snapshot_rekan : $item->snapshot_pengaju) }}</td>
                                    <td>
                                        <span class="badge {{ $tahap === 'Kedaluwarsa' ? 'badge-secondary' : PertukaranShiftService::kelasBadge($item->status) }}">{{ $tahap }}</span>

                                        @if ($masihBerjalan && $sayaPengaju)
                                            <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.batalkan', $item->id) }}" class="mt-2"
                                                  onsubmit="return confirm('Batalkan pengajuan tukar shift ini?')">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn-secondary px-3 py-1.5 text-xs">Batalkan</button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="text-sm text-gray-600">{{ $item->catatan_keputusan ?? '-' }}</td>
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

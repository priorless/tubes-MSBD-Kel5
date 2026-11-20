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
                Tukar jadwal satu tanggal dengan rekan <b>satu jenis layanan</b> di lokasi yang sama. Ajukan paling lambat H-1;
                rekan menerima atau menolak, lalu Operasional memberi persetujuan akhir. Jadwal baru (beserta hak absensinya)
                berlaku hanya setelah disetujui Operasional.
            </p>

            <div class="mt-4">
                @if ($opsi === [])
                    <x-ui.empty-state title="Belum ada jadwal yang dapat ditukar."
                        message="Tukar shift tersedia untuk tanggal mulai besok yang sudah dijadwalkan Operasional dan memiliki rekan satu jenis layanan dengan shift berbeda di lokasi yang sama." />
                @else
                    <form method="POST" action="{{ route('tenaga-kerja.tukar-shift.store') }}" id="formTukarShift" class="grid grid-cols-1 gap-4 lg:grid-cols-3"
                          onsubmit="return confirm('Kirim pengajuan tukar shift? Rekan Anda akan diminta menerima atau menolak.')">
                        @csrf
                        <div class="rounded-xl bg-gray-50 p-4">
                            <label for="tanggal_kerja" class="form-label"><span class="mr-1 inline-flex size-5 items-center justify-center rounded-full bg-sji-biru text-xs font-bold text-white">1</span> Pilih tanggal shift Anda</label>
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
                                <option value="">Pilih tanggal terlebih dahulu</option>
                            </select>
                            <p class="form-hint" id="ringkasanTukar">Hanya rekan satu jenis layanan dengan shift berbeda pada tanggal itu yang ditampilkan.</p>
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
                                const rekanLama = @js((string) old('id_karyawan_rekan', ''));
                                const pilihTanggal = document.getElementById('tanggal_kerja');
                                const pilihRekan = document.getElementById('id_karyawan_rekan');
                                const ringkasan = document.getElementById('ringkasanTukar');
                                const ringkasanAwal = ringkasan.textContent;

                                const isiRekan = (pilihanAwal = '') => {
                                    const tanggal = opsi.find((item) => item.tanggal === pilihTanggal.value);
                                    pilihRekan.replaceChildren(new Option(tanggal ? 'Pilih rekan' : 'Pilih tanggal terlebih dahulu', ''));
                                    pilihRekan.disabled = !tanggal;
                                    ringkasan.textContent = ringkasanAwal;
                                    if (!tanggal) return;
                                    tanggal.rekan.forEach((rekan) => {
                                        const pilihan = new Option(`${rekan.nama} (${rekan.nip}) — ${rekan.shift}`, rekan.id_karyawan);
                                        pilihan.dataset.shift = rekan.shift;
                                        pilihan.selected = String(rekan.id_karyawan) === pilihanAwal;
                                        pilihRekan.add(pilihan);
                                    });
                                    tampilkanRingkasan(tanggal);
                                };
                                const tampilkanRingkasan = (tanggal) => {
                                    const terpilih = pilihRekan.selectedOptions[0];
                                    ringkasan.textContent = terpilih && terpilih.value
                                        ? `Shift Anda: ${tanggal.shift} → menjadi ${terpilih.dataset.shift}.`
                                        : ringkasanAwal;
                                };

                                pilihTanggal.addEventListener('change', () => isiRekan());
                                pilihRekan.addEventListener('change', () => tampilkanRingkasan(opsi.find((item) => item.tanggal === pilihTanggal.value)));
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
                                    <td class="whitespace-nowrap">{{ $labelTanggal($item->tanggal_kerja) }}</td>
                                    <td>
                                        {{ $sayaPengaju ? $item->nama_rekan : $item->nama_pengaju }}
                                        <p class="text-xs text-gray-400">{{ $sayaPengaju ? 'Anda yang mengajukan' : 'Diajukan oleh rekan' }}</p>
                                    </td>
                                    <td class="whitespace-nowrap">{{ $layananTukar->labelShift($sayaPengaju ? $item->snapshot_pengaju : $item->snapshot_rekan) }}</td>
                                    <td class="whitespace-nowrap font-medium text-gray-800">{{ $layananTukar->labelShift($sayaPengaju ? $item->snapshot_rekan : $item->snapshot_pengaju) }}</td>
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

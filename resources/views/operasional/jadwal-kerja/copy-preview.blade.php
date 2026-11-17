@extends('layouts.app')

@section('title', 'Pratinjau Salin Jadwal')

@section('content')
    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Pratinjau Salin Jadwal Bulanan</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ $penempatan->nama_perusahaan }} &mdash; {{ $penempatan->nip }} {{ $penempatan->nama_karyawan }}
                    </p>
                </div>
                <a href="{{ $urlKalender }}"
                   class="btn-secondary">Kembali ke Kalender</a>
            </div>

            <div class="card-body space-y-4">
                <div class="callout callout-info">
                    <p>
                        Sumber: <strong>{{ $judulBulanSumber }}</strong>
                        &rarr;
                        Tujuan: <strong>{{ $judulBulanTujuan }}</strong>
                    </p>
                    <p class="mt-1">Tanggal tujuan dipetakan berdasarkan nomor hari. Status Kerja/Libur dan jam ikut disalin; tanggal yang tidak ada pada bulan tujuan (mis. 30&ndash;31 untuk Februari) dilewati. Tanggal tanpa jadwal sumber tetap Belum Diatur.</p>
                </div>

                @if ($jumlah_jadwal_tujuan > 0)
                    <div class="callout callout-warning">
                        <p class="font-semibold">{{ $jumlah_jadwal_tujuan }} tanggal pada bulan tujuan sudah memiliki jadwal, {{ $jumlah_konflik }} di antaranya berkonflik dengan jadwal sumber.</p>
                        <p class="mt-1">Pilih apakah tanggal yang berkonflik dilewati atau diganti sebelum menyimpan. Data absensi tidak terpengaruh.</p>
                    </div>
                @endif

                @if ($jumlah_dilewati > 0)
                    <div class="callout callout-info">
                        <p>{{ $jumlah_dilewati }} jadwal sumber dilewati karena tanggal tujuan tidak tersedia atau berada di luar masa penempatan.</p>
                    </div>
                @endif

                @if ($errors->any())
                    <div class="callout callout-danger">
                        <p class="font-semibold">Periksa kembali data pratinjau.</p>
                        <ul class="mt-2 list-inside list-disc">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($item === [])
                    <p class="rounded-lg border border-gray-200 p-4 text-sm text-gray-500">
                        Tidak ada jadwal tersimpan pada bulan sumber. Bulan tujuan tetap tanpa jadwal dan dapat diatur dari kalender.
                    </p>
                @else
                    <form action="{{ route('operasional.jadwal-kerja.copy') }}" method="POST" class="space-y-5">
                        @csrf
                        <input type="hidden" name="id_penempatan" value="{{ $idPenempatan }}">
                        <input type="hidden" name="bulan_tujuan" value="{{ $bulan_tujuan }}">
                        <input type="hidden" name="bulan_sumber" value="{{ $bulan_sumber }}">

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase">
                                    <tr>
                                        <th class="px-3 py-3">Tanggal Sumber</th>
                                        <th class="px-3 py-3">Tanggal Tujuan</th>
                                        <th class="px-3 py-3">Status</th>
                                        <th class="px-3 py-3">Jam Tersimpan</th>
                                        <th class="px-3 py-3">Jam Masuk</th>
                                        <th class="px-3 py-3">Jam Pulang</th>
                                        <th class="px-3 py-3">Kondisi Tujuan</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    @foreach ($item as $index => $baris)
                                        <tr @class(['bg-amber-50/60' => $baris['konflik'], 'bg-gray-50' => $baris['dilewati']])>
                                            <td class="whitespace-nowrap px-3 py-3">{{ \Carbon\CarbonImmutable::parse($baris['tanggal_sumber'])->format('d/m/Y') }}</td>
                                            <td class="whitespace-nowrap px-3 py-3">
                                                {{ $baris['tanggal_kerja'] === null ? '—' : \Carbon\CarbonImmutable::parse($baris['tanggal_kerja'])->format('d/m/Y') }}
                                            </td>
                                            <td class="px-3 py-3">
                                                @if (! $baris['dilewati'] && ! $baris['konflik'])
                                                    <input type="hidden" name="item[{{ $index }}][tanggal_sumber]" value="{{ $baris['tanggal_sumber'] }}">
                                                    <select name="item[{{ $index }}][status]" class="form-input min-w-28 js-status" required>
                                                        <option value="Kerja" @selected(old("item.$index.status", $baris['status']) === 'Kerja')>Kerja</option>
                                                        <option value="Libur" @selected(old("item.$index.status", $baris['status']) === 'Libur')>Libur</option>
                                                    </select>
                                                @elseif ($baris['konflik'])
                                                    <input type="hidden" name="item[{{ $index }}][tanggal_sumber]" value="{{ $baris['tanggal_sumber'] }}">
                                                    <input type="hidden" name="item[{{ $index }}][status]" value="{{ old("item.$index.status", $baris['status']) }}">
                                                    {{ old("item.$index.status", $baris['status']) }}
                                                @else
                                                    {{ $baris['status'] }}
                                                @endif
                                            </td>
                                            <td class="px-3 py-3">
                                                @if (! $baris['dilewati'] && ! $baris['konflik'])
                                                    <div class="js-shift-fields" data-index="{{ $index }}">
                                                        <select name="item[{{ $index }}][id_template_shift]" class="form-input min-w-40 js-template">
                                                            <option value="">Isi jam sendiri</option>
                                                            @foreach ($template_shift as $shift)
                                                                <option value="{{ $shift->id }}"
                                                                        data-jam-masuk="{{ substr($shift->jam_masuk, 0, 5) }}"
                                                                        data-jam-pulang="{{ substr($shift->jam_pulang, 0, 5) }}"
                                                                        @selected((string) old("item.$index.id_template_shift", $baris['id_template_shift']) === (string) $shift->id)>
                                                                    {{ $shift->nama_shift }}@if ($shift->status !== 'Aktif') (Nonaktif)@endif
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @elseif ($baris['konflik'])
                                                    <input type="hidden" name="item[{{ $index }}][id_template_shift]" value="{{ old("item.$index.id_template_shift", $baris['id_template_shift']) }}">
                                                    {{ $baris['nama_shift_snapshot'] ?? '—' }}
                                                @else
                                                    {{ $baris['nama_shift_snapshot'] ?? '—' }}
                                                @endif
                                            </td>
                                            <td class="px-3 py-3">
                                                @if (! $baris['dilewati'] && ! $baris['konflik'])
                                                    <input type="time" name="item[{{ $index }}][jam_masuk]" class="form-input min-w-28 js-jam"
                                                        value="{{ old("item.$index.jam_masuk", $baris['jam_masuk']) }}">
                                                @elseif ($baris['konflik'])
                                                    <input type="hidden" name="item[{{ $index }}][jam_masuk]" value="{{ old("item.$index.jam_masuk", $baris['jam_masuk']) }}">
                                                    {{ $baris['jam_masuk'] ?? '—' }}
                                                @else
                                                    {{ $baris['jam_masuk'] ?? '—' }}
                                                @endif
                                            </td>
                                            <td class="px-3 py-3">
                                                @if (! $baris['dilewati'] && ! $baris['konflik'])
                                                    <input type="time" name="item[{{ $index }}][jam_pulang]" class="form-input min-w-28 js-jam"
                                                        value="{{ old("item.$index.jam_pulang", $baris['jam_pulang']) }}">
                                                @elseif ($baris['konflik'])
                                                    <input type="hidden" name="item[{{ $index }}][jam_pulang]" value="{{ old("item.$index.jam_pulang", $baris['jam_pulang']) }}">
                                                    {{ $baris['jam_pulang'] ?? '—' }}
                                                @else
                                                    {{ $baris['jam_pulang'] ?? '—' }}
                                                @endif
                                            </td>
                                            <td class="px-3 py-3">
                                                @if ($baris['dilewati'])
                                                    <span class="badge badge-secondary">{{ $baris['alasan_dilewati'] }}</span>
                                                @elseif ($baris['konflik'])
                                                    <span class="badge badge-warning">Konflik: jadwal sudah ada</span>
                                                    <p class="mt-1 text-xs text-gray-600">
                                                        {{ $baris['jadwal_tujuan']['status'] }}
                                                        @if ($baris['jadwal_tujuan']['nama_shift_snapshot'])
                                                            &mdash; {{ $baris['jadwal_tujuan']['nama_shift_snapshot'] }}
                                                        @endif
                                                    </p>
                                                @else
                                                    <span class="badge badge-success">Siap disalin</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if ($jumlah_konflik > 0)
                            <fieldset class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-gray-700">
                                <legend class="px-1 font-semibold text-gray-800">Penanganan {{ $jumlah_konflik }} tanggal berkonflik</legend>
                                <label class="flex items-start gap-2">
                                    <input type="radio" name="mode_konflik" value="lewati" class="mt-1" required @checked(old('mode_konflik') === 'lewati')>
                                    <span><strong>Pertahankan jadwal lama</strong> &mdash; jadwal tujuan yang sudah ada dipertahankan.</span>
                                </label>
                                <label class="flex items-start gap-2">
                                    <input type="radio" name="mode_konflik" value="ganti" class="mt-1" required @checked(old('mode_konflik') === 'ganti')>
                                    <span><strong>Ganti jadwal lama</strong> &mdash; jadwal tujuan diganti dengan jadwal sumber (riwayat perubahan tetap tercatat).</span>
                                </label>
                            </fieldset>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary" @disabled(collect($item)->every(fn (array $baris): bool => $baris['dilewati']))>
                                Simpan Salinan
                            </button>
                            <a href="{{ $urlKalender }}"
                               class="btn-secondary">Batalkan</a>
                        </div>
                    </form>
                @endif

                @if ($jadwal_tujuan_tambahan->isNotEmpty())
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <h3 class="font-semibold text-gray-800">Jadwal tujuan lain (tidak berkonflik) yang tetap dipertahankan</h3>
                        <ul class="mt-2 space-y-1 text-sm text-gray-700">
                            @foreach ($jadwal_tujuan_tambahan as $jadwal)
                                <li>
                                    {{ \Carbon\CarbonImmutable::parse($jadwal->tanggal_kerja)->format('d/m/Y') }}
                                    &mdash; {{ $jadwal->status }}
                                    @if ($jadwal->nama_shift_snapshot)
                                        ({{ $jadwal->nama_shift_snapshot }})
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.js-shift-fields').forEach((container) => {
            const row = container.closest('tr');
            const status = row.querySelector('.js-status');
            const select = container.querySelector('.js-template');
            const jamFields = row.querySelectorAll('.js-jam');

            const updateFields = () => {
                const working = status.value === 'Kerja';
                container.hidden = !working;
                jamFields.forEach((field) => {
                    field.hidden = !working;
                    field.required = working;
                });
            };

            select.addEventListener('change', () => {
                const option = select.selectedOptions[0];
                if (option?.dataset.jamMasuk) {
                    jamFields[0].value = option.dataset.jamMasuk;
                    jamFields[1].value = option.dataset.jamPulang;
                }
            });
            status.addEventListener('change', updateFields);
            updateFields();
        });
    </script>
@endsection

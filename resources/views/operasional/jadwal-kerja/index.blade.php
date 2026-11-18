@extends('layouts.app')

@section('title', 'Jadwal Kerja')

@section('content')
    <div class="space-y-6">
        @include('operasional.jadwal-kerja._tab', ['tabAktif' => 'jadwal', 'jumlahMenunggu' => $jumlahPertukaranMenunggu ?? 0])

        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Jadwal Kerja Karyawan</h2>
                </div>
                @if ($templateJadwalTersedia)
                    <a href="{{ route('operasional.jadwal-kerja.template-jadwal.index') }}" class="btn-secondary">Pola Jadwal Tersimpan</a>
                @endif
            </div>

            <div class="card-body space-y-6">
                @if (! $rosterTersedia)
                    <div class="callout callout-info">
                        <p class="font-semibold">Skema roster belum diterapkan</p>
                        <p class="mt-1">Pengaturan jam tersimpan dan jadwal harian tersedia setelah migration jadwal kerja diterapkan.</p>
                    </div>
                @endif

                <form method="GET" action="{{ route('operasional.jadwal-kerja') }}" class="grid grid-cols-1 items-end gap-4 md:grid-cols-[1fr_2fr_1fr_auto]">
                    <div>
                        <label for="id_klien" class="form-label">Pilih Klien</label>
                        <select id="id_klien" name="id_klien" class="form-input" onchange="this.form.id_karyawan.value = ''; this.form.submit()">
                            <option value="">Semua klien</option>
                            @foreach ($daftarKlienFilter as $item)
                                <option value="{{ $item->id }}" @selected($idKlien === (int) $item->id)>{{ $item->nama_perusahaan }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="id_karyawan" class="form-label">Pilih Karyawan</label>
                        <select id="id_karyawan" name="id_karyawan" class="form-input">
                            <option value="">Pilih karyawan</option>
                            @foreach ($daftarKaryawan as $item)
                                <option value="{{ $item->id }}" @selected($idKaryawan === (int) $item->id)>
                                    {{ $item->nip }} &mdash; {{ $item->nama }}@if ((int) $item->jumlah_aktif === 0) (tanpa penempatan aktif)@endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="bulan" class="form-label">Pilih Bulan</label>
                        <input id="bulan" name="bulan" type="month" class="form-input" value="{{ $awalBulan->format('Y-m') }}" required>
                    </div>
                    <button type="submit" class="btn-primary">Tampilkan Jadwal</button>
                </form>

                @if ($idKaryawan !== null && $karyawanTerpilih === null)
                    <div class="callout callout-warning">Karyawan tidak ditemukan atau belum memiliki penempatan.</div>
                @endif

                @if ($karyawanTerpilih === null)
                    <x-ui.empty-state title="Belum ada karyawan dipilih." />
                @else
                    <div class="flex flex-col gap-4 border-t border-gray-200 pt-5 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">{{ $karyawanTerpilih->nama }} &mdash; {{ $judulBulan }}</h3>
                            <ul class="mt-1 space-y-0.5 text-sm text-gray-500">
                                @foreach ($penempatan as $item)
                                    <li>
                                        {{ $item->nama_perusahaan }}:
                                        {{ \Carbon\CarbonImmutable::parse($item->tgl_mulai)->format('d/m/Y') }}
                                        &ndash; {{ $item->tgl_selesai === null ? 'sekarang' : \Carbon\CarbonImmutable::parse($item->tgl_selesai)->format('d/m/Y') }}
                                        <span @class(['badge', 'badge-success' => $item->status === 'Aktif', 'badge-secondary' => $item->status !== 'Aktif'])>{{ $item->status }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ $navigasi['sebelumnya'] }}" class="btn-secondary btn-sm">&larr; Bulan Sebelumnya</a>
                            <a href="{{ $navigasi['bulan_ini'] }}" class="btn-secondary btn-sm">Bulan Ini</a>
                            <a href="{{ $navigasi['berikutnya'] }}" class="btn-secondary btn-sm">Bulan Berikutnya &rarr;</a>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 text-sm" aria-label="Legenda status jadwal">
                        <span class="font-medium text-gray-600">Status:</span>
                        <span class="badge badge-success">Kerja</span>
                        <span class="badge badge-secondary">Libur</span>
                        <span class="badge badge-warning">Belum Diatur</span>
                        <span class="text-gray-500">Klik tanggal untuk memilih; Shift+klik untuk memilih rentang.</span>
                    </div>

                    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                        <div class="min-w-0 overflow-x-auto rounded-lg border border-gray-200">
                       <div class="min-w-[680px]">
                                <div class="grid grid-cols-7 border-b border-gray-200 bg-gray-50">
                                    @foreach ($namaHari as $hari)
                                        <div class="px-3 py-2.5 text-center text-xs font-semibold tracking-wide text-gray-500 uppercase">{{ $hari }}</div>
                                    @endforeach
                                </div>

                                <div class="-mb-px grid grid-cols-7 [&>*:nth-child(7n)]:border-r-0" id="kalender-jadwal">
                                    @foreach ($hariKalender as $hari)
                                        @php
                                            $jadwalHari = $hari['jadwal'];
                                            $elemen = $hari['dapat_diatur'] ? 'button' : 'div';
                                        @endphp
                                        <{{ $elemen }}
                                            @if ($hari['dapat_diatur'])
                                                type="button"
                                                data-tanggal="{{ $hari['tanggal_string'] }}"
                                                data-status="{{ $hari['status'] }}"
                                                data-label="{{ $hari['tanggal']->format('d/m') }}"
                                                aria-pressed="false"
                                                aria-label="{{ $hari['tanggal']->format('d/m/Y') }} {{ $hari['status'] }}"
                                            @endif
                                            @class([
                                                'js-tanggal flex min-h-32 flex-col border-b border-r border-gray-200 p-2.5 text-left align-top',
                                                'cursor-pointer bg-white transition-colors hover:bg-blue-50' => $hari['dapat_diatur'],
                                                'bg-gray-50 text-gray-400' => ! $hari['dapat_diatur'],
                                            ])>
                                            @if ($hari['dalam_bulan'])
                                                <div class="flex items-center justify-between gap-1">
                                                    <span @class([
                                                        'inline-flex size-7 items-center justify-center rounded-full text-sm font-semibold tabular-nums',
                                                        'bg-sji-biru text-white' => $hari['hari_ini'],
                                                        'text-gray-800' => ! $hari['hari_ini'] && $hari['dapat_diatur'],
                                                    ])>
                                                        {{ $hari['tanggal']->format('j') }}
                                                    </span>
                                                    @if ($hari['hari_ini'])
                                                        <span class="text-xs font-medium text-sji-biru">Hari ini</span>
                                                    @endif
                                                </div>

                                                @if ($hari['status'] === 'Kerja')
                                                    <div class="mt-2 space-y-1">
                                                        <span class="badge badge-success">Kerja</span>
                                                        <p class="text-sm font-semibold text-gray-800 tabular-nums">
                                                            {{ substr($jadwalHari->jam_masuk, 0, 5) }}&ndash;{{ substr($jadwalHari->jam_pulang, 0, 5) }}
                                                        </p>
                                                        @if ($hari['pulang_besok'])
                                                            <p class="text-xs text-gray-500">(+1 hari)</p>
                                                        @endif
                                                        <p class="truncate text-xs text-gray-500" title="{{ $jadwalHari->nama_shift_snapshot ?? 'Jam manual' }}">{{ $jadwalHari->nama_shift_snapshot ?? 'Jam manual' }}</p>
                                                    </div>
                                                @elseif ($hari['status'] === 'Libur')
                                                    <div class="mt-2"><span class="badge badge-secondary">Libur</span></div>
                                                @elseif ($hari['status'] === 'Belum Diatur')
                                                    <div class="mt-2 space-y-1">
                                                        <span class="badge badge-warning">Belum Diatur</span>
                                                        <p class="text-xs text-gray-500">Jadwal belum ditentukan</p>
                                                    </div>
                                                @elseif ($hari['status'] === 'Di Luar Penempatan')
                                                    <p class="mt-2 text-xs">Di luar penempatan</p>
                                                @endif

                                                @if (! $hari['dapat_diatur'] && $hari['penempatan'] !== null && $hari['penempatan']->status !== 'Aktif')
                                                    <p class="mt-1 text-xs text-gray-400">Penempatan {{ $hari['penempatan']->status }} (hanya lihat)</p>
                                                @endif
                                            @endif
                                        </{{ $elemen }}>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div>
                            @if ($penempatanAktif === null || ! $rosterTersedia)
                                <div class="callout callout-info">
                                    Karyawan ini tidak memiliki penempatan aktif, sehingga jadwal hanya dapat dilihat.
                                </div>
                            @else
                                <form id="panel-tanggal" method="POST" action="{{ route('operasional.jadwal-kerja.atur') }}"
                                      class="space-y-5 rounded-lg border border-gray-200 bg-white p-5 shadow-sm xl:sticky xl:top-4">
                                    @csrf
                                    <input type="hidden" name="id_karyawan" value="{{ $karyawanTerpilih->id }}">
                                    <input type="hidden" name="bulan" value="{{ $awalBulan->format('Y-m') }}">
                                    <div id="input-tanggal"></div>

                                    <div class="border-b border-gray-200 pb-4">
                                        <h3 class="text-base font-semibold text-gray-800">Pengaturan Tanggal</h3>
                                        <p class="mt-0.5 text-xs text-gray-500">Atur jam kerja atau hari libur untuk tanggal yang dipilih.</p>
                                    </div>

                                    <div class="rounded-md bg-gray-50 p-3">
                                        <p class="text-sm font-medium text-gray-700"><span id="jumlah-dipilih" class="font-semibold text-sji-biru tabular-nums">0</span> tanggal dipilih</p>
                                        <div id="daftar-dipilih" class="mt-2 flex flex-wrap gap-1.5"></div>
                                        <p id="kosong-dipilih" class="mt-1 text-xs text-gray-500">Klik tanggal pada kalender untuk memilih.</p>
                                    </div>

                                    <div>
                                        <label for="id_template_shift" class="form-label">Gunakan Jam Tersimpan (opsional)</label>
                                        <select id="id_template_shift" name="id_template_shift" class="form-input">
                                            <option value="">Isi jam sendiri</option>
                                            @foreach ($templateShift as $shift)
                                                <option value="{{ $shift->id }}"
                                                        data-jam-masuk="{{ substr($shift->jam_masuk, 0, 5) }}"
                                                        data-jam-pulang="{{ substr($shift->jam_pulang, 0, 5) }}"
                                                        @selected((string) old('id_template_shift') === (string) $shift->id)>
                                                    {{ $shift->nama_shift }} ({{ substr($shift->jam_masuk, 0, 5) }}&ndash;{{ substr($shift->jam_pulang, 0, 5) }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <p class="form-hint">
                                            <a href="{{ route('operasional.jadwal-kerja.template-shift.index') }}" class="text-sji-biru hover:underline">Kelola jam tersimpan</a>
                                        </p>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label for="jam_masuk" class="form-label">Jam Masuk</label>
                                            <input id="jam_masuk" name="jam_masuk" type="time" class="form-input" value="{{ old('jam_masuk') }}">
                                        </div>
                                        <div>
                                            <label for="jam_pulang" class="form-label">Jam Pulang</label>
                                            <input id="jam_pulang" name="jam_pulang" type="time" class="form-input" value="{{ old('jam_pulang') }}">
                                        </div>
                                    </div>
                                    <p id="info-durasi" class="form-hint" aria-live="polite"></p>
                                    <p id="pesan-panel" class="form-error" role="alert" hidden></p>

                                    <div class="grid grid-cols-1 gap-2 border-t border-gray-200 pt-4 sm:grid-cols-2 xl:grid-cols-1">
                                        <button type="submit" name="aksi" value="kerja" class="btn-primary js-butuh-pilihan sm:col-span-2 xl:col-span-1">Atur Jam Kerja</button>
                                        <button type="submit" name="aksi" value="libur" class="btn-secondary js-butuh-pilihan">Tandai Hari Libur</button>
                                        <button type="submit" name="aksi" value="jadikan_kerja" id="tombol-jadikan-kerja" class="btn-secondary js-butuh-pilihan">Jadikan Hari Kerja</button>
                                        <button type="button" id="tombol-batal" class="btn-secondary js-butuh-pilihan">Batalkan Pilihan Tanggal</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    </div>

                    @if ($rosterTersedia && ($penempatanAktif !== null || $templateJadwalTersedia))
                    <details id="lainnya" class="rounded-lg border border-gray-200" @if ($errors->hasAny(['tindakan', 'nama_template', 'id_template_jadwal', 'jenis']) || old('tindakan')) open @endif>
                        <summary class="cursor-pointer select-none px-4 py-3">
                            <span class="font-semibold text-gray-800">Lainnya: Salin Jadwal Bulanan &amp; Simpan Pola Jadwal</span>
                        </summary>
                        <div class="space-y-6 border-t border-gray-200 p-4">
                    @if ($rosterTersedia && $penempatanAktif !== null)
                        <div id="salin-jadwal">
                            <h3 class="font-semibold text-gray-800">Salin Jadwal Bulanan</h3>
                            <p class="mt-1 text-sm text-gray-500">Salin jadwal dari bulan lain.</p>
                            <form action="{{ route('operasional.jadwal-kerja.copy.preview') }}" method="GET" class="mt-4 flex flex-wrap items-end gap-3">
                                <input type="hidden" name="id_penempatan" value="{{ $penempatanAktif->id }}">
                                <div>
                                    <label for="bulan_tujuan" class="form-label">Bulan Tujuan</label>
                                    <input id="bulan_tujuan" name="bulan_tujuan" type="month" class="form-input" value="{{ $awalBulan->format('Y-m') }}" required>
                                </div>
                                <div>
                                    <label for="bulan_sumber" class="form-label">Bulan Sumber</label>
                                    <input id="bulan_sumber" name="bulan_sumber" type="month" class="form-input" value="{{ $awalBulan->subMonth()->format('Y-m') }}" required>
                                </div>
                                <button type="submit" class="btn-secondary">Pratinjau Salinan</button>
                            </form>
                        </div>
                    @endif

                    @if ($templateJadwalTersedia)
                        <div id="simpan-template" class="border-t border-gray-200 pt-5">
                            <h3 class="font-semibold text-gray-800">Simpan Pola Jadwal</h3>
                            <p class="mt-1 text-sm text-gray-500">Simpan jadwal bulan ini sebagai pola.</p>

                            @if ($jumlahJadwalBulan === 0)
                                <p class="mt-3 text-sm text-gray-500">Belum ada jadwal pada bulan ini untuk dijadikan pola.</p>
                            @else
                                <form id="form-simpan-template" method="POST" action="{{ route('operasional.jadwal-kerja.template-jadwal.store-from-schedule') }}" class="mt-4 space-y-4">
                                    @csrf
                                    <input type="hidden" name="id_karyawan" value="{{ $karyawanTerpilih->id }}">
                                    <input type="hidden" name="bulan" value="{{ $awalBulan->format('Y-m') }}">
                                    @php $tindakanAwal = old('tindakan', 'buat_baru'); $jenisTemplateAwal = old('jenis', 'Pribadi'); @endphp

                                    <div class="flex flex-wrap gap-4 text-sm">
                                        <label class="flex items-center gap-2">
                                            <input type="radio" name="tindakan" value="buat_baru" class="js-tindakan" @checked($tindakanAwal === 'buat_baru')>
                                            Buat pola baru
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <input type="radio" name="tindakan" value="perbarui" class="js-tindakan" @checked($tindakanAwal === 'perbarui') @disabled($templateJadwalTujuan->isEmpty())>
                                            Perbarui pola yang sudah ada
                                        </label>
                                    </div>

                                    <fieldset id="bagian-template-baru" class="grid grid-cols-1 gap-4 md:grid-cols-2" @if ($tindakanAwal !== 'buat_baru') hidden disabled @endif>
                                        <div>
                                            <label for="nama_template_baru" class="form-label">Nama Pola</label>
                                            <input id="nama_template_baru" name="nama_template" type="text" maxlength="100" class="form-input"
                                                   value="{{ old('nama_template', 'Pola '.$karyawanTerpilih->nama.' '.$judulBulan) }}">
                                        </div>
                                        <div>
                                            <span class="form-label">Jenis Pola</span>
                                            <div class="flex flex-wrap gap-4 pt-2 text-sm">
                                                <label class="flex items-center gap-2">
                                                    <input type="radio" name="jenis" value="Pribadi" class="js-jenis-template" @checked($jenisTemplateAwal === 'Pribadi')>
                                                    Pola Pribadi &mdash; hanya untuk {{ $karyawanTerpilih->nama }}
                                                </label>
                                                <label class="flex items-center gap-2">
                                                    <input type="radio" name="jenis" value="Bersama" class="js-jenis-template" @checked($jenisTemplateAwal === 'Bersama') @disabled($daftarKlienTemplate->isEmpty())>
                                                    Pola Bersama &mdash; untuk beberapa karyawan di satu klien
                                                </label>
                                            </div>
                                        </div>
                                        <fieldset id="bagian-template-bersama" class="grid grid-cols-1 gap-4 md:col-span-2 md:grid-cols-2" @if ($jenisTemplateAwal !== 'Bersama') hidden disabled @endif>
                                            <div>
                                                <label for="id_klien_template" class="form-label">Klien</label>
                                                <select id="id_klien_template" name="id_klien" class="form-input">
                                                    @foreach ($daftarKlienTemplate as $item)
                                                        <option value="{{ $item->id }}" @selected((string) old('id_klien', $penempatanAktif?->id_klien) === (string) $item->id)>{{ $item->nama_perusahaan }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label for="id_jenis_layanan_template" class="form-label">Jenis Layanan (opsional)</label>
                                                <select id="id_jenis_layanan_template" name="id_jenis_layanan" class="form-input">
                                                    <option value="">Semua jenis layanan</option>
                                                    @foreach ($daftarLayananTemplate as $item)
                                                        <option value="{{ $item->id }}" @selected((string) old('id_jenis_layanan') === (string) $item->id)>{{ $item->nama_layanan }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </fieldset>
                                        <div class="md:col-span-2">
                                            <label for="keterangan_template" class="form-label">Keterangan (opsional)</label>
                                            <input id="keterangan_template" name="keterangan" type="text" maxlength="1000" class="form-input" value="{{ old('keterangan') }}">
                                        </div>
                                    </fieldset>

                                    <fieldset id="bagian-template-perbarui" class="max-w-md" @if ($tindakanAwal !== 'perbarui') hidden disabled @endif>
                                        <label for="id_template_jadwal" class="form-label">Pola yang Diperbarui</label>
                                        <select id="id_template_jadwal" name="id_template_jadwal" class="form-input">
                                            @foreach ($templateJadwalTujuan as $item)
                                                <option value="{{ $item->id }}" @selected((string) old('id_template_jadwal') === (string) $item->id)>{{ $item->nama_template }} ({{ $item->jenis }})</option>
                                            @endforeach
                                        </select>
                                        <p class="form-hint">Isi pola diganti dengan jadwal bulan ini; nama dan jenisnya tetap. Jadwal yang pernah dibuat dari pola ini tidak berubah.</p>
                                    </fieldset>

                                    <button type="submit" class="btn-primary">Simpan Pola Jadwal</button>
                                </form>
                                <script>
                                    (() => {
                                        const tampilkan = (id, aktif) => {
                                            const bagian = document.getElementById(id);
                                            bagian.hidden = !aktif;
                                            bagian.disabled = !aktif;
                                        };
                                        const jenisBersama = () => document.querySelector('.js-jenis-template:checked')?.value === 'Bersama';
                                        document.querySelectorAll('.js-tindakan').forEach((radio) => radio.addEventListener('change', () => {
                                            tampilkan('bagian-template-baru', radio.value === 'buat_baru');
                                            tampilkan('bagian-template-perbarui', radio.value === 'perbarui');
                                            tampilkan('bagian-template-bersama', radio.value === 'buat_baru' && jenisBersama());
                                        }));
                                        document.querySelectorAll('.js-jenis-template').forEach((radio) => radio.addEventListener('change', () => {
                                            tampilkan('bagian-template-bersama', jenisBersama());
                                        }));
                                    })();
                                </script>
                            @endif
                        </div>
                    @endif
                        </div>
                    </details>
                    <script>
                        (() => {
                            const bukaBila = () => {
                                const tujuan = location.hash ? document.querySelector(location.hash) : null;
                                const lainnya = document.getElementById('lainnya');
                                if (tujuan && lainnya.contains(tujuan)) {
                                    lainnya.open = true;
                                    tujuan.scrollIntoView();
                                }
                            };
                            window.addEventListener('hashchange', bukaBila);
                            bukaBila();
                        })();
                    </script>
                    @endif
                @endif
            </div>
        </div>
    </div>

    @if ($karyawanTerpilih !== null && $penempatanAktif !== null && $rosterTersedia)
        <script>
            (() => {
                const form = document.getElementById('panel-tanggal');
                const selTanggal = Array.from(document.querySelectorAll('#kalender-jadwal button[data-tanggal]'));
                const terpilih = new Set(@json(array_values((array) old('tanggal', []))));
                const inputTanggal = document.getElementById('input-tanggal');
                const daftar = document.getElementById('daftar-dipilih');
                const kosong = document.getElementById('kosong-dipilih');
                const jumlah = document.getElementById('jumlah-dipilih');
                const pilihTemplate = document.getElementById('id_template_shift');
                const jamMasuk = document.getElementById('jam_masuk');
                const jamPulang = document.getElementById('jam_pulang');
                const infoDurasi = document.getElementById('info-durasi');
                const pesan = document.getElementById('pesan-panel');
                const tombolJadikanKerja = document.getElementById('tombol-jadikan-kerja');
                let indeksTerakhir = null;

                const tampilkanPesan = (teks) => {
                    pesan.textContent = teks;
                    pesan.hidden = teks === '';
                };

                const render = () => {
                    inputTanggal.replaceChildren();
                    daftar.replaceChildren();
                    const urut = selTanggal.filter((sel) => terpilih.has(sel.dataset.tanggal));

                    selTanggal.forEach((sel) => {
                        const aktif = terpilih.has(sel.dataset.tanggal);
                        sel.setAttribute('aria-pressed', aktif ? 'true' : 'false');
                        sel.classList.toggle('ring-2', aktif);
                        sel.classList.toggle('ring-inset', aktif);
                        sel.classList.toggle('ring-sji-biru', aktif);
                        sel.classList.toggle('bg-blue-50', aktif);
                    });

                    urut.forEach((sel) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'tanggal[]';
                        input.value = sel.dataset.tanggal;
                        inputTanggal.append(input);

                        const chip = document.createElement('button');
                        chip.type = 'button';
                        chip.className = 'badge badge-info';
                        chip.textContent = sel.dataset.label + ' ×';
                        chip.title = 'Hapus tanggal ini dari pilihan';
                        chip.addEventListener('click', () => {
                            terpilih.delete(sel.dataset.tanggal);
                            render();
                        });
                        daftar.append(chip);
                    });

                    jumlah.textContent = urut.length;
                    kosong.hidden = urut.length > 0;
                    document.querySelectorAll('.js-butuh-pilihan').forEach((tombol) => {
                        tombol.disabled = urut.length === 0;
                    });
                    tombolJadikanKerja.disabled = !urut.some((sel) => sel.dataset.status === 'Libur');
                };

                selTanggal.forEach((sel, indeks) => {
                    sel.addEventListener('click', (event) => {
                        if (event.shiftKey && indeksTerakhir !== null) {
                            const [awal, akhir] = [indeksTerakhir, indeks].sort((a, b) => a - b);
                            selTanggal.slice(awal, akhir + 1).forEach((item) => terpilih.add(item.dataset.tanggal));
                        } else if (terpilih.has(sel.dataset.tanggal)) {
                            terpilih.delete(sel.dataset.tanggal);
                        } else {
                            terpilih.add(sel.dataset.tanggal);
                        }
                        indeksTerakhir = indeks;
                        tampilkanPesan('');
                        render();
                    });
                });

                const perbaruiDurasi = () => {
                    if (!jamMasuk.value || !jamPulang.value) {
                        infoDurasi.textContent = '';
                        return;
                    }
                    const menit = (v) => Number(v.slice(0, 2)) * 60 + Number(v.slice(3, 5));
                    const durasi = (menit(jamPulang.value) - menit(jamMasuk.value) + 1440) % 1440;
                    if (durasi === 0) {
                        infoDurasi.textContent = 'Jam pulang tidak boleh sama dengan jam masuk.';
                        return;
                    }
                    const besok = menit(jamPulang.value) <= menit(jamMasuk.value);
                    infoDurasi.textContent = `Durasi ${Math.floor(durasi / 60)} jam${durasi % 60 ? ' ' + (durasi % 60) + ' menit' : ''}`
                        + (besok ? ', pulang pada hari berikutnya.' : '.');
                };

                pilihTemplate.addEventListener('change', () => {
                    const opsi = pilihTemplate.selectedOptions[0];
                    if (opsi?.dataset.jamMasuk) {
                        jamMasuk.value = opsi.dataset.jamMasuk;
                        jamPulang.value = opsi.dataset.jamPulang;
                    }
                    perbaruiDurasi();
                });
                jamMasuk.addEventListener('input', perbaruiDurasi);
                jamPulang.addEventListener('input', perbaruiDurasi);

                document.getElementById('tombol-batal').addEventListener('click', () => {
                    terpilih.clear();
                    indeksTerakhir = null;
                    tampilkanPesan('');
                    render();
                });

                form.addEventListener('submit', (event) => {
                    const aksi = event.submitter?.value;
                    if (terpilih.size === 0) {
                        event.preventDefault();
                        tampilkanPesan('Pilih minimal satu tanggal pada kalender.');
                        return;
                    }
                    if ((aksi === 'kerja' || aksi === 'jadikan_kerja') && (!jamMasuk.value || !jamPulang.value)) {
                        event.preventDefault();
                        tampilkanPesan('Pilih jam tersimpan atau isi jam masuk dan jam pulang terlebih dahulu.');
                        (jamMasuk.value ? jamPulang : jamMasuk).focus();
                    }
                });

                perbaruiDurasi();
                render();
            })();
        </script>
    @endif
@endsection

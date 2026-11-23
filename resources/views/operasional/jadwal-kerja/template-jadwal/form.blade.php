@extends('layouts.app')

@section('title', $template === null ? 'Buat Pola Jadwal' : 'Edit Pola Jadwal')

@php
    $jenisTerpilih = $template?->jenis ?? old('jenis', $jenisAwal);
    $polaAwal = old('pola', $pola);
@endphp

@section('content')
    <form id="form-template" method="POST"
          action="{{ $template === null
              ? route('operasional.jadwal-kerja.template-jadwal.store')
              : route('operasional.jadwal-kerja.template-jadwal.update', $template->id) }}"
          class="space-y-6">
        @csrf
        @if ($template !== null)
            @method('PUT')
        @endif

        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">{{ $template === null ? 'Buat Pola Jadwal Bulanan' : 'Edit Pola: '.$template->nama_template }}</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        @if ($template === null)
                            Atur pola hari ke-1 sampai 31.
                        @else
                            Jadwal yang sudah dibuat dari pola ini tidak berubah.
                        @endif
                    </p>
                </div>
                <a href="{{ $template === null ? route('operasional.jadwal-kerja.template-jadwal.index') : route('operasional.jadwal-kerja.template-jadwal.show', $template->id) }}"
                   class="btn-secondary btn-sm">Batal</a>
            </div>

            <div class="card-body space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label for="nama_template" class="form-label">Nama Pola</label>
                        <input id="nama_template" name="nama_template" type="text" maxlength="100" class="form-input" required
                               value="{{ old('nama_template', $template?->nama_template) }}" placeholder="Contoh: Pola Jadwal Andi">
                        @error('nama_template') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <span class="form-label">Jenis Pola</span>
                        @if ($template === null)
                            <div class="flex flex-wrap gap-4 pt-2 text-sm">
                                @foreach (['Pribadi' => 'Pribadi — untuk satu karyawan', 'Bersama' => 'Bersama — untuk beberapa karyawan di satu klien'] as $nilai => $label)
                                    <label class="flex items-center gap-2">
                                        <input type="radio" name="jenis" value="{{ $nilai }}" class="js-jenis" @checked($jenisTerpilih === $nilai)>
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <p class="pt-2 text-sm text-gray-700">
                                <span class="badge badge-{{ $template->jenis === 'Pribadi' ? 'info' : 'primary' }}">{{ $template->jenis }}</span>
                                <span class="text-gray-500">Jenis dan karyawan/klien terkait tidak dapat diubah.</span>
                            </p>
                        @endif
                        @error('jenis') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <fieldset id="bagian-pribadi" class="grid grid-cols-1 gap-4 md:grid-cols-2" @if ($jenisTerpilih !== 'Pribadi') hidden disabled @endif>
                    <div>
                        <label for="id_karyawan" class="form-label">Karyawan</label>
                        @if ($template === null)
                            <select id="id_karyawan" name="id_karyawan" class="form-input">
                                <option value="">Pilih karyawan</option>
                                @foreach ($daftarKaryawan as $item)
                                    <option value="{{ $item->id }}" @selected((string) old('id_karyawan') === (string) $item->id)>{{ $item->nip }} &mdash; {{ $item->nama }}</option>
                                @endforeach
                            </select>
                        @else
                            <p class="pt-2 text-sm text-gray-700">{{ $template->nip }} &mdash; {{ $template->nama_karyawan }}</p>
                        @endif
                        @error('id_karyawan') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </fieldset>

                <fieldset id="bagian-bersama" class="grid grid-cols-1 gap-4 md:grid-cols-2" @if ($jenisTerpilih !== 'Bersama') hidden disabled @endif>
                    <div>
                        <label for="id_klien" class="form-label">Klien</label>
                        @if ($template === null)
                            <select id="id_klien" name="id_klien" class="form-input">
                                <option value="">Pilih klien</option>
                                @foreach ($daftarKlien as $item)
                                    <option value="{{ $item->id }}" @selected((string) old('id_klien') === (string) $item->id)>{{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}</option>
                                @endforeach
                            </select>
                        @else
                            <p class="pt-2 text-sm text-gray-700">{{ $template->nama_perusahaan }}</p>
                        @endif
                        @error('id_klien') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="id_jenis_layanan" class="form-label">Jenis Layanan (opsional)</label>
                        <select id="id_jenis_layanan" name="id_jenis_layanan" class="form-input">
                            <option value="">Semua jenis layanan</option>
                            @foreach ($daftarLayanan as $item)
                                <option value="{{ $item->id }}" @selected((string) old('id_jenis_layanan', $template?->id_jenis_layanan) === (string) $item->id)>{{ $item->nama_layanan }}</option>
                            @endforeach
                        </select>
                        <p class="form-hint">Bila diisi, hanya karyawan dengan layanan ini yang dapat memakai pola ini.</p>
                        @error('id_jenis_layanan') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </fieldset>

                <div>
                    <label for="keterangan" class="form-label">Keterangan (opsional)</label>
                    <textarea id="keterangan" name="keterangan" rows="2" maxlength="1000" class="form-input">{{ old('keterangan', $template?->keterangan) }}</textarea>
                    @error('keterangan') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Pola Jadwal (Hari ke-1 sampai 31)</h3>
                    <p class="mt-1 text-sm text-gray-500">Klik hari untuk memilih; Shift+klik untuk rentang. Hari yang tidak diatur tetap Belum Diatur.</p>
                </div>
            </div>
            <div class="card-body">
                <div id="input-pola"></div>
                @error('pola') <p class="form-error mb-3">{{ $message }}</p> @enderror
                @foreach ($errors->get('pola.*') as $pesanPola)
                    <p class="form-error mb-3">{{ $pesanPola[0] }}</p>
                @endforeach

                <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                    <div class="grid grid-cols-4 gap-2 sm:grid-cols-7 xl:col-span-2" id="grid-pola">
                        @for ($hari = 1; $hari <= 31; $hari++)
                            <button type="button" data-hari="{{ $hari }}" aria-pressed="false"
                                    class="js-hari min-h-20 rounded-lg border border-gray-200 bg-white p-2 text-left transition-colors hover:bg-blue-50">
                                <span class="text-sm font-semibold text-gray-700">{{ $hari }}</span>
                                <span class="js-isi mt-1 block text-xs"></span>
                            </button>
                        @endfor
                    </div>

                    <div class="space-y-4 rounded-lg border border-gray-200 p-4 xl:sticky xl:top-4 xl:self-start">
                        <div>
                            <h4 class="font-semibold text-gray-800">Atur Hari Terpilih</h4>
                            <p class="text-sm text-gray-500"><span id="jumlah-dipilih">0</span> hari dipilih</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="panel_jam_masuk" class="form-label">Jam Masuk</label>
                                <input id="panel_jam_masuk" type="time" class="form-input">
                            </div>
                            <div>
                                <label for="panel_jam_pulang" class="form-label">Jam Pulang</label>
                                <input id="panel_jam_pulang" type="time" class="form-input">
                            </div>
                        </div>
                        <p id="info-durasi" class="form-hint" aria-live="polite"></p>
                        <p id="pesan-panel" class="form-error" role="alert" hidden></p>
                        <div class="grid grid-cols-1 gap-2">
                            <button type="button" data-aksi="kerja" class="btn-primary js-aksi">Isi Jam Kerja</button>
                            <button type="button" data-aksi="libur" class="btn-secondary js-aksi">Tandai Libur</button>
                            <button type="button" data-aksi="kosong" class="btn-secondary js-aksi">Kosongkan (Belum Diatur)</button>
                            <button type="button" data-aksi="batal" class="btn-secondary js-aksi">Batalkan Pilihan</button>
                        </div>
                        <p class="text-sm text-gray-600">
                            Ringkasan: <span id="ringkasan-pola"></span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="card-footer justify-start">
                <button type="submit" class="btn-primary">{{ $template === null ? 'Simpan Pola' : 'Simpan Perubahan' }}</button>
            </div>
        </div>
    </form>

    <script>
        (() => {
            const pola = {};
            Object.entries(@json((object) $polaAwal)).forEach(([hari, nilai]) => {
                if (nilai && (nilai.status === 'Kerja' || nilai.status === 'Libur')) {
                    pola[hari] = { status: nilai.status, jam_masuk: nilai.jam_masuk ?? '', jam_pulang: nilai.jam_pulang ?? '' };
                }
            });
            const terpilih = new Set();
            const selHari = Array.from(document.querySelectorAll('.js-hari'));
            const inputPola = document.getElementById('input-pola');
            const jamMasuk = document.getElementById('panel_jam_masuk');
            const jamPulang = document.getElementById('panel_jam_pulang');
            const infoDurasi = document.getElementById('info-durasi');
            const pesan = document.getElementById('pesan-panel');
            let indeksTerakhir = null;

            const menit = (jam) => Number(jam.slice(0, 2)) * 60 + Number(jam.slice(3, 5));
            const tampilkanPesan = (teks) => { pesan.textContent = teks; pesan.hidden = teks === ''; };

            const tambahInput = (nama, nilai) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = nama;
                input.value = nilai;
                inputPola.append(input);
            };

            const render = () => {
                inputPola.replaceChildren();
                let kerja = 0;
                let libur = 0;

                selHari.forEach((sel) => {
                    const hari = sel.dataset.hari;
                    const nilai = pola[hari];
                    const isi = sel.querySelector('.js-isi');
                    const aktif = terpilih.has(hari);

                    sel.setAttribute('aria-pressed', aktif ? 'true' : 'false');
                    ['ring-2', 'ring-inset', 'ring-sji-biru', 'bg-blue-50'].forEach((kelas) => sel.classList.toggle(kelas, aktif));

                    if (nilai?.status === 'Kerja') {
                        kerja++;
                        const besok = menit(nilai.jam_pulang) <= menit(nilai.jam_masuk);
                        isi.innerHTML = '<span class="badge badge-success">Kerja</span>';
                        isi.append(document.createElement('br'), `${nilai.jam_masuk}–${nilai.jam_pulang}${besok ? ' (+1 hari)' : ''}`);
                        tambahInput(`pola[${hari}][status]`, 'Kerja');
                        tambahInput(`pola[${hari}][jam_masuk]`, nilai.jam_masuk);
                        tambahInput(`pola[${hari}][jam_pulang]`, nilai.jam_pulang);
                    } else if (nilai?.status === 'Libur') {
                        libur++;
                        isi.innerHTML = '<span class="badge badge-secondary">Libur</span>';
                        tambahInput(`pola[${hari}][status]`, 'Libur');
                    } else {
                        isi.innerHTML = '<span class="text-gray-400">Belum Diatur</span>';
                    }
                });

                document.getElementById('jumlah-dipilih').textContent = terpilih.size;
                document.getElementById('ringkasan-pola').textContent =
                    `${kerja} hari kerja, ${libur} libur, ${31 - kerja - libur} belum diatur.`;
            };

            selHari.forEach((sel, indeks) => {
                sel.addEventListener('click', (event) => {
                    if (event.shiftKey && indeksTerakhir !== null) {
                        const [awal, akhir] = [indeksTerakhir, indeks].sort((a, b) => a - b);
                        selHari.slice(awal, akhir + 1).forEach((item) => terpilih.add(item.dataset.hari));
                    } else if (terpilih.has(sel.dataset.hari)) {
                        terpilih.delete(sel.dataset.hari);
                    } else {
                        terpilih.add(sel.dataset.hari);
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
                const durasi = (menit(jamPulang.value) - menit(jamMasuk.value) + 1440) % 1440;
                infoDurasi.textContent = durasi === 0
                    ? 'Jam pulang tidak boleh sama dengan jam masuk.'
                    : `Durasi ${Math.floor(durasi / 60)} jam${durasi % 60 ? ' ' + (durasi % 60) + ' menit' : ''}`
                        + (menit(jamPulang.value) <= menit(jamMasuk.value) ? ', pulang pada hari berikutnya.' : '.');
            };
            jamMasuk.addEventListener('input', perbaruiDurasi);
            jamPulang.addEventListener('input', perbaruiDurasi);

            document.querySelectorAll('.js-aksi').forEach((tombol) => {
                tombol.addEventListener('click', () => {
                    const aksi = tombol.dataset.aksi;
                    if (aksi === 'batal') {
                        terpilih.clear();
                        indeksTerakhir = null;
                        tampilkanPesan('');
                        render();
                        return;
                    }
                    if (terpilih.size === 0) {
                        tampilkanPesan('Pilih minimal satu hari pada grid.');
                        return;
                    }
                    if (aksi === 'kerja') {
                        if (!jamMasuk.value || !jamPulang.value || jamMasuk.value === jamPulang.value) {
                            tampilkanPesan('Isi jam masuk dan jam pulang yang berbeda terlebih dahulu.');
                            return;
                        }
                        terpilih.forEach((hari) => { pola[hari] = { status: 'Kerja', jam_masuk: jamMasuk.value, jam_pulang: jamPulang.value }; });
                    } else if (aksi === 'libur') {
                        terpilih.forEach((hari) => { pola[hari] = { status: 'Libur', jam_masuk: '', jam_pulang: '' }; });
                    } else {
                        terpilih.forEach((hari) => { delete pola[hari]; });
                    }
                    terpilih.clear();
                    tampilkanPesan('');
                    render();
                });
            });

            document.querySelectorAll('.js-jenis').forEach((radio) => {
                radio.addEventListener('change', () => {
                    [['bagian-pribadi', 'Pribadi'], ['bagian-bersama', 'Bersama']].forEach(([id, jenis]) => {
                        const bagian = document.getElementById(id);
                        bagian.hidden = radio.value !== jenis;
                        bagian.disabled = radio.value !== jenis;
                    });
                });
            });

            document.getElementById('form-template').addEventListener('submit', (event) => {
                if (Object.keys(pola).length === 0) {
                    event.preventDefault();
                    tampilkanPesan('Isi minimal satu hari pada pola template.');
                }
            });

            render();
        })();
    </script>
@endsection

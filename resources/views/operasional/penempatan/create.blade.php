@extends('layouts.app')

@section('title', 'Penempatan Baru')

@php
    $idKlienTerpilih = (string) old('id_klien', $idKlienAwal);
    $bisaDisimpan = $karyawan->isNotEmpty() && $klien->isNotEmpty();
@endphp

@section('content')
    <x-ui.page-header title="Penempatan Baru" description="Tempatkan karyawan lapangan ke klien aktif.">
        <a href="{{ route('operasional.penempatan.index') }}" class="btn-secondary">Batal</a>
    </x-ui.page-header>

    <form action="{{ route('operasional.penempatan.store') }}" method="POST" class="card max-w-3xl">
        @csrf

        <section class="card-body space-y-4">
            <h3 class="font-semibold text-gray-800">Karyawan</h3>
            <div>
                <label for="id_karyawan" class="form-label">Karyawan Lapangan</label>
                @if ($karyawan->isNotEmpty())
                    <input id="cari_karyawan" type="search" class="form-input mb-2" autocomplete="off"
                           placeholder="Ketik nama atau NIP untuk mencari..." aria-controls="id_karyawan">
                    <p id="status-cari-karyawan" class="form-hint mb-2" aria-live="polite" hidden></p>
                @endif
                <select id="id_karyawan" name="id_karyawan" @class(['form-input', 'form-input-error' => $errors->has('id_karyawan')]) required>
                    <option value="">Pilih karyawan</option>
                    @foreach ($karyawan as $item)
                        <option value="{{ $item->id }}" data-layanan="{{ $item->nama_layanan ?? 'Belum diisi HRD' }}"
                                @selected((string) old('id_karyawan') === (string) $item->id)>
                            {{ $item->nip }} &mdash; {{ $item->nama }}
                        </option>
                    @endforeach
                </select>
                @error('id_karyawan') <p class="form-error">{{ $message }}</p> @enderror
                @if ($karyawan->isEmpty())
                    <p class="form-hint">Tidak ada karyawan lapangan berstatus Tersedia. Minta HRD menambahkan karyawan, atau akhiri penempatan lama terlebih dahulu.</p>
                @endif
                <p id="info-layanan" class="form-hint" hidden>Jenis layanan: <strong id="nama-layanan"></strong></p>
            </div>
        </section>

        <section class="card-body space-y-4 border-t border-gray-200">
            <h3 class="font-semibold text-gray-800">Klien &amp; lokasi</h3>
            <div>
                <label for="id_klien" class="form-label">Klien</label>
                <select id="id_klien" name="id_klien" @class(['form-input', 'form-input-error' => $errors->has('id_klien')]) required>
                    <option value="">Pilih klien</option>
                    @foreach ($klien as $item)
                        <option value="{{ $item->id }}" @selected($idKlienTerpilih === (string) $item->id)>
                            {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                        </option>
                    @endforeach
                </select>
                @error('id_klien') <p class="form-error">{{ $message }}</p> @enderror
                @if ($klien->isEmpty())
                    <p class="form-hint">Belum ada klien aktif. <a href="{{ route('operasional.klien.create') }}" class="text-sji-biru hover:underline">Tambah klien</a></p>
                @endif
            </div>
            <div>
                <label for="lokasi" class="form-label">Lokasi Kerja</label>
                <input id="lokasi" name="lokasi" type="text" maxlength="255" required placeholder="Contoh: Gedung A lantai 3"
                    @class(['form-input', 'form-input-error' => $errors->has('lokasi')]) value="{{ old('lokasi') }}">
                @error('lokasi') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="card-body space-y-4 border-t border-gray-200">
            <h3 class="font-semibold text-gray-800">Periode &amp; jam kerja</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="tgl_mulai" class="form-label">Tanggal Mulai</label>
                    <input id="tgl_mulai" name="tgl_mulai" type="date" required
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_mulai')]) value="{{ old('tgl_mulai') }}">
                    @error('tgl_mulai') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="tgl_selesai" class="form-label">Tanggal Selesai (opsional)</label>
                    <input id="tgl_selesai" name="tgl_selesai" type="date"
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_selesai')]) value="{{ old('tgl_selesai') }}">
                    @error('tgl_selesai') <p class="form-error">{{ $message }}</p> @enderror
                    <p class="form-hint">Kosongkan bila kontrak belum punya batas akhir.</p>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="jam_datang" class="form-label">Jam Datang</label>
                    <input id="jam_datang" name="jam_datang" type="time" required
                        @class(['form-input', 'form-input-error' => $errors->has('jam_datang')]) value="{{ old('jam_datang') }}">
                    @error('jam_datang') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="jam_pulang" class="form-label">Jam Pulang</label>
                    <input id="jam_pulang" name="jam_pulang" type="time" required
                        @class(['form-input', 'form-input-error' => $errors->has('jam_pulang')]) value="{{ old('jam_pulang') }}">
                    @error('jam_pulang') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="form-hint">
                Jam ini menjadi patokan keterlambatan absensi foto karyawan.
                Untuk shift malam, isi jam pulang lebih awal dari jam datang (artinya pulang keesokan hari).
            </p>
        </section>

        <div class="card-footer justify-start">
            <button type="submit" class="btn-primary" @disabled(! $bisaDisimpan)>Simpan Penempatan</button>
            <a href="{{ route('operasional.penempatan.index') }}" class="btn-secondary">Batal</a>
        </div>
    </form>

    <script>
        (() => {
            const pilihKaryawan = document.getElementById('id_karyawan');
            const perbaruiLayanan = () => {
                const layanan = pilihKaryawan.selectedOptions[0]?.dataset.layanan;
                document.getElementById('info-layanan').hidden = !layanan;
                document.getElementById('nama-layanan').textContent = layanan ?? '';
            };
            pilihKaryawan.addEventListener('change', perbaruiLayanan);
            perbaruiLayanan();

            // Saring pilihan karyawan sesuai nama/NIP yang diketik; satu hasil langsung dipilih.
            const cari = document.getElementById('cari_karyawan');
            const statusCari = document.getElementById('status-cari-karyawan');
            if (cari) {
                const pilihan = [...pilihKaryawan.options].filter((opsi) => opsi.value !== '');
                cari.addEventListener('input', () => {
                    const kata = cari.value.trim().toLowerCase();
                    const cocok = pilihan.filter((opsi) => {
                        const tampil = kata === '' || opsi.textContent.toLowerCase().includes(kata);
                        opsi.hidden = !tampil;
                        opsi.disabled = !tampil;
                        return tampil;
                    });
                    if (pilihKaryawan.selectedOptions[0]?.disabled) {
                        pilihKaryawan.value = '';
                    }
                    if (kata !== '' && cocok.length === 1) {
                        pilihKaryawan.value = cocok[0].value;
                    }
                    statusCari.hidden = kata === '';
                    statusCari.textContent = cocok.length === 0
                        ? 'Tidak ada karyawan yang cocok.'
                        : `${cocok.length} karyawan cocok${cocok.length === 1 ? ' dan sudah dipilih' : ', pilih dari daftar di bawah'}.`;
                    perbaruiLayanan();
                });
            }
        })();
    </script>
@endsection

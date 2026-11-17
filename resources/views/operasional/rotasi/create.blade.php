@extends('layouts.app')

@section('title', 'Rotasi Karyawan')

@php
    $jadwalMendatang ??= [];
    $idPenempatanTerpilih = (string) old('id_penempatan_lama', $idPenempatanAwal ?? '');
    $bisaDiproses = $penempatan->isNotEmpty() && $klien->isNotEmpty();
    $format = static fn (?string $nilai): string => $nilai === null ? 'tanpa batas' : \Carbon\Carbon::parse($nilai)->format('d/m/Y');
@endphp

@section('content')
    <x-ui.page-header title="Rotasi Karyawan" description="Pindahkan karyawan dari penempatan saat ini ke klien tujuan." />

    <form id="form-rotasi" action="{{ route('operasional.rotasi.store') }}" method="POST" class="card max-w-3xl"
          onsubmit="return confirm('Proses rotasi ini? Rotasi tidak dapat dibatalkan dari aplikasi.')">
        @csrf

        <section class="card-body space-y-4">
            <h3 class="font-semibold text-gray-800">Karyawan &amp; penempatan saat ini</h3>
            <div>
                <label for="id_penempatan_lama" class="form-label">Karyawan</label>
                <select id="id_penempatan_lama" name="id_penempatan_lama" @class(['form-input', 'form-input-error' => $errors->has('id_penempatan_lama')]) required>
                    <option value="">Pilih karyawan</option>
                    @foreach ($penempatan as $item)
                        <option value="{{ $item->id }}" @selected($idPenempatanTerpilih === (string) $item->id)
                                data-klien="{{ $item->nama_perusahaan }}"
                                data-lokasi="{{ $item->lokasi_kerja }}"
                                data-jam="{{ $item->jam_datang && $item->jam_pulang ? substr($item->jam_datang, 0, 5).'–'.substr($item->jam_pulang, 0, 5) : '—' }}"
                                data-mulai="{{ $item->tgl_mulai ?? '' }}"
                                data-periode="{{ $format($item->tgl_mulai ?? null) }} – {{ $format($item->tgl_selesai ?? null) }}"
                                data-jadwal="{{ json_encode($jadwalMendatang[$item->id] ?? []) }}">
                            {{ $item->nip }} &mdash; {{ $item->nama_karyawan }} ({{ $item->nama_perusahaan }})
                        </option>
                    @endforeach
                </select>
                @error('id_penempatan_lama') <p class="form-error">{{ $message }}</p> @enderror
                @if ($penempatan->isEmpty())
                    <p class="form-hint">Tidak ada penempatan aktif yang dapat dirotasi.</p>
                @endif
            </div>
            <dl id="penempatan-saat-ini" class="grid grid-cols-1 gap-3 rounded-lg bg-gray-50 p-4 text-sm sm:grid-cols-2" hidden>
                <div><dt class="text-gray-500">Klien saat ini</dt><dd class="font-medium text-gray-800" data-saat-ini="klien"></dd></div>
                <div><dt class="text-gray-500">Lokasi kerja</dt><dd class="text-gray-800" data-saat-ini="lokasi"></dd></div>
                <div><dt class="text-gray-500">Periode</dt><dd class="text-gray-800" data-saat-ini="periode"></dd></div>
                <div><dt class="text-gray-500">Jam kerja</dt><dd class="text-gray-800" data-saat-ini="jam"></dd></div>
            </dl>
        </section>

        <section class="card-body space-y-4 border-t border-gray-200">
            <h3 class="font-semibold text-gray-800">Penempatan tujuan</h3>
            <div>
                <label for="id_klien_baru" class="form-label">Klien</label>
                <select id="id_klien_baru" name="id_klien_baru" @class(['form-input', 'form-input-error' => $errors->has('id_klien_baru')]) required>
                    <option value="">Pilih klien tujuan</option>
                    @foreach ($klien as $item)
                        <option value="{{ $item->id }}" @selected((string) old('id_klien_baru') === (string) $item->id)>
                            {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                        </option>
                    @endforeach
                </select>
                @error('id_klien_baru') <p class="form-error">{{ $message }}</p> @enderror
                @if ($klien->isEmpty())
                    <p class="form-hint">Tidak ada klien aktif yang dapat dipilih.</p>
                @endif
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="tgl_mulai_baru" class="form-label">Tanggal Mulai Penempatan</label>
                    <input id="tgl_mulai_baru" name="tgl_mulai_baru" type="date" required
                        data-hari-ini="{{ now('Asia/Jakarta')->toDateString() }}"
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_mulai_baru')]) value="{{ old('tgl_mulai_baru') }}">
                    <p class="form-hint">Tanggal efektif rotasi.</p>
                    @error('tgl_mulai_baru') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="tgl_selesai_baru" class="form-label">Tanggal Selesai Kontrak</label>
                    <input id="tgl_selesai_baru" name="tgl_selesai_baru" type="date"
                        @class(['form-input', 'form-input-error' => $errors->has('tgl_selesai_baru')]) value="{{ old('tgl_selesai_baru') }}">
                    <p class="form-hint">Opsional.</p>
                    @error('tgl_selesai_baru') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="lokasi_baru" class="form-label">Lokasi Penempatan</label>
                <input id="lokasi_baru" name="lokasi_baru" type="text" maxlength="255" required
                    @class(['form-input', 'form-input-error' => $errors->has('lokasi_baru')]) value="{{ old('lokasi_baru') }}">
                @error('lokasi_baru') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="jam_datang_baru" class="form-label">Jam Datang</label>
                    <input id="jam_datang_baru" name="jam_datang_baru" type="time"
                        @class(['form-input', 'form-input-error' => $errors->has('jam_datang_baru')]) value="{{ old('jam_datang_baru') }}">
                    @error('jam_datang_baru') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="jam_pulang_baru" class="form-label">Jam Pulang</label>
                    <input id="jam_pulang_baru" name="jam_pulang_baru" type="time"
                        @class(['form-input', 'form-input-error' => $errors->has('jam_pulang_baru')]) value="{{ old('jam_pulang_baru') }}">
                    @error('jam_pulang_baru') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="form-hint">Kosongkan jam untuk memakai jam penempatan saat ini.</p>
            <p id="dampak-jadwal" class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700" hidden></p>
            <p id="peringatan-masa-depan" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800" hidden></p>
        </section>

        <div class="card-footer justify-start">
            <button type="submit" class="btn-warning" @disabled(! $bisaDiproses)>Proses Rotasi</button>
            <a href="{{ route('operasional.penempatan.index') }}" class="btn-secondary">Batal</a>
        </div>
    </form>

    <script>
        (() => {
            const pilihPenempatan = document.getElementById('id_penempatan_lama');
            const tanggalMulai = document.getElementById('tgl_mulai_baru');
            const dampak = document.getElementById('dampak-jadwal');
            const peringatan = document.getElementById('peringatan-masa-depan');
            const tanggal = (iso) => iso.split('-').reverse().join('/');
            const besoknya = (iso) => {
                const hari = new Date(`${iso}T00:00:00Z`);
                hari.setUTCDate(hari.getUTCDate() + 1);
                return hari.toISOString().slice(0, 10);
            };

            const perbarui = () => {
                const opsi = pilihPenempatan.selectedOptions[0]?.value ? pilihPenempatan.selectedOptions[0] : null;
                document.getElementById('penempatan-saat-ini').hidden = !opsi;
                if (opsi) {
                    ['klien', 'lokasi', 'periode', 'jam'].forEach((kunci) => {
                        document.querySelector(`[data-saat-ini="${kunci}"]`).textContent = opsi.dataset[kunci] || '—';
                    });
                    if (opsi.dataset.mulai) {
                        // Database menolak tanggal yang sama dengan tanggal mulai penempatan lama.
                        tanggalMulai.min = besoknya(opsi.dataset.mulai);
                    }
                }

                const mulai = tanggalMulai.value;
                const hariIni = tanggalMulai.dataset.hariIni;
                peringatan.hidden = !(opsi && mulai && mulai > hariIni);
                if (!peringatan.hidden) {
                    peringatan.textContent = `Tanggal efektif masih di masa depan. Penempatan saat ini langsung ditutup ketika rotasi diproses, `
                        + `sehingga karyawan tidak dapat absen sebelum ${tanggal(mulai)}. Sebaiknya proses rotasi pada tanggal efektifnya.`;
                }
                dampak.hidden = !(opsi && mulai);
                if (!dampak.hidden) {
                    const jadwal = JSON.parse(opsi.dataset.jadwal || '[]').filter((tgl) => tgl >= mulai);
                    dampak.textContent = jadwal.length === 0
                        ? 'Tidak ada jadwal penempatan saat ini pada atau setelah tanggal efektif.'
                        : `${jadwal.length} tanggal jadwal penempatan saat ini mulai ${tanggal(jadwal[0])} tidak dipindahkan ke penempatan tujuan.`;
                }
            };

            pilihPenempatan.addEventListener('change', perbarui);
            tanggalMulai.addEventListener('input', perbarui);
            perbarui();
        })();
    </script>
@endsection

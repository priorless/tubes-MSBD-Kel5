@extends('layouts.app')

@section('title', 'Komponen Gaji')
@section('page-title', 'Komponen Gaji')

@section('content')
    @include('keuangan._gaya')
    @php
        $rupiah = fn ($nilai): string => 'Rp '.number_format((float) $nilai, 0, ',', '.');
        $namaPeriode = $daftarBulan[$bulan].' '.$tahun;
        $urlTanpaCari = route('keuangan.komponen-gaji.index', array_filter([
            'bulan' => $bulan, 'tahun' => $tahun, 'penempatan' => $penempatan, 'jenis_pekerjaan' => $idJenisPekerjaan,
        ], fn ($nilai): bool => $nilai !== null));
    @endphp

    <div class="keu-halaman min-w-0 space-y-6">
    <x-ui.page-header kicker="Keuangan · Input bulanan" title="Komponen Gaji"
        description="Isi gaji pokok, tunjangan, dan potongan pinjaman / koperasi karyawan sebelum payroll di-generate." />

    <section class="keu-panel" aria-label="Filter karyawan">
        <form method="GET" action="{{ route('keuangan.komponen-gaji.index') }}" id="filterKomponen"
              class="grid grid-cols-1 items-end gap-3 px-5 py-4 sm:grid-cols-2 xl:grid-cols-[10rem_7rem_minmax(0,1.6fr)_minmax(0,1fr)_auto]">
            <div>
                <label for="bulan" class="form-label">Periode Bulan</label>
                <select name="bulan" id="bulan" class="form-input w-full">
                    @foreach ($daftarBulan as $angka => $nama)
                        <option value="{{ $angka }}" @selected($angka === $bulan)>{{ $nama }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tahun" class="form-label">Periode Tahun</label>
                <input type="number" name="tahun" id="tahun" value="{{ $tahun }}" min="2000" max="2100" class="form-input w-full">
            </div>
            <div>
                <label for="penempatan" class="form-label">Penempatan</label>
                <select name="penempatan" id="penempatan" class="form-input w-full" required>
                    <option value="eksternal" @selected($penempatan === 'eksternal')>Eksternal (Semua Klien)</option>
                    @foreach ($klien as $item)
                        <option value="klien:{{ $item->id }}" @selected($penempatan === 'klien:'.$item->id)>Klien: {{ $item->nama_perusahaan }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="jenis_pekerjaan" class="form-label">Jenis Pekerjaan</label>
                <select name="jenis_pekerjaan" id="jenis_pekerjaan" class="form-input w-full">
                    <option value="">Semua jenis pekerjaan</option>
                    @foreach ($jenisPekerjaan as $jenis)
                        <option value="{{ $jenis->id }}" @selected($idJenisPekerjaan === (int) $jenis->id)>{{ $jenis->nama_layanan }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-secondary w-full xl:w-auto">Tampilkan</button>
        </form>

        <details class="keu-catatan mx-5 mb-5">
            <summary>
                <svg class="size-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                Data otomatis hanya-baca &mdash; dari mana angkanya?
            </summary>
            <div class="keu-catatan-isi">
                <div>
                    <h3>Gaji pokok &amp; potongan absensi</h3>
                    <p>Gaji pokok sebulan diisi Keuangan sesuai data dari sekretaris dan berlaku sampai diubah; bila belum pernah diisi, sementara memakai gaji kontrak penempatan. Potongan absensi = (gaji pokok / hari kerja kontrak) &times; hari tidak masuk, dihitung otomatis.</p>
                </div>
                <div>
                    <h3>Upah lembur</h3>
                    <p>Dari absensi valid yang sudah check-in dan check-out. Lembur dihitung per jam penuh setelah jam selesai shift (kelebihan di bawah 60 menit tidak dihitung). Upah per jam = gaji pokok / 173; jam pertama 1,5&times;, jam berikutnya 2&times;.</p>
                </div>
                <div>
                    <h3>Potongan BPJS</h3>
                    <p>persentase BPJS klien penempatan &times; gaji pokok, sesuai pengaturan klien (Operasional &rsaquo; Klien). Bila persentase belum diatur, potongan tidak dihitung.</p>
                </div>
                <div>
                    <h3>Upah back-up</h3>
                    <p>Rp0 karena data penugasan Back-up (Pengganti Kosong) beserta persetujuan Operasional dan aturan nominalnya belum tersedia di sistem. Tukar shift (barter) tidak termasuk back-up.</p>
                </div>
            </div>
        </details>
    </section>

    <form method="POST" action="{{ route('keuangan.komponen-gaji.store') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="bulan" value="{{ $bulan }}">
        <input type="hidden" name="tahun" value="{{ $tahun }}">
        <input type="hidden" name="penempatan" value="{{ $penempatan }}">
        <input type="hidden" name="jenis_pekerjaan" value="{{ $idJenisPekerjaan ?? '' }}">
        <input type="hidden" name="q" value="{{ $cari }}">

        <section class="keu-panel min-w-0" aria-labelledby="judul-komponen">
            <div class="keu-panel-kepala">
                <div>
                    <h2 class="keu-judul-panel" id="judul-komponen">Komponen periode {{ $namaPeriode }}</h2>
                    <p class="keu-sub">{{ $karyawan->count() }} karyawan. Kolom <span class="rounded bg-(--op-gold-soft) px-1 font-semibold text-[#7e5710]">berwarna</span> diisi manual; kolom lain otomatis.
                        Gaji pokok cukup diisi saat ada gaji baru dari sekretaris, lalu berlaku untuk periode berikutnya sampai diubah.</p>
                </div>
                @include('keuangan._cari', [
                    'id' => 'cari_komponen',
                    'nilai' => $cari,
                    'placeholder' => 'Cari NIP atau nama karyawan',
                    'formId' => 'filterKomponen',
                    'urlReset' => $urlTanpaCari,
                ])
            </div>

            @if ($cari !== '')
                <p class="keu-hasil-cari" aria-live="polite">
                    <span><strong>{{ $karyawan->count() }}</strong> dari {{ $jumlahSebelumCari }} karyawan cocok dengan &ldquo;{{ $cari }}&rdquo;. Simpan hanya mengubah karyawan yang tampil.</span>
                    <a href="{{ $urlTanpaCari }}">Tampilkan semua karyawan</a>
                </p>
            @endif

            <div class="relative overflow-x-auto">
                <table class="table keu-tabel w-[1800px] min-w-[1800px] table-fixed">
                    <colgroup>
                        <col class="w-36"><col class="w-48"><col class="w-40"><col class="w-60">
                        <col class="w-40"><col class="w-36"><col class="w-36"><col class="w-40">
                        <col class="w-44"><col class="w-56">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-(--lapis-isi-atas) bg-gray-50">NIP</th>
                            <th class="sticky left-36 z-(--lapis-isi-atas) bg-gray-50">Nama Karyawan</th>
                            <th class="sticky left-[21rem] z-(--lapis-isi-atas) bg-gray-50 shadow-[inset_-1px_0_0_var(--op-line)]">Jenis Pekerjaan</th>
                            <th class="keu-kolom-isi text-right">Gaji Pokok <span class="font-normal">/ bulan</span></th>
                            <th class="keu-kolom-isi text-right">Tunjangan</th>
                            <th class="text-right">Upah Back-up</th>
                            <th class="text-right">Upah Lembur</th>
                            <th class="text-right">Potongan Absensi</th>
                            <th class="text-right">Potongan BPJS</th>
                            <th class="keu-kolom-isi text-right">Potongan Pinjaman / Koperasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($karyawan as $item)
                            <tr>
                                <td class="sticky left-0 z-(--lapis-isi) bg-white whitespace-nowrap font-mono text-gray-600">{{ $item->nip }}</td>
                                <td class="sticky left-36 z-(--lapis-isi) bg-white whitespace-nowrap font-medium text-gray-800">{{ $item->nama }}</td>
                                <td class="sticky left-[21rem] z-(--lapis-isi) bg-white shadow-[inset_-1px_0_0_var(--op-line)]">{{ $item->nama_layanan ?? 'Belum diatur' }}</td>
                                <td class="keu-kolom-isi">
                                    <label class="sr-only" for="gaji-{{ $item->id }}">Gaji pokok sebulan {{ $item->nama }}</label>
                                    <div class="grid grid-cols-[2rem_minmax(0,1fr)] items-center gap-1"><span class="text-gray-500">Rp</span><input id="gaji-{{ $item->id }}" @class(['form-input min-w-0 w-full bg-white text-right tabular-nums', 'form-input-error' => $errors->has('komponen.'.$item->id.'.gaji_pokok')]) type="number" name="komponen[{{ $item->id }}][gaji_pokok]" min="1" max="9999999999.99" step="0.01" value="{{ old('komponen.'.$item->id.'.gaji_pokok', $item->gaji_bulanan !== null ? (float) $item->gaji_bulanan : '') }}" placeholder="Isi gaji pokok"></div>
                                    <span @class(['keu-ket is-bungkus text-right', 'is-peringatan' => $item->gaji_belum_diinput])>{{ $item->sumber_gaji }}</span>
                                    @if ($item->hari_wajib !== null && $item->gaji_bulanan !== null && abs((float) $item->gaji_pokok - (float) $item->gaji_bulanan) >= 1)
                                        <span class="keu-ket is-bungkus text-right">Prorata {{ $item->hari_wajib }} hari wajib: Rp {{ number_format($item->gaji_pokok, 0, ',', '.') }}</span>
                                    @endif
                                    @error('komponen.'.$item->id.'.gaji_pokok') <span class="form-error block text-right">{{ $message }}</span> @enderror
                                </td>
                                <td class="keu-kolom-isi">
                                    <label class="sr-only" for="tunjangan-{{ $item->id }}">Tunjangan {{ $item->nama }}</label>
                                    <div class="grid grid-cols-[2rem_minmax(0,1fr)] items-center gap-1"><span class="text-gray-500">Rp</span><input id="tunjangan-{{ $item->id }}" class="form-input min-w-0 w-full bg-white text-right tabular-nums" type="number" name="komponen[{{ $item->id }}][tunjangan]" min="0" max="9999999999.99" step="0.01" value="{{ old('komponen.'.$item->id.'.tunjangan', $item->tunjangan) }}" required></div>
                                </td>
                                <td class="keu-angka">Rp {{ number_format($item->upah_backup, 0, ',', '.') }}<span class="keu-ket">Aturan nominal belum tersedia</span></td>
                                <td class="keu-angka">Rp {{ number_format($item->upah_lembur, 0, ',', '.') }}@if ($item->jam_lembur > 0)<span class="keu-ket">{{ $item->jam_lembur }} jam lembur</span>@endif</td>
                                <td class="keu-angka keu-minus">Rp {{ number_format($item->potongan_absen, 0, ',', '.') }}</td>
                                <td class="text-right tabular-nums">
                                    @if ($item->potongan_bpjs !== null)
                                        <span class="keu-minus whitespace-nowrap">Rp {{ number_format($item->potongan_bpjs, 0, ',', '.') }}</span>
                                        <span class="keu-ket is-bungkus">{{ $item->keterangan_bpjs }}</span>
                                    @else
                                        <span class="text-gray-400">&mdash;</span>
                                        <span class="keu-ket is-bungkus is-peringatan">{{ $item->keterangan_bpjs }}</span>
                                    @endif
                                </td>
                                <td class="keu-kolom-isi">
                                    <label class="sr-only" for="pinjaman-{{ $item->id }}">Potongan Pinjaman / Koperasi {{ $item->nama }}</label>
                                    <div class="grid grid-cols-[2rem_minmax(0,1fr)] items-center gap-1"><span class="text-gray-500">Rp</span><input id="pinjaman-{{ $item->id }}" class="form-input min-w-0 w-full bg-white text-right tabular-nums" type="number" name="komponen[{{ $item->id }}][potongan_pinjaman]" min="0" max="9999999999.99" step="0.01" value="{{ old('komponen.'.$item->id.'.potongan_pinjaman', $item->potongan_pinjaman) }}" required></div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="keu-kosong">
                                    @if ($cari !== '')
                                        <p>Tidak ada karyawan dengan NIP atau nama &ldquo;{{ $cari }}&rdquo;.</p>
                                        <p>Coba sebagian nama atau NIP saja, atau periksa filter penempatan dan jenis pekerjaan.</p>
                                    @else
                                        <p>Tidak ada karyawan yang sesuai untuk periode, klien, dan jenis pekerjaan ini.</p>
                                        <p>Ubah filter di atas atau pastikan karyawan sudah ditempatkan pada periode tersebut.</p>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($karyawan->isNotEmpty())
                <div class="keu-bilah-simpan">
                    <p class="text-xs text-gray-500">Perubahan berlaku untuk {{ $karyawan->count() }} karyawan pada periode {{ $namaPeriode }}.</p>
                    <button type="submit" class="btn-primary px-6 py-2.5">Simpan Komponen Gaji</button>
                </div>
            @endif
        </section>
    </form>
    </div>
@endsection

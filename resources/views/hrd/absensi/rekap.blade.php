@use('App\Support\FormatKolom')

<form method="GET" action="{{ route('hrd.absensi') }}" class="card mb-6">
    <input type="hidden" name="tab" value="rekap">
    <div class="card-body grid grid-cols-1 gap-4 md:grid-cols-12">
        <div class="md:col-span-3">
            <label for="tanggal_awal" class="form-label">Tanggal awal</label>
            <input id="tanggal_awal" type="date" name="tanggal_awal" value="{{ $filterRekap['tanggal_awal'] }}" class="form-input">
            @error('tanggal_awal') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-3">
            <label for="tanggal_akhir" class="form-label">Tanggal akhir</label>
            <input id="tanggal_akhir" type="date" name="tanggal_akhir" value="{{ $filterRekap['tanggal_akhir'] }}" class="form-input">
            @error('tanggal_akhir') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-3">
            <label for="filter_karyawan" class="form-label">Cari Karyawan</label>
            <div class="relative" data-karyawan-autocomplete>
                <input id="filter_karyawan" type="search" class="form-input" autocomplete="off"
                    placeholder="Ketik nama atau NIP karyawan..." role="combobox" aria-autocomplete="list"
                    aria-controls="hasil-karyawan" aria-expanded="false"
                    value="{{ $karyawanTerpilih ? $karyawanTerpilih->nama.' ('.$karyawanTerpilih->nip.')' : '' }}">
                <input id="id_karyawan" type="hidden" name="id_karyawan" value="{{ $filterRekap['id_karyawan'] ?? '' }}">
                <div id="hasil-karyawan" class="absolute z-20 mt-1 hidden max-h-64 w-full overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg" role="listbox" aria-label="Hasil pencarian karyawan"></div>
                <p id="status-pencarian-karyawan" class="mt-1 text-xs text-gray-500" aria-live="polite">
                    @if ($karyawanTerpilih)
                        Filter: {{ $karyawanTerpilih->nama }} ({{ $karyawanTerpilih->nip }})
                    @else
                        Kosongkan pencarian untuk menampilkan semua karyawan.
                    @endif
                </p>
            </div>
            @error('id_karyawan') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div class="md:col-span-3">
            <label for="filter_klien" class="form-label">Klien / penempatan</label>
            <select id="filter_klien" name="id_klien" class="form-input">
                <option value="">Semua klien</option>
                @foreach ($daftarKlien as $klien)
                    <option value="{{ $klien->id }}" @selected((string) $filterRekap['id_klien'] === (string) $klien->id)>{{ $klien->nama_perusahaan }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-wrap gap-2 md:col-span-12">
            <button type="submit" class="btn-primary">Terapkan Filter</button>
            <a href="{{ route('hrd.absensi', ['tab' => 'rekap']) }}" class="btn-secondary">Reset</a>
            @if ($rekap !== null)
                <a href="{{ route('hrd.absensi.rekap.pdf', $filterRekap) }}" class="btn-secondary">Export PDF</a>
            @endif
        </div>
    </div>
</form>

@if ($ringkasanRekap)
    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
        @foreach ([
            ['Hadir', $ringkasanRekap->hadir, 'text-sji-biru'],
            ['Terlambat', $ringkasanRekap->terlambat.' kali · '.$ringkasanRekap->total_menit_terlambat.' menit', 'text-amber-600'],
            ['Izin', $ringkasanRekap->izin, 'text-blue-600'],
            ['Sakit', $ringkasanRekap->sakit, 'text-blue-600'],
            ['Alpha tercatat', $ringkasanRekap->alpha, 'text-red-600'],
            ['Tanpa catatan', $ringkasanRekap->tanpa_catatan, 'text-gray-700'],
            ['Belum check-out', $ringkasanRekap->belum_check_out, 'text-gray-700'],
        ] as [$label, $nilai, $warna])
            <div class="stat-card">
                <h3 class="stat-label">{{ $label }}</h3>
                <p class="stat-value {{ $warna }}">{{ $nilai }}</p>
            </div>
        @endforeach
    </div>

    <div class="card mb-6">
        <div class="card-header"><h2 class="card-title">Rekap lembur</h2></div>
        <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-3">
            @foreach ([
                ['Lembur', $ringkasanRekap->lembur, $ringkasanRekap->jam_lembur],
            ] as [$status, $jumlah, $jam])
                <div class="rounded-lg border border-gray-200 p-4">
                    <p class="text-sm text-gray-500">{{ $status }}</p>
                    <p class="mt-1 text-lg font-semibold text-gray-800">{{ $jumlah }} kali <span class="text-sm font-normal text-gray-500">· {{ $jam }} jam</span></p>
                </div>
            @endforeach
        </div>
    </div>

    <div class="callout callout-info mb-6">
        Tanpa catatan berarti tanggal kalender dalam rentang penempatan yang tidak memiliki baris absensi. Data penempatan belum menyimpan kalender hari kerja mingguan, sehingga angka ini bukan Alpha dan dapat mencakup hari libur.
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Detail absensi</h2></div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Tanggal</th><th>Karyawan</th><th>Klien / lokasi</th><th>Status</th><th>Terlambat</th><th>Masuk</th><th>Keluar</th><th>Lembur</th></tr></thead>
                <tbody>
                    @forelse ($rekap as $baris)
                        <tr>
                            <td class="whitespace-nowrap">{{ FormatKolom::tanggal($baris->tgl, 'd/m/Y') }}</td>
                            <td><span class="font-medium">{{ $baris->nama }}</span><span class="block text-xs text-gray-500">{{ $baris->nip }}</span></td>
                            <td>{{ $baris->nama_klien ?? '—' }}@if ($baris->lokasi_kerja)<span class="block text-xs text-gray-500">{{ $baris->lokasi_kerja }}</span>@endif</td>
                            <td><span class="badge badge-{{ ['Hadir' => 'success', 'Terlambat' => 'warning', 'Izin' => 'info', 'Sakit' => 'info', 'Alpha' => 'danger'][$baris->status_kehadiran] ?? 'secondary' }}">{{ $baris->status_kehadiran }}</span></td>
                            <td>{{ (int) $baris->menit_terlambat > 0 ? $baris->menit_terlambat.' menit' : '—' }}</td>
                            <td class="font-mono">{{ FormatKolom::jam($baris->jam_masuk) }}</td>
                            <td class="font-mono">{{ FormatKolom::jam($baris->jam_keluar) }}</td>
                            <td>{{ (int) $baris->jam_lembur > 0 ? $baris->jam_lembur.' jam' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="table-empty">Tidak ada catatan absensi pada periode dan filter ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            <span>Menampilkan {{ $rekap->firstItem() ?? 0 }}–{{ $rekap->lastItem() ?? 0 }} dari {{ $rekap->total() }} catatan</span>
            @include('partials.pagination', ['paginator' => $rekap])
        </div>
    </div>
@else
    <p class="table-empty">View v_absensi_harian belum tersedia. Jalankan <code>php artisan migrate</code>.</p>
@endif

@push('scripts')
    <script>
        (() => {
            const pembungkus = document.querySelector('[data-karyawan-autocomplete]');
            if (!pembungkus) return;

            const formulir = pembungkus.closest('form');
            const input = document.getElementById('filter_karyawan');
            const idTerpilih = document.getElementById('id_karyawan');
            const daftar = document.getElementById('hasil-karyawan');
            const status = document.getElementById('status-pencarian-karyawan');
            const urlCari = @json(route('hrd.absensi.karyawan.cari'));
            let timerPencarian;
            let permintaanAktif;

            const tutupDaftar = () => {
                daftar.replaceChildren();
                daftar.classList.add('hidden');
                input.setAttribute('aria-expanded', 'false');
            };

            const buatPilihan = (karyawan) => {
                const tombol = document.createElement('button');
                tombol.type = 'button';
                tombol.role = 'option';
                tombol.className = 'block w-full px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none';
                tombol.textContent = `${karyawan.nama} — NIP: ${karyawan.nip} · ${karyawan.layanan}`;
                tombol.addEventListener('click', () => {
                    idTerpilih.value = karyawan.id;
                    input.value = `${karyawan.nama} (${karyawan.nip})`;
                    status.textContent = `Filter: ${input.value}`;
                    tutupDaftar();
                });
                daftar.append(tombol);
            };

            input.addEventListener('input', () => {
                idTerpilih.value = '';
                window.clearTimeout(timerPencarian);
                permintaanAktif?.abort();
                const kataKunci = input.value.trim();

                if (kataKunci.length === 0) {
                    tutupDaftar();
                    status.textContent = 'Kosongkan pencarian untuk menampilkan semua karyawan.';
                    return;
                }

                status.textContent = 'Mencari karyawan...';
                timerPencarian = window.setTimeout(async () => {
                    permintaanAktif = new AbortController();
                    const parameter = new URLSearchParams({ q: kataKunci });

                    try {
                        const respons = await fetch(`${urlCari}?${parameter}`, {
                            headers: { Accept: 'application/json' },
                            signal: permintaanAktif.signal,
                        });
                        if (!respons.ok) throw new Error('Pencarian karyawan gagal.');

                        const hasil = await respons.json();
                        tutupDaftar();
                        if (hasil.length === 0) {
                            status.textContent = 'Karyawan tidak ditemukan';
                            return;
                        }

                        hasil.forEach(buatPilihan);
                        daftar.classList.remove('hidden');
                        input.setAttribute('aria-expanded', 'true');
                        status.textContent = `${hasil.length} hasil pencarian.`;
                    } catch (kesalahan) {
                        if (kesalahan.name !== 'AbortError') {
                            tutupDaftar();
                            status.textContent = 'Pencarian karyawan gagal. Coba lagi.';
                        }
                    }
                }, 250);
            });

            input.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') tutupDaftar();
                if (event.key === 'ArrowDown' && !daftar.classList.contains('hidden')) {
                    event.preventDefault();
                    daftar.querySelector('button')?.focus();
                }
            });
            formulir.addEventListener('submit', (event) => {
                if (input.value.trim() !== '' && idTerpilih.value === '') {
                    event.preventDefault();
                    status.textContent = 'Pilih karyawan dari hasil pencarian atau kosongkan pencarian untuk semua karyawan.';
                    input.focus();
                }
            });
        })();
    </script>
@endpush

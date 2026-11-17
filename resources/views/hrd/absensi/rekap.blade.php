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
            <label for="filter_karyawan" class="form-label">Karyawan</label>
            <select id="filter_karyawan" name="id_karyawan" class="form-input">
                <option value="">Semua karyawan</option>
                @foreach ($daftarKaryawan as $karyawan)
                    <option value="{{ $karyawan->id }}" @selected((string) $filterRekap['id_karyawan'] === (string) $karyawan->id)>{{ $karyawan->nama }} ({{ $karyawan->nip }})</option>
                @endforeach
            </select>
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
                <a href="{{ route('hrd.absensi.rekap.csv', $filterRekap) }}" class="btn-secondary">Export CSV</a>
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
                ['Menunggu', $ringkasanRekap->lembur_menunggu, $ringkasanRekap->jam_lembur_menunggu],
                ['Disetujui', $ringkasanRekap->lembur_disetujui, $ringkasanRekap->jam_lembur_disetujui],
                ['Ditolak', $ringkasanRekap->lembur_ditolak, $ringkasanRekap->jam_lembur_ditolak],
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
                            <td>{{ (int) $baris->jam_lembur > 0 ? $baris->jam_lembur.' jam · '.$baris->status_lembur : '—' }}</td>
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

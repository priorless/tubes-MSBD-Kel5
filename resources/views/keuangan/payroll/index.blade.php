@extends('layouts.app')

@section('title', 'Payroll')
@section('page-title', 'Payroll Karyawan')

@section('content')
    @include('keuangan._gaya')
    @php
        $rupiah = fn ($nilai): string => ((float) $nilai < 0 ? '−' : '').'Rp '.number_format(abs((float) $nilai), 0, ',', '.');
        $sudahLunas = fn (object $item): bool => $item->status_bayar === 'Sudah Lunas';
        $namaPeriode = $daftarBulan[$bulan].' '.$tahun;
        $belumBerjalan = \App\Http\Controllers\KeuanganController::periodeBelumBerjalan($bulan, $tahun);
    @endphp

    <div class="keu-halaman min-w-0 space-y-6">
    <x-ui.page-header :kicker="now()->locale('id')->translatedFormat('l, j F Y').' · Payroll karyawan'"
        :title="'Selamat datang, '.auth()->user()->namaTampilan()"
        description="Kelola payroll karyawan: generate gaji per periode, periksa rincian gaji, dan catat pencairan." />

    {{-- Periode aktif + generate payroll dalam satu bilah --}}
    <section class="keu-panel" aria-label="Periode payroll">
        <div class="flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-end lg:justify-between">
            <form method="GET" action="{{ route('keuangan.payroll.index') }}" class="grid grid-cols-2 items-end gap-3 sm:flex sm:flex-wrap">
                @if ($cari !== '')
                    <input type="hidden" name="q" value="{{ $cari }}">
                @endif
                <div class="col-span-2 sm:col-span-1">
                    <span class="op-lab">Periode payroll</span>
                    <p class="text-lg font-extrabold tracking-tight text-sji-biru-tua">{{ $namaPeriode }}</p>
                </div>
                <div class="hidden h-10 w-px bg-gray-200 sm:block" aria-hidden="true"></div>
                <div>
                    <label for="bulan" class="form-label">Bulan</label>
                    <select name="bulan" id="bulan" class="form-input min-w-0 sm:w-40" data-batas-periode="tahun">
                        @foreach ($daftarBulan as $angka => $nama)
                            <option value="{{ $angka }}" @selected($angka === $bulan)>{{ $nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="tahun" class="form-label">Tahun</label>
                    <input type="number" name="tahun" id="tahun" value="{{ $tahun }}" min="2000" max="2100" class="form-input min-w-0 sm:w-28">
                </div>
                <button type="submit" class="btn-secondary col-span-2 sm:col-span-1">Tampilkan</button>
            </form>

            <form method="POST" action="{{ route('keuangan.payroll.generate') }}" class="w-full lg:w-auto"
                  onsubmit="return confirm(@js('Generate payroll untuk '.$namaPeriode.'?'));">
                @csrf
                <input type="hidden" name="bulan" value="{{ $bulan }}">
                <input type="hidden" name="tahun" value="{{ $tahun }}">
                <button type="submit" class="btn-primary w-full whitespace-normal text-center leading-5 lg:w-auto lg:whitespace-nowrap"
                        @disabled($belumBerjalan)>
                    Generate Payroll {{ $namaPeriode }}
                </button>
            </form>
        </div>

        @if ($belumBerjalan)
            <p class="callout callout-warning mx-5 mb-4 text-sm">
                <span class="font-semibold">Periode {{ $namaPeriode }} belum berjalan.</span>
                Payroll baru dapat di-generate setelah periode dimulai dan absensinya tersedia, paling jauh sampai periode
                {{ $daftarBulan[$bulanIni] }} {{ $tahunIni }}.
            </p>
        @endif

        <details class="keu-catatan mx-5 mb-5">
            <summary>
                <svg class="size-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                Cara payroll dihitung
            </summary>
            <div class="keu-catatan-isi">
                <div>
                    <h3>Siapa yang dihitung</h3>
                    <p>Karyawan Internal berstatus Aktif/Cuti dan karyawan Lapangan yang ditempatkan pada periode tersebut.
                        Karyawan yang sudah memiliki payroll pada periode ini dilewati tanpa diubah.</p>
                </div>
                <div>
                    <h3>Komponen</h3>
                    <p>Lembur, potongan absensi, BPJS klien, tunjangan &amp; potongan pinjaman (Komponen Gaji), dan PPh 21 dihitung otomatis oleh database.</p>
                </div>
                <div>
                    <h3>Rincian Gaji</h3>
                    <p>Lihat rincian pendapatan dan potongan setiap karyawan. Rincian hanya untuk dilihat, tidak dicetak.</p>
                </div>
                <div>
                    <h3>Cair</h3>
                    <p>Tekan setelah gaji benar-benar dibayarkan. Status berubah menjadi Sudah Lunas dan tidak dapat dibatalkan.</p>
                </div>
            </div>
        </details>
    </section>

    <section class="keu-panel min-w-0 overflow-hidden" aria-labelledby="judul-slip-gaji">
        <div class="keu-panel-kepala">
            <div>
                <h2 class="keu-judul-panel" id="judul-slip-gaji">Slip gaji periode {{ $namaPeriode }}</h2>
                <p class="keu-sub">Belum dibayar ditampilkan lebih dulu, lalu urut nama karyawan. <span class="text-(--op-green)">Hijau</span> = pendapatan, <span class="text-(--op-red)">merah</span> = potongan.</p>
            </div>
            @include('keuangan._cari', [
                'id' => 'cari_payroll',
                'nilai' => $cari,
                'placeholder' => 'Cari NIP atau nama karyawan',
                'action' => route('keuangan.payroll.index'),
                'urlReset' => route('keuangan.payroll.index', ['bulan' => $bulan, 'tahun' => $tahun]),
                'tersembunyi' => ['bulan' => $bulan, 'tahun' => $tahun],
            ])
        </div>

        @if ($cari !== '')
            <p class="keu-hasil-cari" aria-live="polite">
                <span><strong>{{ $payroll->total() }}</strong> slip gaji {{ $namaPeriode }} cocok dengan &ldquo;{{ $cari }}&rdquo;</span>
                <a href="{{ route('keuangan.payroll.index', ['bulan' => $bulan, 'tahun' => $tahun]) }}">Tampilkan semua karyawan</a>
            </p>
        @endif

        <div class="relative hidden overflow-x-auto lg:block">
            <table class="table keu-tabel">
                <thead>
                    <tr>
                        <th colspan="4" class="keu-grup"><span>Karyawan</span></th>
                        <th colspan="4" class="keu-grup is-masuk"><span>Pendapatan</span></th>
                        <th colspan="4" class="keu-grup is-potong"><span>Potongan</span></th>
                        <th colspan="3" class="keu-grup"><span>Pembayaran</span></th>
                    </tr>
                    <tr>
                        <th>NIP</th>
                        <th>Nama Karyawan</th>
                        <th>Jenis Pekerjaan</th>
                        <th class="text-center" title="Hari wajib / hadir / absen">Hari (Wajib / Hadir / Absen)</th>
                        <th class="text-right">Gaji Pokok</th>
                        <th class="text-right">Tunjangan</th>
                        <th class="text-right">Upah Back-up</th>
                        <th class="text-right">Upah Lembur</th>
                        <th class="text-right">Potongan Absensi</th>
                        <th class="text-right">Potongan BPJS</th>
                        <th class="text-right">Potongan Pinjaman</th>
                        <th class="text-right">PPh 21</th>
                        <th class="keu-kolom-total text-right">Total Gaji</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payroll as $item)
                        <tr>
                            <td class="whitespace-nowrap font-mono text-gray-600">{{ $item->nip }}</td>
                            <td class="whitespace-nowrap font-medium text-gray-800">{{ $item->nama }}</td>
                            <td class="whitespace-nowrap">
                                <span @class(['badge', 'badge-info' => $item->kategori === 'Internal', 'badge-secondary' => $item->kategori !== 'Internal'])>
                                    {{ $item->jenis_pekerjaan ?? $item->kategori }}
                                </span>
                            </td>
                            <td class="text-center whitespace-nowrap tabular-nums text-gray-600">{{ $item->hari_wajib ?? '—' }} / {{ $item->hari_masuk ?? '—' }} / {{ $item->hari_absen ?? '—' }}</td>
                            <td class="keu-angka">{{ $rupiah($item->gaji_pokok) }}</td>
                            <td class="keu-angka">{{ $rupiah($item->komponen_tunjangan) }}</td>
                            <td class="keu-angka">{{ $rupiah($item->upah_backup) }}</td>
                            <td class="keu-angka">{{ $rupiah($item->total_lembur) }}</td>
                            <td class="keu-angka keu-minus">{{ $rupiah($item->potongan_absen) }}</td>
                            <td class="keu-angka keu-minus">
                                {{ $rupiah($item->potongan_bpjs) }}
                                @if ($item->bpjs_metode !== null)
                                    <span class="keu-ket">{{ \App\Support\LabelBpjs::aturan($item->bpjs_metode, $item->bpjs_nilai) }}</span>
                                @endif
                            </td>
                            <td class="keu-angka keu-minus">{{ $rupiah($item->potongan_pinjaman) }}</td>
                            <td class="keu-angka keu-minus">
                                {{ $rupiah($item->pph21) }}
                                <span class="keu-ket">{{ (float) $item->pph21 < 0 ? 'Lebih bayar, dikembalikan' : 'PTKP '.$item->status_ptkp }}</span>
                            </td>
                            <td class="keu-angka keu-kolom-total font-bold text-gray-900">{{ $rupiah($item->total_gaji) }}</td>
                            <td class="whitespace-nowrap">
                                @if ($sudahLunas($item))
                                    <span class="badge badge-success">Sudah Lunas</span>
                                    <span class="keu-ket">{{ \Illuminate\Support\Carbon::parse($item->tgl_bayar)->format('d/m/Y') }}</span>
                                @else
                                    <span class="badge badge-warning">Belum Dibayar</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" class="keu-btn-garis"
                                            data-struk-url="{{ route('keuangan.payroll.struk', $item->id) }}">Rincian Gaji</button>
                                    @unless ($sudahLunas($item))
                                        <form method="POST" action="{{ route('keuangan.payroll.cair', $item->id) }}"
                                              onsubmit="return confirm(@js('Cairkan gaji '.$item->nama.' sebesar '.$rupiah($item->total_gaji).' hari ini?'));">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="keu-btn-garis is-lunas">Cair</button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="15" class="keu-kosong">
                                @if ($cari !== '')
                                    <p>Tidak ada karyawan dengan NIP atau nama &ldquo;{{ $cari }}&rdquo; pada payroll {{ $namaPeriode }}.</p>
                                    <p>Coba sebagian nama atau NIP saja, atau periksa periode yang dipilih.</p>
                                @else
                                    <p>Belum ada data payroll untuk periode {{ $namaPeriode }}.</p>
                                    <p>Tekan Generate Payroll {{ $namaPeriode }} untuk menghitung gaji periode ini.</p>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="space-y-3 p-3 lg:hidden">
            @forelse ($payroll as $item)
                <article class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex min-w-0 items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="font-semibold leading-5 text-gray-800">{{ $item->nama }}</h3>
                            <p class="mt-1 break-all font-mono text-xs text-gray-500">{{ $item->nip }}</p>
                        </div>
                        <span @class(['badge shrink-0', 'badge-info' => $item->kategori === 'Internal', 'badge-secondary' => $item->kategori !== 'Internal'])>
                            {{ $item->jenis_pekerjaan ?? $item->kategori }}
                        </span>
                    </div>

                    <dl class="mt-3 space-y-2 border-t border-gray-100 pt-3 text-xs">
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Hari Wajib / Hadir / Absen</dt>
                            <dd class="whitespace-nowrap font-medium tabular-nums">{{ $item->hari_wajib ?? '—' }} / {{ $item->hari_masuk ?? '—' }} / {{ $item->hari_absen ?? '—' }}</dd>
                        </div>
                        @foreach ([
                            ['Gaji Pokok', $item->gaji_pokok, false],
                            ['Tunjangan', $item->komponen_tunjangan, false],
                            ['Upah Back-up', $item->upah_backup, false],
                            ['Upah Lembur', $item->total_lembur, false],
                            ['Potongan Absensi', $item->potongan_absen, true],
                            ['Potongan BPJS', $item->potongan_bpjs, true],
                            ['Potongan Pinjaman', $item->potongan_pinjaman, true],
                            ['PPh 21 (PTKP '.$item->status_ptkp.')', $item->pph21, true],
                        ] as [$label, $nilai, $potongan])
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-500">{{ $label }}</dt>
                                <dd @class(['whitespace-nowrap font-medium tabular-nums', 'text-red-600' => $potongan])>{{ $rupiah($nilai) }}</dd>
                            </div>
                        @endforeach
                        <div class="flex justify-between gap-3 rounded-md bg-blue-50 px-2 py-1.5">
                            <dt class="font-semibold text-gray-700">Total Gaji</dt>
                            <dd class="whitespace-nowrap font-bold text-gray-900 tabular-nums">{{ $rupiah($item->total_gaji) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3">
                        <div>
                            @if ($sudahLunas($item))
                                <span class="badge badge-success">Sudah Lunas</span>
                                <p class="mt-1 text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($item->tgl_bayar)->format('d/m/Y') }}</p>
                            @else
                                <span class="badge badge-warning">Belum Dibayar</span>
                            @endif
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            <button type="button" class="keu-btn-garis"
                                    data-struk-url="{{ route('keuangan.payroll.struk', $item->id) }}">Rincian Gaji</button>
                            @unless ($sudahLunas($item))
                                <form method="POST" action="{{ route('keuangan.payroll.cair', $item->id) }}"
                                      onsubmit="return confirm(@js('Cairkan gaji '.$item->nama.' sebesar '.$rupiah($item->total_gaji).' hari ini?'));">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="keu-btn-garis is-lunas">Cair</button>
                                </form>
                            @endunless
                        </div>
                    </div>
                </article>
            @empty
                <div class="keu-kosong rounded-xl border border-gray-200">
                    @if ($cari !== '')
                        <p>Tidak ada karyawan dengan NIP atau nama &ldquo;{{ $cari }}&rdquo;.</p>
                        <p>Coba sebagian nama atau NIP saja.</p>
                    @else
                        <p>Belum ada data payroll untuk periode {{ $namaPeriode }}.</p>
                        <p>Tekan Generate Payroll {{ $namaPeriode }} untuk menghitung gaji periode ini.</p>
                    @endif
                </div>
            @endforelse
        </div>

        <div class="card-footer flex-col items-start sm:flex-row sm:items-center">
            <span>Menampilkan {{ $payroll->firstItem() ?? 0 }}&ndash;{{ $payroll->lastItem() ?? 0 }} dari {{ $payroll->total() }} slip gaji</span>
            @include('partials.pagination', ['paginator' => $payroll])
        </div>
    </section>
    </div>

    <dialog id="strukModal" aria-labelledby="strukModalJudul"
            class="m-auto max-h-[92vh] w-[calc(100%-2rem)] max-w-3xl overflow-hidden rounded-xl bg-gray-100 p-0 shadow-2xl backdrop:bg-gray-900/60">
        <div class="flex max-h-[92vh] flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-white px-5 py-3">
                <h2 id="strukModalJudul" class="text-sm font-semibold text-gray-800">Rincian Gaji</h2>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800" data-struk-tutup aria-label="Tutup rincian gaji">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="overflow-y-auto p-4 sm:p-6" id="strukModalIsi" aria-live="polite"></div>
        </div>
    </dialog>
@endsection

@push('scripts')
    <script>
        (() => {
            const modal = document.getElementById('strukModal');
            const isi = document.getElementById('strukModalIsi');
            const judul = document.getElementById('strukModalJudul');

            const tampilkanPesan = (pesan, kelas) => {
                isi.innerHTML = '';
                const p = document.createElement('p');
                p.className = 'rounded-lg bg-white px-6 py-12 text-center text-sm ' + kelas;
                p.textContent = pesan;
                isi.appendChild(p);
            };

            const bukaStruk = async (url) => {
                judul.textContent = 'Rincian Gaji';
                tampilkanPesan('Memuat rincian gaji...', 'text-gray-500');
                if (!modal.open) modal.showModal();

                try {
                    const respons = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                        cache: 'no-store',
                    });
                    if (!respons.ok) throw new Error(String(respons.status));
                    isi.innerHTML = await respons.text();
                    judul.textContent = isi.querySelector('[data-judul]')?.dataset.judul ?? 'Rincian Gaji';
                } catch (galat) {
                    tampilkanPesan(galat.message === '404'
                        ? 'Rincian gaji tidak ditemukan.'
                        : 'Rincian gaji gagal dimuat. Silakan coba lagi.', 'text-red-600');
                }
            };

            document.querySelectorAll('[data-struk-url]').forEach((tombol) => {
                tombol.addEventListener('click', () => bukaStruk(tombol.dataset.strukUrl));
            });

            modal.querySelector('[data-struk-tutup]').addEventListener('click', () => modal.close());
            modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });

            const idStrukDiminta = new URLSearchParams(window.location.search).get('struk');
            if (idStrukDiminta && /^\d+$/.test(idStrukDiminta)) {
                bukaStruk(@js(url('keuangan/payroll')) + '/' + idStrukDiminta + '/struk');
            }
        })();
    </script>
@endpush

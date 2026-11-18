@extends('layouts.app')

@section('title', 'Payroll')
@section('page-title', 'Payroll Karyawan')

@section('content')
    @php
        $rupiah = fn ($nilai): string => ((float) $nilai < 0 ? '−' : '').'Rp '.number_format(abs((float) $nilai), 0, ',', '.');
        $sudahLunas = fn (object $item): bool => $item->status_bayar === 'Sudah Lunas';
    @endphp

    <div class="callout callout-info mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="max-w-3xl">
            <p class="font-semibold">Generate payroll</p>
            <p class="mt-1">
                Payroll periode yang dipilih dihitung otomatis oleh database untuk karyawan Internal berstatus Aktif/Cuti dan karyawan Lapangan
                yang ditempatkan pada periode itu: lembur, potongan absensi, BPJS klien, tunjangan &amp; potongan pinjaman (Komponen Gaji), dan PPh 21.
                Karyawan yang sudah memiliki payroll pada periode ini dilewati tanpa diubah.
            </p>
            <p class="mt-2 text-sm">
                Gunakan <span class="font-semibold">Rincian Gaji</span> untuk melihat rincian pendapatan dan potongan setiap karyawan.
                Gunakan <span class="font-semibold">Cair</span> setelah gaji benar-benar dibayarkan.
            </p>
        </div>
        <form method="POST" action="{{ route('keuangan.payroll.generate') }}" class="w-full sm:w-auto"
              onsubmit="return confirm(@js('Generate payroll untuk '.$daftarBulan[$bulan].' '.$tahun.'?'));">
            @csrf
            <input type="hidden" name="bulan" value="{{ $bulan }}">
            <input type="hidden" name="tahun" value="{{ $tahun }}">
            <button type="submit" class="btn-primary w-full whitespace-normal text-center leading-5 sm:w-auto sm:whitespace-nowrap">
                Generate Payroll {{ $daftarBulan[$bulan] }} {{ $tahun }}
            </button>
        </form>
    </div>

    <div class="card">
        <div class="card-header flex-col items-stretch sm:flex-row sm:items-center">
            <h2 class="card-title">Slip gaji periode {{ $daftarBulan[$bulan] }} {{ $tahun }}</h2>
            <form method="GET" action="{{ route('keuangan.payroll.index') }}" class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap sm:items-center">
                <label for="bulan" class="sr-only">Bulan</label>
                <select name="bulan" id="bulan" class="form-input min-w-0 py-1.5 sm:w-auto">
                    @foreach ($daftarBulan as $angka => $nama)
                        <option value="{{ $angka }}" @selected($angka === $bulan)>{{ $nama }}</option>
                    @endforeach
                </select>
                <label for="tahun" class="sr-only">Tahun</label>
                <input type="number" name="tahun" id="tahun" value="{{ $tahun }}" min="2000" max="2100" class="form-input min-w-0 py-1.5 sm:w-24">
                <button type="submit" class="btn-secondary btn-sm col-span-2 sm:col-span-1">Tampilkan</button>
            </form>
        </div>

        <div class="hidden overflow-x-auto lg:block">
            <table class="table">
                <thead>
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
                        <th class="text-right">Total Gaji</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payroll as $item)
                        <tr>
                            <td class="font-mono text-gray-600">{{ $item->nip }}</td>
                            <td class="whitespace-nowrap">{{ $item->nama }}</td>
                            <td class="whitespace-nowrap">
                                <span @class(['badge', 'badge-info' => $item->kategori === 'Internal', 'badge-secondary' => $item->kategori !== 'Internal'])>
                                    {{ $item->jenis_pekerjaan ?? $item->kategori }}
                                </span>
                            </td>
                            <td class="text-center whitespace-nowrap tabular-nums text-gray-600">{{ $item->hari_wajib ?? '—' }} / {{ $item->hari_masuk ?? '—' }} / {{ $item->hari_absen ?? '—' }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums">{{ $rupiah($item->gaji_pokok) }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums">{{ $rupiah($item->komponen_tunjangan) }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums">{{ $rupiah($item->upah_backup) }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums">{{ $rupiah($item->total_lembur) }}</td>
                            <td class="text-right whitespace-nowrap text-red-600 tabular-nums">{{ $rupiah($item->potongan_absen) }}</td>
                            <td class="text-right whitespace-nowrap text-red-600 tabular-nums">
                                {{ $rupiah($item->potongan_bpjs) }}
                                @if ($item->bpjs_metode !== null)
                                    <p class="text-xs text-gray-400">{{ \App\Support\LabelBpjs::aturan($item->bpjs_metode, $item->bpjs_nilai) }}</p>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap text-red-600 tabular-nums">{{ $rupiah($item->potongan_pinjaman) }}</td>
                            <td class="text-right whitespace-nowrap text-red-600 tabular-nums">
                                {{ $rupiah($item->pph21) }}
                                <p class="text-xs text-gray-400">{{ (float) $item->pph21 < 0 ? 'Lebih bayar, dikembalikan' : 'PTKP '.$item->status_ptkp }}</p>
                            </td>
                            <td class="text-right font-semibold whitespace-nowrap text-gray-800 tabular-nums">{{ $rupiah($item->total_gaji) }}</td>
                            <td class="whitespace-nowrap">
                                @if ($sudahLunas($item))
                                    <span class="badge badge-success">Sudah Lunas</span>
                                    <p class="mt-0.5 text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($item->tgl_bayar)->format('d/m/Y') }}</p>
                                @else
                                    <span class="badge badge-warning">Belum Dibayar</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <div class="flex flex-wrap items-center justify-center gap-2">
                                    <button type="button" class="btn-secondary btn-sm whitespace-nowrap"
                                            data-struk-url="{{ route('keuangan.payroll.struk', $item->id) }}">Rincian Gaji</button>
                                    @unless ($sudahLunas($item))
                                        <form method="POST" action="{{ route('keuangan.payroll.cair', $item->id) }}"
                                              onsubmit="return confirm(@js('Cairkan gaji '.$item->nama.' sebesar '.$rupiah($item->total_gaji).' hari ini?'));">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn-success btn-sm whitespace-nowrap">Cair</button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="15" class="table-empty">Belum ada data payroll untuk periode {{ $daftarBulan[$bulan] }} {{ $tahun }}.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="space-y-3 p-3 lg:hidden">
            @forelse ($payroll as $item)
                <article class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
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
                        <div class="flex justify-between gap-3 border-t border-gray-100 pt-2">
                            <dt class="font-semibold text-gray-700">Total Gaji</dt>
                            <dd class="whitespace-nowrap font-bold text-gray-800 tabular-nums">{{ $rupiah($item->total_gaji) }}</dd>
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
                            <button type="button" class="btn-secondary btn-sm whitespace-nowrap"
                                    data-struk-url="{{ route('keuangan.payroll.struk', $item->id) }}">Rincian Gaji</button>
                            @unless ($sudahLunas($item))
                                <form method="POST" action="{{ route('keuangan.payroll.cair', $item->id) }}"
                                      onsubmit="return confirm(@js('Cairkan gaji '.$item->nama.' sebesar '.$rupiah($item->total_gaji).' hari ini?'));">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-success btn-sm whitespace-nowrap">Cair</button>
                                </form>
                            @endunless
                        </div>
                    </div>
                </article>
            @empty
                <p class="table-empty rounded-lg border border-gray-200">
                    Belum ada data payroll untuk periode {{ $daftarBulan[$bulan] }} {{ $tahun }}.
                </p>
            @endforelse
        </div>

        <div class="card-footer flex-col items-start sm:flex-row sm:items-center">
            <span>Menampilkan {{ $payroll->firstItem() ?? 0 }}&ndash;{{ $payroll->lastItem() ?? 0 }} dari {{ $payroll->total() }} slip gaji</span>
            @include('partials.pagination', ['paginator' => $payroll])
        </div>
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

@extends('layouts.app')

@section('title', 'Payroll')
@section('page-title', 'Payroll Karyawan')

@section('content')
    <div class="callout callout-info mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="max-w-3xl">
            <p class="font-semibold">Generate payroll</p>
            <p class="mt-1">
                Payroll dihitung otomatis oleh database untuk karyawan Internal berstatus Aktif dan karyawan Lapangan berstatus Ditempatkan,
                termasuk lembur dan potongan absensi. Karyawan yang sudah memiliki payroll pada periode ini akan dilewati.
            </p>
            <p class="mt-2 text-sm">
                Gunakan <span class="font-semibold">Lihat Struk</span> untuk memeriksa atau mencetak rincian gaji.
                Gunakan <span class="font-semibold">Tandai Cair</span> setelah gaji benar-benar dibayarkan.
            </p>
        </div>
        <form method="POST" action="{{ route('keuangan.payroll.generate') }}"
              onsubmit="return confirm(@js('Generate payroll untuk '.$daftarBulan[$bulanIni].' '.$tahunIni.'?'));">
            @csrf
            <button type="submit" class="btn-primary">
                Generate Payroll Bulan Ini ({{ $daftarBulan[$bulanIni] }} {{ $tahunIni }})
            </button>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Slip gaji periode {{ $daftarBulan[$bulan] }} {{ $tahun }}</h2>
            <form method="GET" action="{{ route('keuangan.payroll.index') }}" class="flex flex-wrap items-center gap-2">
                <label for="bulan" class="sr-only">Bulan</label>
                <select name="bulan" id="bulan" class="form-input w-auto py-1.5">
                    @foreach ($daftarBulan as $angka => $nama)
                        <option value="{{ $angka }}" @selected($angka === $bulan)>{{ $nama }}</option>
                    @endforeach
                </select>
                <label for="tahun" class="sr-only">Tahun</label>
                <input type="number" name="tahun" id="tahun" value="{{ $tahun }}" min="2000" max="2100" class="form-input w-24 py-1.5">
                <button type="submit" class="btn-secondary btn-sm">Tampilkan</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>NIP</th>
                        <th>Nama</th>
                        <th>Kategori</th>
                        <th class="text-right">Gaji Pokok</th>
                        <th class="text-right">Lembur</th>
                        <th class="text-right">Potongan Absen</th>
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
                            <td>
                                <span @class(['badge', 'badge-info' => $item->kategori === 'Internal', 'badge-secondary' => $item->kategori !== 'Internal'])>
                                    {{ $item->kategori }}
                                </span>
                            </td>
                            <td class="text-right whitespace-nowrap tabular-nums">Rp {{ number_format((float) $item->gaji_pokok, 0, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums">Rp {{ number_format((float) $item->total_lembur, 0, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap text-red-600 tabular-nums">Rp {{ number_format((float) $item->potongan_absen, 0, ',', '.') }}</td>
                            <td class="text-right font-semibold whitespace-nowrap text-gray-800 tabular-nums">Rp {{ number_format((float) $item->total_gaji, 0, ',', '.') }}</td>
                            <td class="whitespace-nowrap">
                                @if ($item->status_bayar === 'Sudah Dibayar')
                                    <span class="badge badge-success">Sudah Dibayar</span>
                                    <p class="mt-0.5 text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($item->tgl_bayar)->format('d/m/Y') }}</p>
                                @else
                                    <span class="badge badge-warning">Belum Dibayar</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <div class="flex flex-wrap items-center justify-center gap-2">
                                    <button type="button" class="btn-secondary btn-sm whitespace-nowrap"
                                            data-struk-url="{{ route('keuangan.payroll.struk', $item->id) }}">Lihat Struk</button>
                                    @if ($item->status_bayar !== 'Sudah Dibayar')
                                        <form method="POST" action="{{ route('keuangan.payroll.cair', $item->id) }}"
                                              onsubmit="return confirm(@js('Tandai gaji '.$item->nama.' sudah cair hari ini?'));">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn-success btn-sm whitespace-nowrap">Tandai Cair</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="table-empty">Belum ada data payroll untuk periode {{ $daftarBulan[$bulan] }} {{ $tahun }}.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $payroll->firstItem() ?? 0 }}&ndash;{{ $payroll->lastItem() ?? 0 }} dari {{ $payroll->total() }} slip gaji</span>
            @include('partials.pagination', ['paginator' => $payroll])
        </div>
    </div>

    <dialog id="strukModal" aria-labelledby="strukModalJudul"
            class="m-auto max-h-[92vh] w-[calc(100%-2rem)] max-w-3xl overflow-hidden rounded-xl bg-gray-100 p-0 shadow-2xl backdrop:bg-gray-900/60">
        <div class="flex max-h-[92vh] flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-white px-5 py-3">
                <h2 id="strukModalJudul" class="text-sm font-semibold text-gray-800">Slip Gaji Digital</h2>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn-primary btn-sm" data-struk-cetak disabled>Cetak / Simpan PDF</button>
                    <button type="button" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800" data-struk-tutup aria-label="Tutup struk">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="overflow-y-auto p-4 sm:p-6" id="strukModalIsi" aria-live="polite"></div>
        </div>
    </dialog>

    <div id="areaCetakStruk"></div>
@endsection

@push('scripts')
    <script>
        (() => {
            const modal = document.getElementById('strukModal');
            const isi = document.getElementById('strukModalIsi');
            const judul = document.getElementById('strukModalJudul');
            const tombolCetak = modal.querySelector('[data-struk-cetak]');
            const areaCetak = document.getElementById('areaCetakStruk');
            document.body.appendChild(areaCetak);

            const tampilkanPesan = (pesan, kelas) => {
                isi.innerHTML = '';
                const p = document.createElement('p');
                p.className = 'rounded-lg bg-white px-6 py-12 text-center text-sm ' + kelas;
                p.textContent = pesan;
                isi.appendChild(p);
            };

            const bukaStruk = async (url) => {
                judul.textContent = 'Slip Gaji Digital';
                tombolCetak.disabled = true;
                tampilkanPesan('Memuat slip gaji...', 'text-gray-500');
                if (!modal.open) modal.showModal();

                try {
                    const respons = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                        cache: 'no-store',
                    });
                    if (!respons.ok) throw new Error(String(respons.status));
                    isi.innerHTML = await respons.text();
                    judul.textContent = isi.querySelector('[data-judul]')?.dataset.judul ?? 'Slip Gaji Digital';
                    tombolCetak.disabled = false;
                } catch (galat) {
                    tampilkanPesan(galat.message === '404'
                        ? 'Slip gaji tidak ditemukan.'
                        : 'Slip gaji gagal dimuat. Silakan coba lagi.', 'text-red-600');
                }
            };

            document.querySelectorAll('[data-struk-url]').forEach((tombol) => {
                tombol.addEventListener('click', () => bukaStruk(tombol.dataset.strukUrl));
            });

            modal.querySelector('[data-struk-tutup]').addEventListener('click', () => modal.close());
            modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });

            tombolCetak.addEventListener('click', () => {
                areaCetak.innerHTML = isi.innerHTML;
                document.body.classList.add('cetak-struk');
                window.print();
            });
            window.addEventListener('afterprint', () => {
                document.body.classList.remove('cetak-struk');
                areaCetak.innerHTML = '';
            });

            const idStrukDiminta = new URLSearchParams(window.location.search).get('struk');
            if (idStrukDiminta && /^\d+$/.test(idStrukDiminta)) {
                bukaStruk(@js(url('keuangan/payroll')) + '/' + idStrukDiminta + '/struk');
            }
        })();
    </script>
@endpush

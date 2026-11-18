@extends('layouts.app')

@section('title', 'Invoice Klien')

@section('content')
    @if ($invoiceBaru)
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-lg border border-sji-biru/20 bg-blue-50 px-5 py-4">
            <div class="flex items-center gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-sji-biru text-white" aria-hidden="true">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/>
                    </svg>
                </span>
                <div>
                    <p class="text-sm font-semibold text-gray-800">Invoice digital siap dilihat</p>
                    <p class="text-sm text-gray-600">Nomor invoice <span class="font-mono font-medium">{{ $invoiceBaru->nomor_invoice }}</span></p>
                </div>
            </div>
            <button type="button" class="btn-primary" data-struk-url="{{ route('keuangan.invoice.struk', $invoiceBaru->id) }}">
                Lihat Struk
            </button>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('keuangan.invoice.generate') }}" class="card self-start">
            @csrf

            <div class="card-header">
                <h2 class="card-title">Generate Invoice</h2>
            </div>

            <div class="card-body space-y-4">
                <div>
                    <label for="id_klien" class="form-label">Klien</label>
                    <select name="id_klien" id="id_klien" @class(['form-input', 'form-input-error' => $errors->has('id_klien')]) required>
                        <option value="">&mdash; Pilih klien &mdash;</option>
                        @foreach ($klien as $item)
                            <option value="{{ $item->id }}" @selected((int) old('id_klien') === (int) $item->id)
                                    data-bpjs="{{ \App\Support\LabelBpjs::aturan($item->bpjs_metode, $item->bpjs_nilai) }}"
                                    data-bpjs-aktif="{{ $item->bpjs_metode === null ? '0' : '1' }}">
                                {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_klien') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div id="info_bpjs_klien" class="hidden rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm" aria-live="polite">
                    <p class="text-xs text-gray-500">BPJS default klien</p>
                    <p class="font-semibold text-gray-800" id="info_bpjs_aturan"></p>
                    <p class="mt-1 text-xs text-gray-500" id="info_bpjs_keterangan"></p>
                </div>

                <div class="grid grid-cols-12 gap-3">
                    <div class="col-span-7">
                        <label for="bulan" class="form-label">Bulan</label>
                        <select name="bulan" id="bulan" @class(['form-input', 'form-input-error' => $errors->has('bulan')]) required>
                            @foreach ($daftarBulan as $angka => $nama)
                                <option value="{{ $angka }}" @selected((int) old('bulan', $bulanIni) === $angka)>{{ $nama }}</option>
                            @endforeach
                        </select>
                        @error('bulan') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="col-span-5">
                        <label for="tahun" class="form-label">Tahun</label>
                        <input type="number" name="tahun" id="tahun" min="2000" max="2100" value="{{ old('tahun', $tahunIni) }}"
                               @class(['form-input', 'form-input-error' => $errors->has('tahun')]) required>
                        @error('tahun') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p class="text-xs text-gray-500">
                    Jumlah tenaga dan total tagihan dihitung otomatis oleh database dari penempatan aktif klien pada periode tersebut,
                    ditambah biaya BPJS sesuai pengaturan klien.
                </p>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn-primary w-full">Generate Invoice</button>
            </div>
        </form>

        <div class="card lg:col-span-2">
            <div class="card-header">
                <h2 class="card-title">Daftar invoice</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No. Invoice</th>
                            <th>Klien</th>
                            <th>Periode</th>
                            <th class="text-center">Tenaga</th>
                            <th class="text-right">Total Tagihan</th>
                            <th>Status</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($invoice as $item)
                            <tr>
                                <td class="font-mono whitespace-nowrap text-gray-600">{{ $item->nomor_invoice }}</td>
                                <td>{{ $item->nama_perusahaan }}</td>
                                <td class="whitespace-nowrap">{{ $daftarBulan[$item->periode_bulan] ?? $item->periode_bulan }} {{ $item->periode_tahun }}</td>
                                <td class="text-center">{{ $item->jumlah_tenaga }}</td>
                                <td class="text-right whitespace-nowrap tabular-nums">
                                    Rp {{ number_format((float) $item->total_tagihan, 0, ',', '.') }}
                                    @if ((float) $item->biaya_bpjs > 0)
                                        <p class="text-xs text-gray-400">termasuk BPJS Rp {{ number_format((float) $item->biaya_bpjs, 0, ',', '.') }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($item->status === 'Lunas')
                                        <span class="badge badge-success">Lunas</span>
                                        <p class="mt-0.5 text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($item->tgl_pembayaran)->format('d/m/Y') }}</p>
                                    @else
                                        <span class="badge badge-danger">Belum Lunas</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-center gap-2">
                                        <button type="button" class="btn-secondary btn-sm whitespace-nowrap"
                                                data-struk-url="{{ route('keuangan.invoice.struk', $item->id) }}">Lihat Struk</button>
                                        @if ($item->status !== 'Lunas')
                                            <form method="POST" action="{{ route('keuangan.invoice.lunas', $item->id) }}" class="flex items-center gap-2"
                                                  onsubmit="return confirm(@js('Tandai invoice '.$item->nomor_invoice.' sebagai lunas?'));">
                                                @csrf
                                                @method('PATCH')
                                                <label for="tgl_pembayaran_{{ $item->id }}" class="sr-only">Tanggal pembayaran</label>
                                                <input type="date" name="tgl_pembayaran" id="tgl_pembayaran_{{ $item->id }}"
                                                       value="{{ now()->toDateString() }}" class="form-input w-auto py-1.5" required>
                                                <button type="submit" class="btn-success btn-sm whitespace-nowrap">Tandai Lunas</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="table-empty">Belum ada invoice.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card-footer">
                <span>Menampilkan {{ $invoice->firstItem() ?? 0 }}&ndash;{{ $invoice->lastItem() ?? 0 }} dari {{ $invoice->total() }} invoice</span>
                @include('partials.pagination', ['paginator' => $invoice])
            </div>
        </div>
    </div>

    {{-- ===================== Modal struk invoice digital ===================== --}}
    <dialog id="strukModal" aria-labelledby="strukModalJudul"
            class="m-auto max-h-[92vh] w-[calc(100%-2rem)] max-w-3xl overflow-hidden rounded-xl bg-gray-100 p-0 shadow-2xl backdrop:bg-gray-900/60">
        <div class="flex max-h-[92vh] flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-white px-5 py-3">
                <h2 id="strukModalJudul" class="text-sm font-semibold text-gray-800">Invoice Digital</h2>
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
            // BPJS default klien terisi otomatis saat klien dipilih (hanya ditampilkan, diatur di data klien).
            const pilihKlien = document.getElementById('id_klien');
            const infoBpjs = document.getElementById('info_bpjs_klien');
            const tampilkanBpjs = () => {
                const opsi = pilihKlien.selectedOptions[0];
                infoBpjs.classList.toggle('hidden', !opsi || opsi.value === '');
                if (!opsi || opsi.value === '') return;
                const aktif = opsi.dataset.bpjsAktif === '1';
                document.getElementById('info_bpjs_aturan').textContent = opsi.dataset.bpjs;
                document.getElementById('info_bpjs_keterangan').textContent = aktif
                    ? 'Biaya BPJS invoice = total potongan BPJS karyawan klien ini pada payroll periode yang dipilih. Generate payroll periode tersebut terlebih dahulu.'
                    : 'Klien ini tidak memakai BPJS; invoice hanya berisi tagihan tenaga.';
            };
            pilihKlien.addEventListener('change', tampilkanBpjs);
            tampilkanBpjs();
        })();

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
                judul.textContent = 'Invoice Digital';
                tombolCetak.disabled = true;
                tampilkanPesan('Memuat struk invoice...', 'text-gray-500');
                if (!modal.open) modal.showModal();

                try {
                    const respons = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                        cache: 'no-store',
                    });
                    if (!respons.ok) throw new Error(String(respons.status));
                    isi.innerHTML = await respons.text();
                    judul.textContent = isi.querySelector('[data-judul]')?.dataset.judul ?? 'Invoice Digital';
                    tombolCetak.disabled = false;
                } catch (galat) {
                    tampilkanPesan(galat.message === '404'
                        ? 'Invoice tidak ditemukan.'
                        : 'Struk gagal dimuat. Silakan coba lagi.', 'text-red-600');
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
                bukaStruk(@js(url('keuangan/invoice')) + '/' + idStrukDiminta + '/struk');
            }
        })();
    </script>
@endpush

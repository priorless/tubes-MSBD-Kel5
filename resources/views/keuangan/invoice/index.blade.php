@extends('layouts.app')

@section('title', 'Invoice Klien')

@section('content')
    @include('keuangan._gaya')
    @php
        $rupiah = fn ($nilai): string => 'Rp '.number_format((float) $nilai, 0, ',', '.');
    @endphp

    <div class="keu-halaman min-w-0 space-y-6">
    <x-ui.page-header kicker="Keuangan · Tagihan klien" title="Invoice Klien"
        description="Buat invoice tagihan klien per periode, periksa dokumennya, kirim ke klien, lalu catat pelunasannya." />

    @if ($invoiceBaru)
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-sji-biru/20 bg-blue-50 px-5 py-4">
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

    {{-- Generate invoice: satu baris di atas tabel agar tabel memakai lebar penuh. --}}
    <form method="POST" action="{{ route('keuangan.invoice.generate') }}" class="keu-panel">
        @csrf

        <div class="keu-panel-kepala">
            <div>
                <h2 class="keu-judul-panel">Generate invoice baru</h2>
                <p class="keu-sub">Pastikan payroll periode yang dipilih sudah di-generate.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 items-start gap-4 px-5 py-5 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_8rem_auto]">
            <div class="sm:col-span-2 lg:col-span-1">
                <label for="id_klien" class="form-label">Klien</label>
                <select name="id_klien" id="id_klien" @class(['form-input', 'form-input-error' => $errors->has('id_klien')]) required>
                    <option value="">&mdash; Pilih klien &mdash;</option>
                    @foreach ($klien as $item)
                        <option value="{{ $item->id }}" @selected((int) old('id_klien') === (int) $item->id)
                                data-fee="{{ $item->management_fee === null ? '' : \App\Support\LabelBpjs::persen($item->management_fee) }}">
                            {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                        </option>
                    @endforeach
                </select>
                @error('id_klien') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="bulan" class="form-label">Bulan</label>
                <select name="bulan" id="bulan" @class(['form-input', 'form-input-error' => $errors->has('bulan')]) data-batas-periode="tahun" required>
                    @foreach ($daftarBulan as $angka => $nama)
                        <option value="{{ $angka }}" @selected((int) old('bulan', $bulanIni) === $angka)>{{ $nama }}</option>
                    @endforeach
                </select>
                @error('bulan') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="tahun" class="form-label">Tahun</label>
                <input type="number" name="tahun" id="tahun" min="2000" max="2100" value="{{ old('tahun', $tahunIni) }}"
                       @class(['form-input', 'form-input-error' => $errors->has('tahun')]) required>
                @error('tahun') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2 lg:col-span-1 lg:pt-[25px]">
                <button type="submit" class="btn-primary w-full lg:w-auto">Generate Invoice</button>
            </div>
        </div>

        <div class="flex flex-col gap-3 border-t border-dashed border-gray-200 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div id="info_fee_klien" class="hidden text-sm" aria-live="polite">
                <div class="flex items-center gap-3">
                <span class="rounded-md bg-sji-biru-tua px-2.5 py-1 font-mono text-sm font-semibold text-white" id="info_fee_nilai"></span>
                <span>
                    <span class="block text-xs font-semibold text-gray-700">Management fee klien (dari data klien Operasional)</span>
                    <span class="block text-xs text-gray-500" id="info_fee_keterangan"></span>
                </span>
                </div>
            </div>
            <p class="order-first text-xs leading-5 text-gray-500 lg:max-w-3xl">
                <span class="font-semibold text-gray-700">Rumus:</span>
                Sub Total = gaji pokok + tunjangan + upah back-up + lembur &minus; potongan absensi (BPJS sudah termasuk) dari payroll karyawan klien.
                Management Fee = persentase klien &times; Sub Total, PPN = 11% &times; Management Fee.
            </p>
        </div>
    </form>

    <section class="keu-panel min-w-0 overflow-hidden" aria-labelledby="judul-daftar-invoice">
        <div class="keu-panel-kepala">
            <div>
                <h2 class="keu-judul-panel" id="judul-daftar-invoice">Daftar invoice</h2>
                <p class="keu-sub">Terbaru di atas. Gunakan Lihat Struk untuk memeriksa dan mengedit dokumen sebelum dikirim.</p>
            </div>
            @include('keuangan._cari', [
                'id' => 'cari_invoice',
                'nilai' => $cari,
                'placeholder' => 'Cari kode klien, nama klien, atau no. invoice',
                'action' => route('keuangan.invoice.index'),
                'urlReset' => route('keuangan.invoice.index'),
            ])
        </div>

        @if ($cari !== '')
            <p class="keu-hasil-cari" aria-live="polite">
                <span><strong>{{ $invoice->total() }}</strong> invoice cocok dengan &ldquo;{{ $cari }}&rdquo;</span>
                <a href="{{ route('keuangan.invoice.index') }}">Tampilkan semua invoice</a>
            </p>
        @endif

        <div class="relative overflow-x-auto">
            <table class="table keu-tabel">
                <thead>
                    <tr>
                        <th>No. Invoice</th>
                        <th>Klien</th>
                        <th class="text-right">Sub Total</th>
                        <th class="text-right">Management Fee</th>
                        <th class="text-right">PPN 11%</th>
                        <th class="keu-kolom-total text-right">Grand Total</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoice as $item)
                        <tr>
                            <td class="whitespace-nowrap">
                                <span class="font-mono text-[13px] font-medium text-gray-800">{{ $item->nomor_invoice }}</span>
                                <span class="keu-ket">{{ $daftarBulan[$item->periode_bulan] ?? $item->periode_bulan }} {{ $item->periode_tahun }}</span>
                            </td>
                            <td class="min-w-44">
                                <span class="font-medium text-gray-800">{{ $item->nama_perusahaan }}</span>
                                <span class="keu-ket">{{ $item->kode_klien }} &middot; {{ $item->jumlah_tenaga }} tenaga kerja</span>
                            </td>
                            @if ((float) $item->sub_total > 0)
                                <td class="keu-angka">{{ $rupiah($item->sub_total) }}</td>
                                <td class="keu-angka">
                                    {{ $rupiah($item->management_fee) }}
                                    <span class="keu-ket">{{ \App\Support\LabelBpjs::persen($item->persen_fee) }} dari sub total</span>
                                </td>
                                <td class="keu-angka">{{ $rupiah($item->ppn) }}</td>
                            @else
                                <td colspan="3" class="text-center text-xs text-gray-400">Invoice lama (sebelum rincian payroll)</td>
                            @endif
                            <td class="keu-angka keu-kolom-total font-bold text-gray-900">{{ $rupiah($item->total_tagihan) }}</td>
                            <td class="whitespace-nowrap">
                                @if ($item->status === 'Lunas')
                                    <span class="badge badge-success">Lunas</span>
                                    <span class="keu-ket">{{ \Illuminate\Support\Carbon::parse($item->tgl_pembayaran)->format('d/m/Y') }}</span>
                                @else
                                    <span class="badge badge-danger">Belum Lunas</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex w-max flex-col gap-2">
                                    <div class="flex items-center gap-2">
                                        <button type="button" class="btn-secondary btn-sm whitespace-nowrap"
                                                data-struk-url="{{ route('keuangan.invoice.struk', $item->id) }}">Lihat Struk</button>
                                        @if ($item->status !== 'Lunas')
                                            <button type="button" class="keu-btn-garis is-lunas"
                                                    data-lunas-url="{{ route('keuangan.invoice.lunas', $item->id) }}"
                                                    data-lunas-nomor="{{ $item->nomor_invoice }}"
                                                    data-lunas-klien="{{ $item->nama_perusahaan }}"
                                                    data-lunas-total="{{ $rupiah($item->total_tagihan) }}">Tandai Lunas</button>
                                        @endif
                                    </div>
                                    @if ((float) $item->sub_total > 0)
                                        <div class="flex items-center gap-2">
                                            <button type="button" class="keu-btn-garis"
                                                    data-email-url="{{ route('keuangan.invoice.email', $item->id) }}"
                                                    data-email-id="{{ $item->id }}"
                                                    data-email-nomor="{{ $item->nomor_invoice }}"
                                                    data-email-klien="{{ $item->nama_perusahaan }}"
                                                    data-email-tujuan="{{ $item->pic_email }}"
                                                    data-email-subjek="{{ $item->email['subjek'] }}"
                                                    data-email-pesan="{{ $item->email['pesan'] }}">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                                                Kirim via Email
                                            </button>
                                            @if ($item->whatsapp !== null)
                                                @if ($item->whatsapp['url'] !== null)
                                                    <a href="{{ $item->whatsapp['url'] }}" target="_blank" rel="noopener noreferrer"
                                                       class="keu-btn-garis is-wa" data-whatsapp-kirim>
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.4A8.4 8.4 0 1 1 21 11.5Z"/></svg>
                                                        Kirim via WhatsApp
                                                    </a>
                                                @else
                                                    <button type="button" class="keu-btn-garis is-wa"
                                                            data-whatsapp-galat="{{ $item->whatsapp['galat'] }}">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.4A8.4 8.4 0 1 1 21 11.5Z"/></svg>
                                                        Kirim via WhatsApp
                                                    </button>
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="keu-kosong">
                                @if ($cari !== '')
                                    <p>Tidak ada invoice untuk &ldquo;{{ $cari }}&rdquo;</p>
                                    <p>Periksa kembali kode atau nama klien, atau coba sebagian kata saja (mis. &ldquo;Maju&rdquo;).</p>
                                @else
                                    <p>Belum ada invoice</p>
                                    <p>Pilih klien dan periode pada formulir di atas, lalu klik Generate Invoice.</p>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $invoice->firstItem() ?? 0 }}&ndash;{{ $invoice->lastItem() ?? 0 }} dari {{ $invoice->total() }} invoice</span>
            @include('partials.pagination', ['paginator' => $invoice])
        </div>
    </section>
    </div>

    {{-- ===================== Modal tandai lunas ===================== --}}
    <dialog id="lunasModal" aria-labelledby="lunasModalJudul"
            class="m-auto w-[calc(100%-2rem)] max-w-md overflow-hidden rounded-xl bg-white p-0 shadow-2xl backdrop:bg-gray-900/60">
        <form method="POST" action="#" data-lunas-form>
            @csrf
            @method('PATCH')
            <div class="border-b border-gray-200 px-5 py-4">
                <h2 id="lunasModalJudul" class="text-sm font-semibold text-gray-800">Catat pelunasan invoice</h2>
                <p class="mt-0.5 text-xs text-gray-500" data-lunas-info></p>
            </div>
            <div class="space-y-3 px-5 py-4">
                <p class="text-sm text-gray-600">Total tagihan <span class="font-semibold text-gray-900" data-lunas-total></span></p>
                <div>
                    <label for="tgl_pembayaran" class="form-label">Tanggal pembayaran diterima</label>
                    <input type="date" name="tgl_pembayaran" id="tgl_pembayaran" value="{{ now()->toDateString() }}"
                           class="form-input" required>
                </div>
                <p class="text-xs text-gray-500">Setelah ditandai lunas, dokumen invoice terkunci dan tidak dapat diedit.</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                <button type="button" class="btn-secondary" data-lunas-tutup>Batal</button>
                <button type="submit" class="btn-success">Tandai Lunas</button>
            </div>
        </form>
    </dialog>


    {{-- ===================== Modal struk invoice digital ===================== --}}
    <dialog id="strukModal" aria-labelledby="strukModalJudul"
            class="m-auto max-h-[92vh] w-[calc(100%-2rem)] max-w-3xl overflow-hidden rounded-xl bg-gray-100 p-0 shadow-2xl backdrop:bg-gray-900/60">
        <div class="flex max-h-[92vh] flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-white px-5 py-3">
                <h2 id="strukModalJudul" class="text-sm font-semibold text-gray-800">Invoice Digital</h2>
                <div class="flex items-center gap-2">
                    <a href="#" class="btn-primary btn-sm pointer-events-none opacity-50" data-struk-pdf target="_blank" rel="noopener" aria-disabled="true">Unduh PDF Tagihan</a>
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

    {{-- ===================== Modal kirim invoice via email ===================== --}}
    <dialog id="emailModal" aria-labelledby="emailModalJudul"
            class="m-auto max-h-[92vh] w-[calc(100%-2rem)] max-w-2xl overflow-hidden rounded-xl bg-white p-0 shadow-2xl backdrop:bg-gray-900/60">
        <form method="POST" action="#" class="flex max-h-[92vh] flex-col" data-email-form
              onsubmit="return confirm('Kirim invoice ini via email sekarang?');">
            @csrf
            <input type="hidden" name="id_invoice" value="">
            <input type="hidden" name="form_email" value="1">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-3">
                <div>
                    <h2 id="emailModalJudul" class="text-sm font-semibold text-gray-800">Kirim Invoice via Email</h2>
                    <p class="text-xs text-gray-500" data-email-info></p>
                </div>
                <button type="button" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800" data-email-tutup aria-label="Tutup form email">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="space-y-4 overflow-y-auto px-5 py-4">
                <div>
                    <label for="email_tujuan" class="form-label">Email tujuan</label>
                    <input type="email" name="email_tujuan" id="email_tujuan" maxlength="100" required
                           @class(['form-input', 'form-input-error' => $errors->has('email_tujuan')])>
                    <p class="form-hint">Terisi otomatis dari email PIC klien; dapat diubah.</p>
                    @error('email_tujuan') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email_cc" class="form-label">CC <span class="font-normal text-gray-400">(opsional)</span></label>
                    <input type="text" name="cc" id="email_cc" maxlength="1000" placeholder="contoh: keuangan@klien.co.id, direktur@klien.co.id"
                           @class(['form-input', 'form-input-error' => $errors->has('cc') || $errors->has('daftar_cc') || $errors->has('daftar_cc.*')])>
                    <p class="form-hint">Pisahkan beberapa alamat dengan koma. Maksimal {{ \App\Http\Requests\Keuangan\KirimInvoiceEmailRequest::MAKS_CC }} alamat.</p>
                    @foreach (array_merge($errors->get('cc'), $errors->get('daftar_cc'), ...array_values($errors->get('daftar_cc.*'))) as $pesanCc)
                        <p class="form-error">{{ $pesanCc }}</p>
                    @endforeach
                </div>
                <div>
                    <label for="email_subjek" class="form-label">Subjek</label>
                    <input type="text" name="subjek" id="email_subjek" maxlength="200" required
                           @class(['form-input', 'form-input-error' => $errors->has('subjek')])>
                    @error('subjek') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email_pesan" class="form-label">Isi pesan</label>
                    <textarea name="pesan" id="email_pesan" rows="12" maxlength="5000" required
                              @class(['form-input', 'form-input-error' => $errors->has('pesan')])></textarea>
                    @error('pesan') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                @error('id_invoice') <p class="form-error">{{ $message }}</p> @enderror
                <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
                    Lampiran: PDF tagihan (surat permohonan, invoice, dan lampiran rincian) versi terakhir yang tersimpan,
                    sama dengan tombol Unduh PDF Tagihan. Nominal diambil dari sistem.
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                <button type="button" class="btn-secondary" data-email-tutup>Batal</button>
                <button type="submit" class="btn-primary">Kirim Email</button>
            </div>
        </form>
    </dialog>
@endsection

@push('scripts')
    <script>
        (() => {
            // Management fee klien ditampilkan saat klien dipilih (hanya dibaca; diatur Operasional di data klien).
            const pilihKlien = document.getElementById('id_klien');
            const infoFee = document.getElementById('info_fee_klien');
            const tampilkanFee = () => {
                const opsi = pilihKlien.selectedOptions[0];
                infoFee.classList.toggle('hidden', !opsi || opsi.value === '');
                if (!opsi || opsi.value === '') return;
                const ada = opsi.dataset.fee !== '';
                document.getElementById('info_fee_nilai').textContent = ada ? opsi.dataset.fee : 'Belum diatur';
                document.getElementById('info_fee_keterangan').textContent = ada
                    ? 'Management Fee = ' + opsi.dataset.fee + ' x Sub Total; PPN 11% dihitung dari Management Fee.'
                    : 'Invoice tidak dapat dibuat sebelum Operasional mengisi Management Fee klien ini (0% pun boleh).';
            };
            pilihKlien.addEventListener('change', tampilkanFee);
            tampilkanFee();
        })();

        (() => {
            const modal = document.getElementById('strukModal');
            const isi = document.getElementById('strukModalIsi');
            const judul = document.getElementById('strukModalJudul');
            const tautanPdf = modal.querySelector('[data-struk-pdf]');
            const aturPdf = (url) => {
                const aktif = url !== null;
                tautanPdf.href = aktif ? url + '?format=pdf' : '#';
                tautanPdf.classList.toggle('pointer-events-none', !aktif);
                tautanPdf.classList.toggle('opacity-50', !aktif);
                tautanPdf.setAttribute('aria-disabled', aktif ? 'false' : 'true');
            };

            const tampilkanPesan = (pesan, kelas) => {
                isi.innerHTML = '';
                const p = document.createElement('p');
                p.className = 'rounded-lg bg-white px-6 py-12 text-center text-sm ' + kelas;
                p.textContent = pesan;
                isi.appendChild(p);
            };

            let urlStrukAktif = null;

            const bukaStruk = async (url) => {
                urlStrukAktif = url;
                judul.textContent = 'Invoice Digital';
                aturPdf(null);
                tampilkanPesan('Memuat invoice...', 'text-gray-500');
                if (!modal.open) modal.showModal();

                try {
                    const respons = await fetch(url, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                        cache: 'no-store',
                    });
                    if (!respons.ok) throw new Error(String(respons.status));
                    isi.innerHTML = await respons.text();
                    judul.textContent = isi.querySelector('[data-judul]')?.dataset.judul ?? 'Invoice Digital';
                    aturPdf(isi.querySelector('[data-punya-rincian="1"]') ? url : null);
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

            // Mode edit dokumen (teks surat & catatan manual). Isi modal dimuat lewat fetch, jadi event didelegasikan.
            isi.addEventListener('click', (e) => {
                const dokumen = e.target.closest('[data-dokumen]');
                if (!dokumen) return;
                const form = dokumen.querySelector('[data-mode-edit]');

                if (e.target.closest('[data-edit-mulai]')) {
                    dokumen.querySelector('[data-mode-lihat]').classList.add('hidden');
                    form.classList.remove('hidden');
                    aturPdf(null);
                    form.querySelector('input:not([type="hidden"]), textarea')?.focus();
                } else if (e.target.closest('[data-edit-batal]')) {
                    // Batal: buang semua perubahan dengan memuat ulang versi terakhir yang tersimpan.
                    if (urlStrukAktif) bukaStruk(urlStrukAktif);
                } else if (e.target.closest('[data-catatan-tambah]')) {
                    const daftar = form.querySelector('[data-catatan-daftar]');
                    const indeks = Number(daftar.dataset.catatanBerikut);
                    const baris = form.querySelector('[data-catatan-template]').content.firstElementChild.cloneNode(true);
                    baris.querySelector('[data-nama="isi"]').name = `catatan[${indeks}][isi]`;
                    daftar.dataset.catatanBerikut = String(indeks + 1);
                    daftar.appendChild(baris);
                    baris.querySelector('textarea').focus();
                } else if (e.target.closest('[data-catatan-hapus]')) {
                    e.target.closest('[data-catatan-baris]').remove();
                }
            });
            isi.addEventListener('submit', (e) => {
                if (e.target.matches('[data-mode-edit]') && !confirm('Simpan perubahan dokumen invoice ini?')) e.preventDefault();
            });


            // ===== Tandai lunas: tanggal pembayaran diisi di modal kecil, bukan di baris tabel =====
            const modalLunas = document.getElementById('lunasModal');
            const formLunas = modalLunas.querySelector('[data-lunas-form]');
            document.querySelectorAll('[data-lunas-url]').forEach((tombol) => {
                tombol.addEventListener('click', () => {
                    formLunas.action = tombol.dataset.lunasUrl;
                    modalLunas.querySelector('[data-lunas-info]').textContent = tombol.dataset.lunasNomor + ' · ' + tombol.dataset.lunasKlien;
                    modalLunas.querySelector('[data-lunas-total]').textContent = tombol.dataset.lunasTotal;
                    modalLunas.showModal();
                });
            });
            modalLunas.querySelector('[data-lunas-tutup]').addEventListener('click', () => modalLunas.close());
            modalLunas.addEventListener('click', (e) => { if (e.target === modalLunas) modalLunas.close(); });

            // ===== Kirim invoice via WhatsApp: nomor/rekening tidak valid -> beri tahu, jangan buka WhatsApp =====
            document.querySelectorAll('[data-whatsapp-galat]').forEach((tombol) => {
                tombol.addEventListener('click', () => alert(tombol.dataset.whatsappGalat));
            });

            // ===== Kirim invoice via email =====
            const modalEmail = document.getElementById('emailModal');
            const formEmail = modalEmail.querySelector('[data-email-form]');
            const bukaEmail = (tombol, isian = null) => {
                const d = tombol.dataset;
                formEmail.action = d.emailUrl;
                formEmail.elements.id_invoice.value = d.emailId;
                formEmail.elements.email_tujuan.value = isian?.email_tujuan ?? d.emailTujuan ?? '';
                formEmail.elements.cc.value = isian?.cc ?? '';
                formEmail.elements.subjek.value = isian?.subjek ?? d.emailSubjek;
                formEmail.elements.pesan.value = isian?.pesan ?? d.emailPesan;
                modalEmail.querySelector('[data-email-info]').textContent = d.emailNomor + ' · ' + d.emailKlien;
                if (!modalEmail.open) modalEmail.showModal();
            };
            document.querySelectorAll('[data-email-url]').forEach((tombol) => {
                tombol.addEventListener('click', () => {
                    // Isian lama/pesan error hanya berlaku untuk percobaan sebelumnya.
                    modalEmail.querySelectorAll('.form-error').forEach((el) => el.remove());
                    modalEmail.querySelectorAll('.form-input-error').forEach((el) => el.classList.remove('form-input-error'));
                    bukaEmail(tombol);
                });
            });
            modalEmail.querySelectorAll('[data-email-tutup]').forEach((el) => el.addEventListener('click', () => modalEmail.close()));

            @if (old('form_email') && old('id_invoice'))
                {
                    const tombolLama = document.querySelector('[data-email-id="' + @js((string) old('id_invoice')) + '"]');
                    if (tombolLama) {
                        bukaEmail(tombolLama, @js(['email_tujuan' => old('email_tujuan'), 'cc' => old('cc'), 'subjek' => old('subjek'), 'pesan' => old('pesan')]));
                    }
                }
            @endif

            const idStrukDiminta = new URLSearchParams(window.location.search).get('struk');
            if (idStrukDiminta && /^\d+$/.test(idStrukDiminta)) {
                bukaStruk(@js(url('keuangan/invoice')) + '/' + idStrukDiminta + '/struk');
            }
        })();
    </script>
@endpush

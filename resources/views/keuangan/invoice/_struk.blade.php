{{--
    Isi invoice digital untuk modal di halaman Invoice (KeuanganController::strukInvoice()).
    Nominal dibaca dari tbl_invoice / tbl_invoice_detail lewat InvoiceKlienService (tanpa hitung ulang).
    Dokumen resmi (surat permohonan, invoice, lampiran) tersedia sebagai PDF dari tombol "Unduh PDF Tagihan".
--}}
@php
    use Illuminate\Support\Carbon;

    $sudahLunas = $invoice->status === 'Lunas';
    $rupiah = fn ($nilai): string => 'Rp '.number_format((float) $nilai, 0, ',', '.');
    $tanggalIndonesia = fn ($tanggal): string => Carbon::parse($tanggal)->locale('id')->translatedFormat('d F Y');
@endphp
<article class="struk-dokumen overflow-hidden rounded-lg bg-white text-gray-800" data-judul="Invoice {{ $invoice->nomor_invoice }}"
         data-punya-rincian="{{ $punyaRincian ? '1' : '0' }}">
    {{-- ===================== Kop ===================== --}}
    <div class="flex flex-wrap items-center justify-between gap-6 bg-sji-biru-tua px-8 py-6 text-white">
        <div class="flex items-center gap-4">
            <img src="{{ asset('images/logo-sji.png') }}" alt="Logo PT Satu Jaya Indonesia" class="size-16 rounded-md bg-white object-contain p-1.5">
            <div>
                <p class="text-xl font-bold tracking-wide">PT SATU JAYA INDONESIA</p>
                <p class="text-sm text-blue-200">Penyedia Jasa Tenaga Kerja Outsourcing</p>
            </div>
        </div>
        <div class="text-right">
            <p class="text-3xl font-extrabold tracking-[0.3em] text-sji-emas">INVOICE</p>
            <p class="mt-1 font-mono text-sm text-blue-100">{{ $invoice->nomor_invoice }}</p>
        </div>
    </div>
    <div class="flex h-1.5" aria-hidden="true">
        <span class="flex-3 bg-sji-merah"></span>
        <span class="flex-1 bg-sji-emas"></span>
    </div>

    {{-- ===================== Klien & rincian dokumen ===================== --}}
    <section class="grid grid-cols-1 gap-8 px-8 py-7 sm:grid-cols-2">
        <div>
            <p class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Ditagihkan kepada</p>
            <p class="mt-2 text-lg font-bold text-gray-900">{{ $invoice->nama_perusahaan }}</p>
            @if (filled($invoice->alamat))
                <p class="mt-1 text-sm leading-relaxed text-gray-600">{{ $invoice->alamat }}</p>
            @endif
            @if (filled($invoice->pic_nama))
                <p class="mt-3 text-sm text-gray-600">
                    U.p. <span class="font-medium text-gray-800">{{ $invoice->pic_nama }}</span>@if (filled($invoice->pic_jabatan)), {{ $invoice->pic_jabatan }}@endif
                </p>
            @endif
        </div>

        <dl class="space-y-2 text-sm sm:justify-self-end sm:text-right">
            <div class="flex justify-between gap-6 sm:block">
                <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Tanggal Invoice</dt>
                <dd class="font-medium text-gray-800">{{ $tanggalSurat }}</dd>
            </div>
            <div class="flex justify-between gap-6 sm:block">
                <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Periode Tagihan</dt>
                <dd class="font-medium text-gray-800">{{ $labelPeriode }}</dd>
            </div>
            <div class="flex justify-between gap-6 sm:block">
                <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Jumlah Tenaga</dt>
                <dd class="font-medium text-gray-800">{{ $invoice->jumlah_tenaga }} orang</dd>
            </div>
            <div class="flex items-center justify-between gap-6 pt-1 sm:block">
                <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Status</dt>
                <dd class="mt-1">
                    @if ($sudahLunas)
                        <span class="inline-flex rounded-full bg-emerald-600 px-3 py-1 text-xs font-bold tracking-wider text-white">LUNAS</span>
                    @else
                        <span class="inline-flex rounded-full bg-orange-500 px-3 py-1 text-xs font-bold tracking-wider text-white">BELUM LUNAS</span>
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    {{-- ===================== Rincian tagihan ===================== --}}
    <section class="px-8">
        @if ($punyaRincian)
            <div class="overflow-x-auto rounded-md border border-gray-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold tracking-wider text-gray-500 uppercase">
                        <tr>
                            <th class="w-12 px-4 py-3 text-center">No</th>
                            <th class="px-4 py-3 text-left">Uraian</th>
                            <th class="px-4 py-3 text-right whitespace-nowrap">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($ringkasan as $baris)
                            <tr>
                                <td class="px-4 py-3 text-center text-gray-500">{{ $loop->iteration }}</td>
                                <td class="px-4 py-3 text-gray-800">{{ $baris['uraian'] }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ $rupiah($baris['nominal']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Invoice ini dibuat sebelum invoice dihitung dari payroll, sehingga belum memiliki rincian dan lampiran.
            </p>
        @endif
    </section>

    {{-- ===================== Total ===================== --}}
    <section class="grid grid-cols-1 gap-6 px-8 py-7 sm:grid-cols-2 sm:items-start">
        <div class="text-sm text-gray-600">
            <p class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Terbilang</p>
            <p class="mt-1 italic">{{ $terbilang }}</p>
            @if ($sudahLunas && filled($invoice->tgl_pembayaran))
                <p class="mt-3 font-medium text-emerald-700">Dibayar {{ $tanggalIndonesia($invoice->tgl_pembayaran) }}</p>
            @endif
        </div>

        <dl class="space-y-1.5 text-sm">
            @if ($punyaRincian)
                <div class="flex justify-between gap-6"><dt class="text-gray-600">Sub Total</dt><dd class="tabular-nums">{{ $rupiah($invoice->sub_total) }}</dd></div>
                <div class="flex justify-between gap-6">
                    <dt class="text-gray-600">Management Fee ({{ \App\Support\LabelBpjs::persen($invoice->persen_fee) }})</dt>
                    <dd class="tabular-nums">{{ $rupiah($invoice->management_fee) }}</dd>
                </div>
                <div class="flex justify-between gap-6"><dt class="text-gray-600">PPN 11% &times; Management Fee</dt><dd class="tabular-nums">{{ $rupiah($invoice->ppn) }}</dd></div>
            @endif
            <div class="flex justify-between gap-6 border-t border-gray-200 pt-2">
                <dt class="font-semibold text-gray-800">Grand Total</dt>
                <dd class="text-xl font-extrabold text-sji-biru-tua tabular-nums">{{ $rupiah($invoice->total_tagihan) }}</dd>
            </div>
        </dl>
    </section>

    {{-- ===================== Catatan tukar shift ===================== --}}
    @if ($punyaRincian)
        <section class="border-t border-gray-100 px-8 py-5 text-sm">
            <p class="font-semibold text-gray-700">Catatan pergantian jadwal (Tukar Shift disetujui, tanpa biaya tambahan)</p>
            @if ($catatanTukarShift === [])
                <p class="mt-1 text-gray-500">Tidak ada pertukaran shift pada periode ini.</p>
            @else
                <ul class="mt-1 list-inside list-disc text-gray-600">
                    @foreach ($catatanTukarShift as $catatan)
                        <li>{{ $catatan['tanggal'] }}: {{ $catatan['teks'] }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- ===================== Teks surat & catatan manual (mode edit Admin Keuangan) ===================== --}}
    @if ($punyaRincian)
        @php
            $bisaDiedit = ! $sudahLunas;
            $labelTeks = [
                'penerima' => 'Penerima surat', 'hal' => 'Perihal (Hal)', 'paragraf_pembuka' => 'Paragraf pembuka',
                'paragraf_penutup' => 'Paragraf penutup', 'salam_penutup' => 'Kalimat salam',
            ];
        @endphp
        <section class="border-t border-gray-100 px-8 py-5 text-sm" data-dokumen>
            <div data-mode-lihat>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold text-gray-700">Teks surat permohonan</p>
                        @if ($perubahanTerakhir)
                            <p class="text-xs text-gray-400">Versi {{ $versiDokumen }} &middot; diubah {{ $perubahanTerakhir['oleh'] }},
                                {{ Carbon::parse($perubahanTerakhir['pada'])->locale('id')->translatedFormat('d F Y H:i') }}</p>
                        @else
                            <p class="text-xs text-gray-400">Memakai teks bawaan.</p>
                        @endif
                    </div>
                    @if ($bisaDiedit)
                        <button type="button" class="btn-secondary btn-sm" data-edit-mulai>Edit Dokumen</button>
                    @else
                        <span class="text-xs text-gray-400">Invoice lunas &mdash; dokumen terkunci.</span>
                    @endif
                </div>
                <dl class="mt-3 space-y-2">
                    @foreach ($labelTeks as $kolom => $label)
                        <div>
                            <dt class="text-xs text-gray-400">{{ $label }}</dt>
                            <dd class="whitespace-pre-line text-gray-700">{{ $teksSurat[$kolom] }}</dd>
                        </div>
                    @endforeach
                </dl>

                <p class="mt-4 font-semibold text-gray-700">Catatan tambahan Keuangan</p>
                @if ($catatanManual === [])
                    <p class="mt-1 text-gray-500">Belum ada catatan tambahan.</p>
                @else
                    <ul class="mt-1 space-y-1 text-gray-600">
                        @foreach ($catatanManual as $catatan)
                            <li>&bull; {{ $catatan['isi'] }}
                                <span class="text-xs text-gray-400">({{ $catatan['dibuat_oleh'] }}, {{ Carbon::parse($catatan['dibuat_pada'])->format('d/m/Y H:i') }})</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($bisaDiedit)
                <form method="POST" action="{{ route('keuangan.invoice.dokumen', $invoice->id) }}" class="hidden space-y-3" data-mode-edit>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="id_invoice" value="{{ $invoice->id }}">
                    <input type="hidden" name="versi" value="{{ $versiDokumen }}">

                    <p class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800">
                        Mode edit invoice {{ $invoice->nomor_invoice }}. Hanya teks surat dan catatan tambahan yang dapat diubah;
                        seluruh nominal tetap dihitung sistem. Kosongkan isian untuk kembali ke teks bawaan.
                    </p>

                    @foreach ($labelTeks as $kolom => $label)
                        <div>
                            <label for="dokumen_{{ $kolom }}_{{ $invoice->id }}" class="form-label">{{ $label }}</label>
                            @if (str_starts_with($kolom, 'paragraf'))
                                <textarea name="{{ $kolom }}" id="dokumen_{{ $kolom }}_{{ $invoice->id }}" rows="3" maxlength="2000" class="form-input">{{ $teksSurat[$kolom] }}</textarea>
                            @else
                                <input type="text" name="{{ $kolom }}" id="dokumen_{{ $kolom }}_{{ $invoice->id }}" value="{{ $teksSurat[$kolom] }}"
                                       maxlength="{{ $kolom === 'penerima' ? 150 : ($kolom === 'hal' ? 200 : 255) }}" class="form-input">
                            @endif
                        </div>
                    @endforeach

                    <div>
                        <div class="flex items-center justify-between">
                            <p class="form-label mb-0">Catatan tambahan Keuangan</p>
                            <button type="button" class="btn-secondary btn-sm" data-catatan-tambah>Tambah catatan</button>
                        </div>
                        <p class="text-xs text-gray-400">Catatan otomatis dari Operasional (tukar shift) tidak dapat diubah di sini.</p>
                        <div class="mt-2 space-y-2" data-catatan-daftar data-catatan-berikut="{{ count($catatanManual) }}">
                            @foreach ($catatanManual as $catatan)
                                <div class="flex items-start gap-2" data-catatan-baris>
                                    <input type="hidden" name="catatan[{{ $loop->index }}][id]" value="{{ $catatan['id'] }}">
                                    <textarea name="catatan[{{ $loop->index }}][isi]" rows="2" maxlength="500" class="form-input" aria-label="Catatan tambahan">{{ $catatan['isi'] }}</textarea>
                                    <button type="button" class="btn-danger btn-sm" data-catatan-hapus>Hapus</button>
                                </div>
                            @endforeach
                        </div>
                        <template data-catatan-template>
                            <div class="flex items-start gap-2" data-catatan-baris>
                                <textarea data-nama="isi" rows="2" maxlength="500" class="form-input" aria-label="Catatan tambahan"></textarea>
                                <button type="button" class="btn-danger btn-sm" data-catatan-hapus>Hapus</button>
                            </div>
                        </template>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-gray-100 pt-3">
                        <button type="button" class="btn-secondary" data-edit-batal>Batal</button>
                        <button type="submit" class="btn-primary">Simpan Perubahan</button>
                    </div>
                </form>
            @endif
        </section>
    @endif

    <div class="border-t border-gray-200 bg-gray-50 px-8 py-5 text-center text-xs text-gray-500">
        Surat permohonan, invoice resmi, dan lampiran daftar gaji tersedia lewat tombol <span class="font-semibold">Unduh PDF Tagihan</span>.
    </div>
</article>

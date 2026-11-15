{{--
    Isi dokumen invoice digital. Dimuat ke dalam modal di halaman Invoice
    lewat KeuanganController::strukInvoice(), sehingga data dan status
    selalu dibaca terbaru dari tbl_invoice setiap kali struk dibuka.
--}}
@php
    use Illuminate\Support\Carbon;

    $sudahLunas = $invoice->status === 'Lunas';
    $rupiah = fn ($nilai): string => 'Rp '.number_format((float) $nilai, 0, ',', '.');
    $tanggalIndonesia = fn ($tanggal): string => Carbon::parse($tanggal)->locale('id')->translatedFormat('d F Y');
@endphp
<article class="struk-dokumen overflow-hidden rounded-lg bg-white text-gray-800" data-judul="Invoice {{ $invoice->nomor_invoice }}">
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
            @if (filled($invoice->pic_email) || filled($invoice->pic_telp))
                <p class="text-sm text-gray-500">
                    {{ collect([$invoice->pic_email, $invoice->pic_telp])->filter()->implode(' · ') }}
                </p>
            @endif
        </div>

        <dl class="space-y-2 text-sm sm:justify-self-end sm:text-right">
            <div class="flex justify-between gap-6 sm:block">
                <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Nomor Invoice</dt>
                <dd class="font-mono font-semibold text-gray-800">{{ $invoice->nomor_invoice }}</dd>
            </div>
            @if (filled($invoice->created_at))
                <div class="flex justify-between gap-6 sm:block">
                    <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Tanggal Invoice</dt>
                    <dd class="font-medium text-gray-800">{{ $tanggalIndonesia($invoice->created_at) }}</dd>
                </div>
            @endif
            <div class="flex justify-between gap-6 sm:block">
                <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Periode Tagihan</dt>
                <dd class="font-medium text-gray-800">{{ $labelPeriode }}</dd>
            </div>
            <div class="flex items-center justify-between gap-6 pt-1 sm:block">
                <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Status</dt>
                <dd class="mt-1">
                    @if ($sudahLunas)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-600 px-3 py-1 text-xs font-bold tracking-wider text-white">
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                            LUNAS
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-orange-500 px-3 py-1 text-xs font-bold tracking-wider text-white">
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l2.5 2.5"/></svg>
                            BELUM LUNAS
                        </span>
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    {{-- ===================== Rincian tagihan ===================== --}}
    <section class="px-8">
        <div class="overflow-x-auto rounded-md border border-gray-200">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs font-semibold tracking-wider text-gray-500 uppercase">
                    <tr>
                        <th class="w-12 px-4 py-3 text-center">No</th>
                        <th class="px-4 py-3 text-left">Deskripsi Layanan</th>
                        <th class="px-4 py-3 text-left whitespace-nowrap">Periode</th>
                        <th class="px-4 py-3 text-center whitespace-nowrap">Jumlah Tenaga</th>
                        <th class="px-4 py-3 text-right whitespace-nowrap">Jumlah</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr class="align-top">
                        <td class="px-4 py-4 text-center text-gray-500">1</td>
                        <td class="px-4 py-4">
                            <p class="font-medium text-gray-800">Jasa Penyediaan Tenaga Kerja Outsourcing</p>
                            <p class="mt-0.5 text-xs text-gray-500">Klien {{ $invoice->kode_klien }} &middot; {{ $invoice->nama_perusahaan }}</p>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-gray-700">{{ $labelPeriode }}</td>
                        <td class="px-4 py-4 text-center text-gray-700 tabular-nums">{{ $invoice->jumlah_tenaga }} orang</td>
                        <td class="px-4 py-4 text-right font-medium whitespace-nowrap text-gray-800 tabular-nums">{{ $rupiah($invoice->total_tagihan) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- ===================== Total & status pembayaran ===================== --}}
    <section class="grid grid-cols-1 gap-6 px-8 py-7 sm:grid-cols-2 sm:items-center">
        <div @class([
            'rounded-md border-l-4 px-4 py-3 text-sm',
            'border-emerald-600 bg-emerald-50 text-emerald-800' => $sudahLunas,
            'border-orange-500 bg-orange-50 text-orange-800' => ! $sudahLunas,
        ])>
            @if ($sudahLunas)
                <p class="font-semibold">Pembayaran telah diterima</p>
                @if (filled($invoice->tgl_pembayaran))
                    <p class="mt-0.5">Tanggal pembayaran: <span class="font-medium">{{ $tanggalIndonesia($invoice->tgl_pembayaran) }}</span></p>
                @endif
            @else
                <p class="font-semibold">Menunggu pembayaran</p>
                <p class="mt-0.5">Mohon lakukan pembayaran sesuai total tagihan dengan mencantumkan nomor invoice {{ $invoice->nomor_invoice }}.</p>
            @endif
        </div>

        <div class="relative sm:text-right">
            <div class="flex justify-between border-b border-gray-200 pb-2 text-sm text-gray-600 sm:justify-end sm:gap-10">
                <span>Subtotal</span>
                <span class="tabular-nums">{{ $rupiah($invoice->total_tagihan) }}</span>
            </div>
            <p class="mt-3 text-xs font-semibold tracking-widest text-gray-400 uppercase">Total Tagihan</p>
            <p class="mt-1 text-3xl font-extrabold text-sji-biru-tua tabular-nums sm:text-4xl">{{ $rupiah($invoice->total_tagihan) }}</p>

            @if ($sudahLunas)
                <div class="struk-stempel" role="img" aria-label="Stempel lunas">
                    <span class="struk-stempel-teks">LUNAS</span>
                    @if (filled($invoice->tgl_pembayaran))
                        <span class="struk-stempel-tanggal">{{ Carbon::parse($invoice->tgl_pembayaran)->format('d.m.Y') }}</span>
                    @endif
                </div>
            @endif
        </div>
    </section>

    {{-- ===================== Footer ===================== --}}
    <div class="border-t border-gray-200 bg-gray-50 px-8 py-6 text-center">
        <p class="text-sm font-semibold text-sji-biru-tua">Terima kasih atas kepercayaan Anda bekerja sama dengan kami.</p>
        <p class="mt-1 text-xs text-gray-500">
            Dokumen ini diterbitkan secara elektronik oleh sistem informasi PT Satu Jaya Indonesia dan sah tanpa tanda tangan basah.
        </p>
        <div class="mt-4 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-xs text-gray-400">
            <span class="font-semibold tracking-wide text-gray-600">PT SATU JAYA INDONESIA</span>
            <span aria-hidden="true">&middot;</span>
            <span>Penyedia Jasa Tenaga Kerja Outsourcing</span>
            <span aria-hidden="true">&middot;</span>
            <span>Dicetak {{ now()->locale('id')->translatedFormat('d F Y, H:i') }}</span>
        </div>
    </div>
</article>

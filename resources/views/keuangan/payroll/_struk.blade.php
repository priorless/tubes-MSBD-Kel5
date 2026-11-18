@php
    use Illuminate\Support\Carbon;

    $sudahDibayar = $payroll->status_bayar === 'Sudah Dibayar';
    $rupiah = fn ($nilai): string => 'Rp '.number_format((float) $nilai, 0, ',', '.');
    $tanggalIndonesia = fn ($tanggal): string => Carbon::parse($tanggal)->locale('id')->translatedFormat('d F Y');
@endphp
<article class="struk-dokumen overflow-hidden rounded-lg bg-white text-gray-800" data-judul="Slip Gaji {{ $payroll->nama }} - {{ $labelPeriode }}">
    <div class="flex flex-wrap items-center justify-between gap-6 bg-sji-biru-tua px-8 py-6 text-white">
        <div class="flex items-center gap-4">
            <img src="{{ asset('images/logo-sji.png') }}" alt="Logo PT Satu Jaya Indonesia" class="size-16 rounded-md bg-white object-contain p-1.5">
            <div>
                <p class="text-xl font-bold tracking-wide">PT SATU JAYA INDONESIA</p>
                <p class="text-sm text-blue-200">Slip pembayaran gaji karyawan</p>
            </div>
        </div>
        <div class="text-right">
            <p class="text-2xl font-extrabold tracking-widest text-sji-emas">SLIP GAJI</p>
            <p class="mt-1 text-sm text-blue-100">Periode {{ $labelPeriode }}</p>
        </div>
    </div>
    <div class="flex h-1.5" aria-hidden="true">
        <span class="flex-3 bg-sji-merah"></span>
        <span class="flex-1 bg-sji-emas"></span>
    </div>

    <section class="grid grid-cols-1 gap-4 px-8 py-6 sm:grid-cols-2">
        <div>
            <p class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Karyawan</p>
            <p class="mt-2 text-lg font-bold text-gray-900">{{ $payroll->nama }}</p>
            <p class="mt-1 font-mono text-sm text-gray-600">NIP {{ $payroll->nip }}</p>
            <p class="text-sm text-gray-600">{{ $payroll->kategori }}</p>
        </div>
        <dl class="space-y-2 text-sm sm:justify-self-end sm:text-right">
            <div class="flex justify-between gap-6 sm:block">
                <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Periode</dt>
                <dd class="font-medium text-gray-800">{{ $labelPeriode }}</dd>
            </div>
            <div class="flex items-center justify-between gap-6 sm:block">
                <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Status</dt>
                <dd class="mt-1">
                    @if ($sudahDibayar)
                        <span class="inline-flex rounded-full bg-emerald-600 px-3 py-1 text-xs font-bold tracking-wider text-white">SUDAH DIBAYAR</span>
                    @else
                        <span class="inline-flex rounded-full bg-orange-500 px-3 py-1 text-xs font-bold tracking-wider text-white">BELUM DIBAYAR</span>
                    @endif
                </dd>
            </div>
            @if ($sudahDibayar && filled($payroll->tgl_bayar))
                <div class="flex justify-between gap-6 sm:block">
                    <dt class="text-xs font-semibold tracking-widest text-gray-400 uppercase">Tanggal Dibayar</dt>
                    <dd class="font-medium text-gray-800">{{ $tanggalIndonesia($payroll->tgl_bayar) }}</dd>
                </div>
            @endif
        </dl>
    </section>

    <section class="px-8">
        <div class="overflow-x-auto rounded-md border border-gray-200">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs font-semibold tracking-wider text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-3 text-left">Rincian Gaji</th>
                        <th class="px-4 py-3 text-right">Jumlah</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @if (($payroll->hari_wajib ?? null) !== null)
                        <tr class="bg-gray-50/60">
                            <td class="px-4 py-3 text-gray-700">
                                Kehadiran
                                <span class="block text-xs text-gray-500">Hari wajib / hari masuk (Hadir, Terlambat, Sakit) / hari absen</span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                {{ $payroll->hari_wajib }} / {{ $payroll->hari_masuk ?? '—' }} / {{ $payroll->hari_absen ?? '—' }} hari
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td class="px-4 py-3 text-gray-700">Gaji Pokok</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $rupiah($payroll->gaji_pokok) }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 text-gray-700">Lembur</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $rupiah($payroll->total_lembur) }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 text-gray-700">Tunjangan</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $rupiah($payroll->komponen_tunjangan) }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 text-red-600">Potongan Absensi</td>
                        <td class="px-4 py-3 text-right text-red-600 tabular-nums">&minus; {{ $rupiah($payroll->potongan_absen) }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 text-red-600">Potongan BPJS</td>
                        <td class="px-4 py-3 text-right text-red-600 tabular-nums">&minus; {{ $rupiah($payroll->potongan_bpjs) }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 text-red-600">Potongan Pinjaman / Koperasi</td>
                        <td class="px-4 py-3 text-right text-red-600 tabular-nums">&minus; {{ $rupiah($payroll->potongan_pinjaman) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t-2 border-gray-200 bg-gray-50">
                    <tr>
                        <th class="px-4 py-4 text-left font-semibold text-gray-800">Total Gaji</th>
                        <td class="px-4 py-4 text-right text-lg font-extrabold text-sji-biru-tua tabular-nums">{{ $rupiah($payroll->total_gaji) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <footer class="mt-6 border-t border-gray-200 bg-gray-50 px-8 py-5 text-center">
        <p class="text-sm font-semibold text-sji-biru-tua">Slip gaji ini diterbitkan secara elektronik oleh sistem informasi PT Satu Jaya Indonesia.</p>
        <p class="mt-1 text-xs text-gray-500">Dicetak {{ now()->locale('id')->translatedFormat('d F Y, H:i') }}</p>
    </footer>
</article>

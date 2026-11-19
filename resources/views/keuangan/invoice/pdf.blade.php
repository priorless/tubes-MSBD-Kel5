@php
    // Dokumen tagihan klien: (1) surat permohonan, (2) invoice, (3) lampiran daftar gaji.
    // Semua nominal dibaca dari tbl_invoice / tbl_invoice_detail (InvoiceKlienService) -- tidak dihitung ulang.
    $rp = fn ($nilai): string => number_format((float) $nilai, 0, ',', '.');
    $isi = fn ($nilai, string $label): string => filled($nilai) ? (string) $nilai : '['.$label.' belum tersedia]';
    $persenFee = rtrim(rtrim(number_format((float) $invoice->persen_fee, 2, ',', '.'), '0'), ',').'%';
    $labelBpjs = $invoice->bpjs_nilai !== null ? 'BPJS '.\App\Support\LabelBpjs::persen($invoice->bpjs_nilai) : 'BPJS';
    $rekening = $perusahaan['rekening'];
    $lunas = $invoice->status === 'Lunas';
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Tagihan {{ $invoice->nomor_invoice }}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm 13mm 14mm; }
        body { color: #1f2937; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.45; }
        p { margin: 2px 0; }
        table { border-collapse: collapse; }
        .halaman { page-break-after: always; }
        .halaman-akhir { page-break-after: auto; }
        .utuh { page-break-inside: avoid; }
        .kanan { text-align: right; }
        .tengah { text-align: center; }
        .tebal { font-weight: bold; }
        .miring { font-style: italic; }
        .redup { color: #64748b; }
        .placeholder { color: #b45309; }

        /* Kop surat */
        .kop { width: 100%; border-bottom: 3px solid #123b66; margin-bottom: 2px; }
        .kop td { vertical-align: middle; padding-bottom: 6px; }
        .kop img { height: 52px; }
        .kop-nama { color: #123b66; font-size: 15px; font-weight: bold; letter-spacing: 1px; }
        .kop-rinci { color: #475569; font-size: 8.5px; }
        .kop-garis { height: 2px; background: #c49a45; margin-bottom: 12px; }

        /* Surat */
        .meta td { padding: 1px 6px 1px 0; vertical-align: top; }
        .rincian-surat { width: 100%; margin: 6px 0; }
        .rincian-surat td { padding: 2px 4px; vertical-align: top; }
        .garis-atas td { border-top: 1px solid #1f2937; }
        .rekening td { padding: 1px 8px 1px 0; }
        .ttd { width: 230px; margin-left: auto; margin-top: 14px; text-align: center; }
        .ttd-ruang { height: 62px; }
        .ttd-nama { font-weight: bold; text-decoration: underline; }

        /* Invoice */
        .judul-invoice { color: #123b66; font-size: 22px; font-weight: bold; letter-spacing: 5px; }
        .kotak { width: 100%; margin: 10px 0; }
        .kotak td { vertical-align: top; padding: 0; }
        .label-kecil { color: #64748b; font-size: 8px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .tabel-invoice { width: 100%; margin-top: 8px; }
        .tabel-invoice th { background: #123b66; color: #fff; padding: 6px 8px; font-size: 9px; text-align: left; }
        .tabel-invoice td { border-bottom: 1px solid #e2e8f0; padding: 6px 8px; vertical-align: top; }
        .ringkas { width: 300px; margin-left: auto; margin-top: 8px; }
        .ringkas td { padding: 3px 8px; }
        .grand td { background: #123b66; color: #fff; font-weight: bold; font-size: 12px; padding: 7px 8px; }
        .terbilang { border-left: 3px solid #c49a45; background: #fdf8ec; padding: 6px 10px; margin-top: 10px; }
        .status { display: inline-block; border-radius: 8px; color: #fff; font-size: 8.5px; font-weight: bold; padding: 2px 8px; }

        /* Lampiran */
        .lampiran { font-size: 6.8px; line-height: 1.3; }
        .lampiran-judul { text-align: center; font-weight: bold; margin-bottom: 6px; }
        .lampiran-judul p { margin: 1px 0; }
        .daftar { width: 100%; table-layout: fixed; }
        .daftar thead { display: table-header-group; }
        .daftar tr { page-break-inside: avoid; }
        .daftar th { border: 1px solid #334155; background: #e2e8f0; padding: 3px 2px; text-align: center; font-size: 6.5px; }
        .daftar td { border: 1px solid #94a3b8; padding: 3px 3px; vertical-align: top; }
        .daftar .kelompok td { background: #f1f5f9; font-weight: bold; text-align: center; }
        .daftar .subtotal td { background: #f8fafc; font-weight: bold; }
        .daftar .total td { background: #123b66; color: #fff; font-weight: bold; }
        .catatan { margin-top: 8px; font-size: 7.5px; }
        .catatan ol { margin: 3px 0 0 14px; padding: 0; }
    </style>
</head>
<body>
{{-- ======================= HALAMAN 1: SURAT PERMOHONAN ======================= --}}
<div class="halaman">
    @include('keuangan.invoice._kop-pdf')

    <p class="kanan">{{ $perusahaan['kota'] }}, {{ $tanggalSurat }}</p>

    <table class="meta" style="margin-top: 6px;">
        <tr><td>No</td><td>:</td><td class="tebal">{{ $nomorSurat }}</td></tr>
        <tr><td>Lamp</td><td>:</td><td>1 (satu) Berkas</td></tr>
        <tr><td>Hal</td><td>:</td><td class="tebal">Tagihan Jasa Tenaga Kerja Bulan {{ $labelPeriode }}</td></tr>
    </table>

    <p style="margin-top: 12px;">Kepada Yth,</p>
    <p class="tebal">Bapak/Ibu Pimpinan</p>
    <p class="tebal">{{ $invoice->nama_perusahaan }}</p>
    @if (filled($invoice->alamat))
        <p>{{ $invoice->alamat }}</p>
    @endif

    <p style="margin-top: 12px;">Dengan hormat,</p>
    <p>
        Bersama ini kami kirimkan 1 (satu) berkas Tagihan Jasa Tenaga Kerja {{ $invoice->nama_perusahaan }}
        Bulan {{ $labelPeriode }}, dengan perincian tagihan sebagai berikut:
    </p>

    <table class="rincian-surat">
        @foreach ($ringkasan as $baris)
            <tr>
                <td style="width: 18px;">{{ $loop->iteration }}.</td>
                <td>{{ strtoupper($baris['uraian']) }}</td>
                <td style="width: 20px;">Rp</td>
                <td class="kanan" style="width: 95px;">{{ $rp($baris['nominal']) }}</td>
            </tr>
        @endforeach
        <tr class="garis-atas">
            <td></td><td class="kanan tebal">SUB TOTAL</td><td>Rp</td><td class="kanan tebal">{{ $rp($invoice->sub_total) }}</td>
        </tr>
        <tr>
            <td></td><td class="kanan">MANAGEMENT FEE {{ $persenFee }}</td><td>Rp</td><td class="kanan">{{ $rp($invoice->management_fee) }}</td>
        </tr>
        <tr>
            <td></td><td class="kanan">PPN 11% (DARI MANAGEMENT FEE)</td><td>Rp</td><td class="kanan">{{ $rp($invoice->ppn) }}</td>
        </tr>
        <tr class="garis-atas">
            <td></td><td class="kanan tebal">TOTAL</td><td class="tebal">Rp</td><td class="kanan tebal">{{ $rp($invoice->total_tagihan) }}</td>
        </tr>
    </table>

    <p class="miring">Terbilang : {{ $terbilang }}</p>

    <p style="margin-top: 10px;">Adapun jumlah tagihan tersebut kami mohon ditransfer ke rekening kami di:</p>
    <table class="rekening" style="margin-left: 20px;">
        <tr><td>Bank</td><td>:</td><td class="tebal">{{ $isi($rekening['bank'], 'Nama bank') }}</td></tr>
        <tr><td>Alamat</td><td>:</td><td>{{ $isi($rekening['alamat'], 'Alamat bank') }}</td></tr>
        <tr><td>Nama Rekening</td><td>:</td><td>{{ $isi($rekening['nama'], 'Nama rekening') }}</td></tr>
        <tr><td>Nomor Rekening</td><td>:</td><td class="tebal">{{ $isi($rekening['nomor'], 'Nomor rekening') }}</td></tr>
    </table>

    <p style="margin-top: 10px;">Demikian tagihan ini kami sampaikan kepada Bapak/Ibu. Atas kerja samanya kami ucapkan terima kasih.</p>
    <p class="miring">Semoga Bapak/Ibu dalam keadaan sehat, sukses, dan bahagia selalu.</p>

    <div class="ttd utuh">
        <p>Hormat kami,</p>
        <p>{{ $perusahaan['nama'] }}</p>
        <div class="ttd-ruang"></div>
        <p class="ttd-nama {{ filled($perusahaan['direktur']) ? '' : 'placeholder' }}">{{ $isi($perusahaan['direktur'], 'Nama direktur') }}</p>
        <p>Direktur</p>
    </div>
</div>

{{-- ======================= HALAMAN 2: INVOICE ======================= --}}
<div class="halaman">
    @include('keuangan.invoice._kop-pdf')

    <table class="kotak">
        <tr>
            <td>
                <p class="judul-invoice">INVOICE</p>
                <p class="tebal">{{ $invoice->nomor_invoice }}</p>
            </td>
            <td class="kanan">
                <span class="status" style="background: {{ $lunas ? '#059669' : '#f97316' }};">{{ $lunas ? 'LUNAS' : 'BELUM LUNAS' }}</span>
            </td>
        </tr>
    </table>

    <table class="kotak">
        <tr>
            <td style="width: 55%;">
                <p class="label-kecil">Ditagihkan kepada</p>
                <p class="tebal" style="font-size: 11px;">{{ $invoice->nama_perusahaan }}</p>
                @if (filled($invoice->alamat)) <p>{{ $invoice->alamat }}</p> @endif
                @if (filled($invoice->pic_nama))
                    <p>U.p. {{ $invoice->pic_nama }}@if (filled($invoice->pic_jabatan)), {{ $invoice->pic_jabatan }}@endif</p>
                @endif
            </td>
            <td>
                <table style="width: 100%;">
                    <tr><td class="label-kecil">Tanggal</td><td class="kanan">{{ $tanggalSurat }}</td></tr>
                    <tr><td class="label-kecil">Periode</td><td class="kanan">{{ $rentangPeriode }}</td></tr>
                    <tr><td class="label-kecil">No. Surat</td><td class="kanan">{{ $nomorSurat }}</td></tr>
                    <tr><td class="label-kecil">Jumlah Tenaga</td><td class="kanan">{{ $invoice->jumlah_tenaga }} orang</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="tabel-invoice">
        <thead>
            <tr><th style="width: 24px;">No</th><th>Uraian</th><th class="kanan" style="width: 120px;">Jumlah (Rp)</th></tr>
        </thead>
        <tbody>
            @foreach ($ringkasan as $baris)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $baris['uraian'] }}</td>
                    <td class="kanan">{{ $rp($baris['nominal']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="ringkas utuh">
        <tr><td>Sub Total</td><td class="kanan">Rp {{ $rp($invoice->sub_total) }}</td></tr>
        <tr><td>Management Fee ({{ $persenFee }} &times; Sub Total)</td><td class="kanan">Rp {{ $rp($invoice->management_fee) }}</td></tr>
        <tr><td>PPN 11% &times; Management Fee</td><td class="kanan">Rp {{ $rp($invoice->ppn) }}</td></tr>
        <tr class="grand"><td>Grand Total</td><td class="kanan">Rp {{ $rp($invoice->total_tagihan) }}</td></tr>
    </table>

    <div class="terbilang utuh"><span class="label-kecil">Terbilang</span><br><span class="miring">{{ $terbilang }}</span></div>

    <table class="kotak utuh" style="margin-top: 14px;">
        <tr>
            <td style="width: 58%;">
                <p class="label-kecil">Pembayaran</p>
                <p>Bank: {{ $isi($rekening['bank'], 'Nama bank') }}</p>
                <p>Nama Rekening: {{ $isi($rekening['nama'], 'Nama rekening') }}</p>
                <p>Nomor Rekening: <span class="tebal">{{ $isi($rekening['nomor'], 'Nomor rekening') }}</span></p>
                <p class="redup" style="margin-top: 4px;">Sertakan nomor invoice {{ $invoice->nomor_invoice }} pada berita transfer.</p>
                <p class="redup">Biaya BPJS, pinjaman, dan PPh 21 tenaga kerja sudah termasuk dalam Sub Total (lihat lampiran).</p>
            </td>
            <td>
                <div class="ttd" style="margin-top: 0;">
                    <p>{{ $perusahaan['kota'] }}, {{ $tanggalSurat }}</p>
                    <p>{{ $perusahaan['nama'] }}</p>
                    <div class="ttd-ruang"></div>
                    <p class="ttd-nama {{ filled($perusahaan['direktur']) ? '' : 'placeholder' }}">{{ $isi($perusahaan['direktur'], 'Nama direktur') }}</p>
                    <p>Direktur</p>
                </div>
            </td>
        </tr>
    </table>
</div>

{{-- ======================= HALAMAN 3: LAMPIRAN DAFTAR GAJI ======================= --}}
<div class="halaman-akhir lampiran">
    <div class="lampiran-judul">
        <p style="font-size: 10px;">DAFTAR GAJI {{ strtoupper($perusahaan['nama']) }}</p>
        <p style="font-size: 10px; text-decoration: underline;">DI {{ strtoupper($invoice->nama_perusahaan) }}</p>
        <p style="font-size: 8px;">PERIODE: {{ strtoupper($rentangPeriode) }}</p>
        <p class="redup" style="font-weight: normal;">Lampiran surat No. {{ $nomorSurat }} &middot; Invoice {{ $invoice->nomor_invoice }}</p>
    </div>

    <table class="daftar">
        <thead>
            <tr>
                <th rowspan="2" style="width: 3%;">NO</th>
                <th rowspan="2" style="width: 13%;">NAMA STAF / KARYAWAN</th>
                <th rowspan="2" style="width: 8%;">JABATAN</th>
                <th rowspan="2" style="width: 7.5%;">GAJI POKOK</th>
                <th rowspan="2" style="width: 6.5%;">TUNJANGAN</th>
                <th rowspan="2" style="width: 6.5%;">UPAH BACK UP</th>
                <th rowspan="2" style="width: 6.5%;">UPAH LEMBUR</th>
                <th rowspan="2" style="width: 6.5%;">POT. ABSENSI</th>
                <th rowspan="2" style="width: 7.5%;">JUMLAH GAJI</th>
                <th colspan="3">POTONGAN</th>
                <th rowspan="2" style="width: 7.5%;">TOTAL PENERIMAAN</th>
                <th rowspan="2" style="width: 5%;">TANDA TANGAN</th>
            </tr>
            <tr>
                <th style="width: 6.5%;">PINJAMAN / POTONGAN</th>
                <th style="width: 6%;">{{ strtoupper($labelBpjs) }}</th>
                <th style="width: 6%;">PPH 21</th>
            </tr>
        </thead>
        <tbody>
            @php $nomor = 0; @endphp
            @foreach ($kelompok as $jabatan => $baris)
                <tr class="kelompok"><td colspan="14">{{ strtoupper($jabatan) }}</td></tr>
                @foreach ($baris as $item)
                    @php $nomor++; @endphp
                    <tr>
                        <td class="tengah">{{ $nomor }}</td>
                        <td>
                            {{ $item->nama }}
                            @if ((int) $item->hari_klien < (int) $item->hari_total)
                                <br><span class="redup">prorata {{ $item->hari_klien }}/{{ $item->hari_total }} hari</span>
                            @endif
                        </td>
                        <td>{{ strtoupper($item->jabatan) }}</td>
                        <td class="kanan">{{ $rp($item->gaji_pokok) }}</td>
                        <td class="kanan">{{ (float) $item->tunjangan > 0 ? $rp($item->tunjangan) : '-' }}</td>
                        <td class="kanan">{{ (float) $item->upah_backup > 0 ? $rp($item->upah_backup) : '-' }}</td>
                        <td class="kanan">{{ (float) $item->upah_lembur > 0 ? $rp($item->upah_lembur) : '-' }}</td>
                        <td class="kanan">{{ (float) $item->potongan_absen > 0 ? $rp($item->potongan_absen) : '-' }}</td>
                        <td class="kanan tebal">{{ $rp($item->biaya_tenaga_kerja) }}</td>
                        <td class="kanan">{{ (float) $item->potongan_pinjaman > 0 ? $rp($item->potongan_pinjaman) : '-' }}</td>
                        <td class="kanan">{{ (float) $item->potongan_bpjs > 0 ? $rp($item->potongan_bpjs) : '-' }}</td>
                        <td class="kanan">{{ (float) $item->pph21 != 0 ? $rp($item->pph21) : '-' }}</td>
                        <td class="kanan tebal">{{ $rp($item->total_penerimaan) }}</td>
                        <td style="{{ $nomor % 2 === 0 ? 'text-align: right;' : '' }}">{{ $nomor }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal">
                    <td colspan="8" class="kanan">JUMLAH {{ strtoupper($jabatan) }}</td>
                    <td class="kanan">{{ $rp($baris->sum('biaya_tenaga_kerja')) }}</td>
                    <td colspan="3"></td>
                    <td class="kanan">{{ $rp($baris->sum('total_penerimaan')) }}</td>
                    <td></td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3" class="kanan">TOTAL</td>
                <td class="kanan">{{ $rp($totalLampiran['gaji_pokok']) }}</td>
                <td class="kanan">{{ $rp($totalLampiran['tunjangan']) }}</td>
                <td class="kanan">{{ $rp($totalLampiran['upah_backup']) }}</td>
                <td class="kanan">{{ $rp($totalLampiran['upah_lembur']) }}</td>
                <td class="kanan">{{ $rp($totalLampiran['potongan_absen']) }}</td>
                <td class="kanan">{{ $rp($totalLampiran['biaya_tenaga_kerja']) }}</td>
                <td class="kanan">{{ $rp($totalLampiran['potongan_pinjaman']) }}</td>
                <td class="kanan">{{ $rp($totalLampiran['potongan_bpjs']) }}</td>
                <td class="kanan">{{ $rp($totalLampiran['pph21']) }}</td>
                <td class="kanan">{{ $rp($totalLampiran['total_penerimaan']) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="catatan utuh">
        <p>Jumlah Gaji = Gaji Pokok + Tunjangan + Upah Back Up + Upah Lembur &minus; Potongan Absensi. Total Jumlah Gaji
            (Rp {{ $rp($totalLampiran['biaya_tenaga_kerja']) }}) = Sub Total tagihan.</p>
        <p class="tebal" style="margin-top: 4px;">Catatan pergantian jadwal (Tukar Shift yang disetujui Operasional &mdash; tanpa biaya tambahan):</p>
        @if ($catatanTukarShift === [])
            <p>Tidak ada pertukaran shift pada periode ini.</p>
        @else
            <ol>
                @foreach ($catatanTukarShift as $catatan)
                    <li>{{ $catatan['tanggal'] }}: {{ $catatan['teks'] }}</li>
                @endforeach
            </ol>
        @endif
    </div>
</div>
</body>
</html>

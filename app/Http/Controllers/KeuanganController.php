<?php

namespace App\Http\Controllers;

use App\Http\Requests\Keuangan\UpdateInvoiceDokumenRequest;
use App\Services\InvoiceKlienService;
use App\Support\DokumenInvoiceDitolak;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Modul Keuangan: seluruh logika bisnis (perhitungan payroll dan invoice)
 * berada di database. Controller ini hanya memanggil stored procedure dan membaca view/tabel.
 */
class KeuanganController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const DAFTAR_BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function payroll(Request $request): View
    {
        $periode = $this->periodeDariRequest($request);

        return view('keuangan.payroll.index', [
            'payroll' => DB::table('v_slip_gaji_detail')
                ->where('periode_bulan', $periode['bulan'])
                ->where('periode_tahun', $periode['tahun'])
                ->orderBy('status_bayar')
                ->orderBy('nama')
                ->paginate(15)
                ->withQueryString(),
            'bulan' => $periode['bulan'],
            'tahun' => $periode['tahun'],
            'daftarBulan' => self::DAFTAR_BULAN,
            'bulanIni' => now()->month,
            'tahunIni' => now()->year,
        ]);
    }

    /**
     * Generate payroll untuk periode yang dipilih. Seluruh perhitungan ada di sp_generate_payroll_bulanan
     * (sql/Komponen/16): karyawan yang sudah punya payroll periode itu dilewati tanpa ditimpa, dan
     * unique key (id_karyawan, periode) mencegah payroll ganda walau tombol diklik berulang.
     */
    public function generatePayroll(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bulan' => ['required', 'integer', 'between:1,12'],
            'tahun' => ['required', 'integer', 'between:2000,2100'],
        ], $this->pesanValidasi());

        $bulan = (int) $validated['bulan'];
        $tahun = (int) $validated['tahun'];
        $sudahAda = $this->jumlahPayroll($bulan, $tahun);

        $gagal = $this->jalankanProsedur('CALL sp_generate_payroll_bulanan(?, ?)', [$bulan, $tahun]);

        if ($gagal !== null) {
            return $gagal;
        }

        $dibuat = $this->jumlahPayroll($bulan, $tahun) - $sudahAda;

        return redirect()
            ->route('keuangan.payroll.index', ['bulan' => $bulan, 'tahun' => $tahun])
            ->with('success', sprintf(
                'Generate payroll %s %d selesai: %d payroll baru dibuat, %d dilewati karena sudah ada.',
                self::DAFTAR_BULAN[$bulan], $tahun, $dibuat, $sudahAda,
            ));
    }

    /**
     * Pencairan: status 'Belum Dibayar' -> 'Sudah Lunas' + tanggal hari ini (sp_update_status_bayar).
     * Pencairan kedua ditolak database. Tidak ada pencatatan Mutasi Kas.
     */
    public function tandaiPayrollCair(int $id): RedirectResponse
    {
        abort_unless(DB::table('tbl_payroll')->where('id', $id)->exists(), 404);

        $gagal = $this->jalankanProsedur('CALL sp_update_status_bayar(?, ?, ?)', [
            'PAYROLL',
            $id,
            now()->toDateString(),
        ]);

        return $gagal ?? back()->with('success', 'Gaji berhasil dicairkan. Status payroll: Sudah Lunas.');
    }

    /**
     * Rincian gaji (hanya dilihat, tidak dicetak) dari data payroll tersimpan (v_slip_gaji_detail), tanpa hitung ulang.
     * AJAX -> HTML untuk modal; selain itu kembali ke halaman payroll dengan modal terbuka.
     */
    public function strukPayroll(Request $request, int $id): View|RedirectResponse
    {
        if (! $request->ajax()) {
            return redirect()->route('keuangan.payroll.index', ['struk' => $id]);
        }

        $payroll = DB::table('v_slip_gaji_detail')
            ->where('id', $id)
            ->first([
                'id',
                'nip',
                'nama',
                'kategori',
                'jenis_pekerjaan',
                'status_ptkp',
                'periode_bulan',
                'periode_tahun',
                'gaji_pokok',
                'komponen_tunjangan',
                'upah_backup',
                'total_lembur',
                'potongan_absen',
                'potongan_bpjs',
                'potongan_pinjaman',
                'pph21',
                'total_gaji',
                'status_bayar',
                'tgl_bayar',
                'hari_wajib',
                'hari_masuk',
                'hari_absen',
                'bpjs_metode',
                'bpjs_nilai',
                'nama_klien_bpjs',
            ]);

        abort_if($payroll === null, 404);

        return view('keuangan.payroll._struk', [
            'payroll' => $payroll,
            'labelPeriode' => (self::DAFTAR_BULAN[(int) $payroll->periode_bulan] ?? $payroll->periode_bulan).' '.$payroll->periode_tahun,
        ]);
    }

    private function jumlahPayroll(int $bulan, int $tahun): int
    {
        return DB::table('tbl_payroll')->where('periode_bulan', $bulan)->where('periode_tahun', $tahun)->count();
    }

    public function invoice(): View
    {
        return view('keuangan.invoice.index', [
            'invoice' => DB::table('tbl_invoice as i')
                ->join('tbl_klien as k', 'k.id', '=', 'i.id_klien')
                ->orderByDesc('i.periode_tahun')
                ->orderByDesc('i.periode_bulan')
                ->orderBy('k.nama_perusahaan')
                ->select([
                    'i.id',
                    'i.nomor_invoice',
                    'i.periode_bulan',
                    'i.periode_tahun',
                    'i.jumlah_tenaga',
                    'i.sub_total',
                    'i.persen_fee',
                    'i.management_fee',
                    'i.ppn',
                    'i.total_tagihan',
                    'i.status',
                    'i.tgl_pembayaran',
                    'k.kode_klien',
                    'k.nama_perusahaan',
                ])
                ->paginate(15),
            'invoiceBaru' => session()->has('invoice_baru')
                ? DB::table('tbl_invoice')->where('id', session('invoice_baru'))->first(['id', 'nomor_invoice'])
                : null,
            'klien' => DB::table('tbl_klien')
                ->where('status', 'Aktif')
                ->orderBy('nama_perusahaan')
                // Management fee ditampilkan (tidak dapat diubah; diatur Operasional) saat Keuangan memilih klien.
                ->get(['id', 'kode_klien', 'nama_perusahaan', 'management_fee']),
            'daftarBulan' => self::DAFTAR_BULAN,
            'bulanIni' => now()->month,
            'tahunIni' => now()->year,
        ]);
    }

    public function generateInvoice(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id_klien' => ['bail', 'required', 'integer', 'exists:tbl_klien,id'],
            'bulan' => ['bail', 'required', 'integer', 'between:1,12'],
            'tahun' => ['bail', 'required', 'integer', 'between:2000,2100'],
        ], $this->pesanValidasi());

        $gagal = $this->jalankanProsedur('CALL sp_generate_invoice_klien(?, ?, ?)', [
            $validated['id_klien'],
            $validated['bulan'],
            $validated['tahun'],
        ]);

        if ($gagal !== null) {
            return $gagal;
        }

        $idInvoiceBaru = DB::table('tbl_invoice')
            ->where('id_klien', $validated['id_klien'])
            ->where('periode_bulan', $validated['bulan'])
            ->where('periode_tahun', $validated['tahun'])
            ->value('id');

        return redirect()
            ->route('keuangan.invoice.index')
            ->with('success', 'Invoice periode '.self::DAFTAR_BULAN[(int) $validated['bulan']].' '.$validated['tahun'].' berhasil dibuat.')
            ->with('invoice_baru', $idInvoiceBaru);
    }

    /**
     * Invoice digital untuk modal (AJAX), atau dokumen PDF tagihan klien (?format=pdf): surat permohonan,
     * invoice, dan lampiran daftar gaji. Semua nominal dibaca dari tbl_invoice & tbl_invoice_detail (tanpa hitung ulang).
     * Akses langsung lain diarahkan ke halaman Invoice yang membuka modal tersebut.
     */
    public function strukInvoice(Request $request, int $id, InvoiceKlienService $layanan): View|RedirectResponse|Response
    {
        $unduhPdf = $request->query('format') === 'pdf';

        if (! $unduhPdf && ! $request->ajax()) {
            return redirect()->route('keuangan.invoice.index', ['struk' => $id]);
        }

        $dokumen = $layanan->dokumen($id);
        abort_if($dokumen === null, 404);

        if (! $unduhPdf) {
            return view('keuangan.invoice._struk', $dokumen);
        }

        if (! $dokumen['punyaRincian']) {
            return redirect()->route('keuangan.invoice.index')
                ->with('error', 'Invoice '.$dokumen['invoice']->nomor_invoice.' dibuat sebelum invoice dihitung dari payroll, sehingga belum memiliki lampiran rincian gaji.');
        }

        return Pdf::loadView('keuangan.invoice.pdf', $dokumen)
            ->setPaper('a4')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->stream(sprintf('tagihan-%s.pdf', $dokumen['invoice']->nomor_invoice));
    }

    /**
     * Simpan mode edit dokumen tagihan (teks surat & catatan manual) untuk invoice {id} saja.
     * Nominal tidak diterima dari form; versi mencegah simpanan bersamaan saling menimpa.
     */
    public function simpanDokumenInvoice(UpdateInvoiceDokumenRequest $request, int $id, InvoiceKlienService $layanan): RedirectResponse
    {
        abort_unless(DB::table('tbl_invoice')->where('id', $id)->exists(), 404);

        try {
            $berubah = $layanan->simpanDokumen($id, $request->validated(), (int) $request->user()->getAuthIdentifier());
        } catch (DokumenInvoiceDitolak $exception) {
            return redirect()->route('keuangan.invoice.index')->with('error', $exception->getMessage());
        }

        return redirect()->route('keuangan.invoice.index', ['struk' => $id])
            ->with('success', $berubah ? 'Perubahan dokumen invoice berhasil disimpan.' : 'Tidak ada perubahan pada dokumen invoice.');
    }

    public function tandaiInvoiceLunas(Request $request, int $id): RedirectResponse
    {
        abort_unless(DB::table('tbl_invoice')->where('id', $id)->exists(), 404);

        $validated = $request->validate([
            'tgl_pembayaran' => ['bail', 'required', 'date'],
        ], $this->pesanValidasi());

        $gagal = $this->jalankanProsedur('CALL sp_update_status_bayar(?, ?, ?)', [
            'INVOICE',
            $id,
            $validated['tgl_pembayaran'],
        ]);

        return $gagal ?? back()->with('success', 'Invoice berhasil ditandai lunas.');
    }

    /**
     * @return array{bulan: int, tahun: int}
     */
    private function periodeDariRequest(Request $request): array
    {
        $bulan = (int) $request->query('bulan', (string) now()->month);
        $tahun = (int) $request->query('tahun', (string) now()->year);

        return [
            'bulan' => $bulan >= 1 && $bulan <= 12 ? $bulan : now()->month,
            'tahun' => $tahun >= 2000 && $tahun <= 2100 ? $tahun : now()->year,
        ];
    }

    /**
     * Memanggil stored procedure. Mengembalikan redirect berisi notifikasi
     * bila database menolak (SQLSTATE 45000), atau null bila berhasil.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function jalankanProsedur(string $sql, array $bindings): ?RedirectResponse
    {
        try {
            DB::statement($sql, $bindings);
        } catch (QueryException $exception) {
            $message = $this->signalMessage($exception);

            if ($message === null) {
                throw $exception;
            }

            return back()->withInput()->with('error', $message);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function pesanValidasi(): array
    {
        return [
            'id_klien.required' => 'Klien wajib dipilih.',
            'id_klien.integer' => 'Pilihan klien tidak valid.',
            'id_klien.exists' => 'Klien yang dipilih tidak ditemukan.',
            'bulan.required' => 'Bulan wajib dipilih.',
            'bulan.integer' => 'Bulan tidak valid.',
            'bulan.between' => 'Bulan harus antara 1 sampai 12.',
            'tahun.required' => 'Tahun wajib diisi.',
            'tahun.integer' => 'Tahun tidak valid.',
            'tahun.between' => 'Tahun harus antara 2000 sampai 2100.',
            'tgl_pembayaran.required' => 'Tanggal pembayaran wajib diisi.',
            'tgl_pembayaran.date' => 'Tanggal pembayaran tidak valid.',
        ];
    }

    private function signalMessage(QueryException $exception): ?string
    {
        $errorInfo = $exception->errorInfo ?? $exception->getPrevious()?->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? $exception->getCode());

        if ($sqlState !== '45000') {
            return null;
        }

        $databaseMessage = (string) ($errorInfo[2] ?? $exception->getPrevious()?->getMessage() ?? '');
        $databaseMessage = trim((string) preg_replace('/^\d+\s+/', '', $databaseMessage));

        return $databaseMessage === ''
            ? 'Operasi ditolak oleh aturan database.'
            : 'Operasi ditolak oleh aturan database: '.$databaseMessage;
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function generatePayroll(): RedirectResponse
    {
        $bulan = now()->month;
        $tahun = now()->year;

        $gagal = $this->jalankanProsedur('CALL sp_generate_payroll_bulanan(?, ?)', [$bulan, $tahun]);

        if ($gagal !== null) {
            return $gagal;
        }

        return redirect()
            ->route('keuangan.payroll.index', ['bulan' => $bulan, 'tahun' => $tahun])
            ->with('success', 'Payroll periode '.self::DAFTAR_BULAN[$bulan].' '.$tahun.' berhasil di-generate.');
    }

    public function tandaiPayrollCair(int $id): RedirectResponse
    {
        abort_unless(DB::table('tbl_payroll')->where('id', $id)->exists(), 404);

        $gagal = $this->jalankanProsedur('CALL sp_update_status_bayar(?, ?, ?)', [
            'PAYROLL',
            $id,
            now()->toDateString(),
        ]);

        return $gagal ?? back()->with('success', 'Status payroll berhasil ditandai cair.');
    }

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
                'periode_bulan',
                'periode_tahun',
                'gaji_pokok',
                'total_lembur',
                'potongan_absen',
                'total_gaji',
                'status_bayar',
                'tgl_bayar',
                'hari_wajib',
                'hari_masuk',
                'hari_absen',
            ]);

        abort_if($payroll === null, 404);

        return view('keuangan.payroll._struk', [
            'payroll' => $payroll,
            'labelPeriode' => (self::DAFTAR_BULAN[(int) $payroll->periode_bulan] ?? $payroll->periode_bulan).' '.$payroll->periode_tahun,
        ]);
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
                ->get(['id', 'kode_klien', 'nama_perusahaan']),
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
     * Isi struk invoice digital untuk modal di halaman Invoice. Data dan
     * status selalu dibaca langsung dari tbl_invoice sehingga mengikuti
     * kondisi terbaru. Akses langsung (bukan fetch) diarahkan ke halaman
     * Invoice yang akan membuka modal struk tersebut.
     */
    public function strukInvoice(Request $request, int $id): View|RedirectResponse
    {
        if (! $request->ajax()) {
            return redirect()->route('keuangan.invoice.index', ['struk' => $id]);
        }

        $invoice = DB::table('tbl_invoice as i')
            ->join('tbl_klien as k', 'k.id', '=', 'i.id_klien')
            ->where('i.id', $id)
            ->first([
                'i.id',
                'i.nomor_invoice',
                'i.periode_bulan',
                'i.periode_tahun',
                'i.jumlah_tenaga',
                'i.total_tagihan',
                'i.status',
                'i.tgl_pembayaran',
                'i.created_at',
                'k.kode_klien',
                'k.nama_perusahaan',
                'k.alamat',
                'k.pic_nama',
                'k.pic_jabatan',
                'k.pic_telp',
                'k.pic_email',
            ]);

        abort_if($invoice === null, 404);

        return view('keuangan.invoice._struk', [
            'invoice' => $invoice,
            'labelPeriode' => (self::DAFTAR_BULAN[(int) $invoice->periode_bulan] ?? $invoice->periode_bulan).' '.$invoice->periode_tahun,
        ]);
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

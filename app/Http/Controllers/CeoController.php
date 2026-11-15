<?php

namespace App\Http\Controllers;

use App\Services\DatabaseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CeoController extends Controller
{
    private const int JUMLAH_BULAN_GRAFIK = 12;

    private const int JUMLAH_BULAN_TREN = 6;

    public function dashboard(): View
    {
        $periode = Carbon::now()->startOfMonth();
        $error = null;
        $ringkasan = null;
        $arusKas = collect();
        $profitKlien = collect();

        try {
            $ringkasan = DatabaseService::getDashboardEksekutif();
            $arusKas = DatabaseService::getArusKasBulanan(self::JUMLAH_BULAN_GRAFIK);
            $profitKlien = DatabaseService::getProfitSemuaKlien($periode->month, $periode->year);
        } catch (QueryException $exception) {
            report($exception);
            $error = DatabaseService::pesanError($exception);
        }

        $kpi = [
            ['label' => 'Lapangan Bertugas', 'nilai' => (int) ($ringkasan->lapangan_bertugas ?? 0), 'uang' => false, 'ikon' => 'fa-user-shield', 'warna' => 'info'],
            ['label' => 'Lapangan Siap', 'nilai' => (int) ($ringkasan->lapangan_siap ?? 0), 'uang' => false, 'ikon' => 'fa-user-check', 'warna' => 'success'],
            ['label' => 'Internal Aktif', 'nilai' => (int) ($ringkasan->internal_aktif ?? 0), 'uang' => false, 'ikon' => 'fa-user-tie', 'warna' => 'primary'],
            ['label' => 'Total Piutang', 'nilai' => (float) ($ringkasan->total_piutang ?? 0), 'uang' => true, 'ikon' => 'fa-file-invoice-dollar', 'warna' => 'warning'],
            ['label' => 'Profit Bulan Berjalan', 'nilai' => (float) ($ringkasan->profit_bulan_berjalan ?? 0), 'uang' => true, 'ikon' => 'fa-chart-line', 'warna' => 'danger'],
        ];

        return view('ceo.dashboard', [
            'kpi' => $kpi,
            'chartLabels' => $arusKas->map(fn (object $baris) => $this->labelPeriode((int) $baris->bulan, (int) $baris->tahun))->all(),
            'chartPemasukan' => $arusKas->map(fn (object $baris) => (float) $baris->total_masuk)->all(),
            'chartPengeluaran' => $arusKas->map(fn (object $baris) => (float) $baris->total_keluar)->all(),
            'profitKlien' => $profitKlien,
            'totalProfitKlien' => $profitKlien->sum(fn (object $klien) => (float) $klien->profit),
            'labelPeriode' => $periode->translatedFormat('F Y'),
            'error' => $error,
        ]);
    }

    public function profitKlien(Request $request): View
    {
        $validated = $request->validate([
            'id_klien' => ['nullable', 'integer', 'exists:tbl_klien,id'],
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'tahun' => ['nullable', 'integer', 'between:2000,2100'],
        ], [
            'id_klien.exists' => 'Klien tidak ditemukan.',
            'bulan.between' => 'Bulan harus antara 1 sampai 12.',
            'tahun.between' => 'Tahun tidak valid.',
        ]);

        $klienList = DB::table('tbl_klien')->where('status', 'Aktif')->orderBy('nama_perusahaan')->get();
        $selectedKlienId = isset($validated['id_klien']) ? (int) $validated['id_klien'] : null;
        $selectedBulan = (int) ($validated['bulan'] ?? now()->month);
        $selectedTahun = (int) ($validated['tahun'] ?? now()->year);
        $profitResult = null;
        $trenLabels = [];
        $trenProfit = [];
        $error = null;

        if ($selectedKlienId !== null) {
            try {
                $profitResult = DatabaseService::getProfitKlien($selectedKlienId, $selectedBulan, $selectedTahun);

                // Tren beberapa bulan terakhir: tiap titik tetap dihitung oleh fn_profit_klien di database.
                $akhir = Carbon::create($selectedTahun, $selectedBulan, 1);
                for ($mundur = self::JUMLAH_BULAN_TREN - 1; $mundur >= 0; $mundur--) {
                    $titik = $akhir->copy()->subMonthsNoOverflow($mundur);
                    $trenLabels[] = $this->labelPeriode($titik->month, $titik->year);
                    $trenProfit[] = DatabaseService::getProfitKlien($selectedKlienId, $titik->month, $titik->year);
                }
            } catch (QueryException $exception) {
                report($exception);
                $error = DatabaseService::pesanError($exception);
                $profitResult = null;
            }
        }

        return view('ceo.profit_klien', compact(
            'klienList', 'selectedKlienId', 'selectedBulan', 'selectedTahun', 'profitResult', 'trenLabels', 'trenProfit', 'error',
        ));
    }

    private function labelPeriode(int $bulan, int $tahun): string
    {
        return Carbon::create($tahun, $bulan, 1)->translatedFormat('M Y');
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\DatabaseService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class CeoController extends Controller
{
    private const int JUMLAH_BULAN_GRAFIK = 12;

    public function dashboard(): View
    {
        $periode = Carbon::now()->startOfMonth();
        $error = null;
        $ringkasan = null;
        $arusKas = collect();

        try {
            $ringkasan = DatabaseService::getDashboardEksekutif();
            $arusKas = DatabaseService::getArusKasBulanan(self::JUMLAH_BULAN_GRAFIK);
        } catch (QueryException $exception) {
            report($exception);
            $error = DatabaseService::pesanError($exception);
        }

        $kpi = [
            ['label' => 'Lapangan Bertugas', 'nilai' => (int) ($ringkasan->lapangan_bertugas ?? 0), 'uang' => false, 'ikon' => 'fa-user-shield', 'warna' => 'info'],
            ['label' => 'Lapangan Siap', 'nilai' => (int) ($ringkasan->lapangan_siap ?? 0), 'uang' => false, 'ikon' => 'fa-user-check', 'warna' => 'success'],
            ['label' => 'Internal Aktif', 'nilai' => (int) ($ringkasan->internal_aktif ?? 0), 'uang' => false, 'ikon' => 'fa-user-tie', 'warna' => 'primary'],
            ['label' => 'Total Piutang', 'nilai' => (float) ($ringkasan->total_piutang ?? 0), 'uang' => true, 'ikon' => 'fa-file-invoice-dollar', 'warna' => 'warning'],
        ];

        return view('ceo.dashboard', [
            'kpi' => $kpi,
            'chartLabels' => $arusKas->map(fn (object $baris) => $this->labelPeriode((int) $baris->bulan, (int) $baris->tahun))->all(),
            'chartPemasukan' => $arusKas->map(fn (object $baris) => (float) $baris->total_masuk)->all(),
            'chartPengeluaran' => $arusKas->map(fn (object $baris) => (float) $baris->total_keluar)->all(),
            'labelPeriode' => $periode->translatedFormat('F Y'),
            'error' => $error,
        ]);
    }

    private function labelPeriode(int $bulan, int $tahun): string
    {
        return Carbon::create($tahun, $bulan, 1)->translatedFormat('M Y');
    }
}

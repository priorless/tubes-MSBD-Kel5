<?php

namespace App\Http\Controllers;

use App\Services\DatabaseService;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CeoController extends Controller
{
    private const NAMA_BULAN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** Ubah error database menjadi pesan notifikasi (SQLSTATE 45000 = pesan dari SIGNAL di database). */
    private function pesanError(QueryException $e): string
    {
        if ((string) $e->getCode() === '45000') {
            // Ambil teks MESSAGE_TEXT dari SIGNAL, buang prefix "SQLSTATE[45000]: ... 1644"
            return preg_replace('/^.*?1644\s+/s', '', explode(' (Connection:', $e->getMessage())[0]);
        }
        report($e);
        return 'Terjadi kesalahan pada database. Silakan coba lagi.';
    }

    public function dashboard()
    {
        try {
            $dashboardData = DatabaseService::getDashboardEksekutif();
            $arusKasRaw = DatabaseService::getArusKasBulanan();
        } catch (QueryException $e) {
            return view('ceo.dashboard', [
                'kpi' => [], 'chartLabels' => [], 'chartPemasukan' => [], 'chartPengeluaran' => [],
            ])->with('error', $this->pesanError($e));
        }

        // Kartu KPI dibuat dari kolom apa pun yang dikembalikan view (hanya format tampilan, bukan logika bisnis)
        $baris = is_iterable($dashboardData) && !($dashboardData instanceof \stdClass) ? ($dashboardData[0] ?? null) : $dashboardData;
        $kpi = [];
        foreach ((array) $baris as $kolom => $nilai) {
            $uang = preg_match('/(total|pendapatan|pemasukan|pengeluaran|profit|laba|biaya|gaji|invoice|saldo|kas)/i', $kolom);
            $kpi[] = [
                'label' => ucwords(str_replace('_', ' ', $kolom)),
                'nilai' => is_numeric($nilai) ? (float) $nilai : $nilai,
                'uang'  => (bool) $uang && is_numeric($nilai),
                'angka' => is_numeric($nilai),
            ];
        }

        $chartLabels = $chartPemasukan = $chartPengeluaran = [];
        foreach ($arusKasRaw as $row) {
            $bulan = $row->bulan ?? $row->periode_bulan ?? '';
            $bulan = is_numeric($bulan) ? (self::NAMA_BULAN[(int) $bulan] ?? $bulan) : $bulan;
            $chartLabels[] = trim($bulan . ' ' . ($row->tahun ?? $row->periode_tahun ?? ''));
            $chartPemasukan[] = (float) ($row->total_pemasukan ?? $row->pemasukan ?? 0);
            $chartPengeluaran[] = (float) ($row->total_pengeluaran ?? $row->pengeluaran ?? 0);
        }

        return view('ceo.dashboard', compact('kpi', 'chartLabels', 'chartPemasukan', 'chartPengeluaran'));
    }

    public function profitKlien(Request $request)
    {
        $request->validate([
            'id_klien' => 'nullable|integer',
            'bulan'    => 'nullable|integer|between:1,12',
            'tahun'    => 'nullable|integer|between:2000,2100',
        ], [
            'bulan.between' => 'Bulan harus antara 1 sampai 12.',
            'tahun.between' => 'Tahun tidak valid.',
        ]);

        $klienList = DB::table('tbl_klien')->where('status', 'Aktif')->get();
        $selectedKlienId = $request->input('id_klien');
        $selectedBulan = (int) $request->input('bulan', date('n'));
        $selectedTahun = (int) $request->input('tahun', date('Y'));
        $profitResult = null;
        $trenLabels = $trenProfit = [];
        $error = null;

        if ($selectedKlienId) {
            try {
                $profitResult = DatabaseService::getProfitKlien((int) $selectedKlienId, $selectedBulan, $selectedTahun);

                // Tren 6 bulan terakhir: tiap titik tetap dihitung oleh fn_profit_klien di database
                for ($i = 5; $i >= 0; $i--) {
                    $t = mktime(0, 0, 0, $selectedBulan - $i, 1, $selectedTahun);
                    $trenLabels[] = self::NAMA_BULAN[(int) date('n', $t)] . ' ' . date('Y', $t);
                    $trenProfit[] = DatabaseService::getProfitKlien((int) $selectedKlienId, (int) date('n', $t), (int) date('Y', $t));
                }
            } catch (QueryException $e) {
                $error = $this->pesanError($e);
                $profitResult = null;
            }
        }

        $view = view('ceo.profit_klien', compact(
            'klienList', 'selectedKlienId', 'selectedBulan', 'selectedTahun', 'profitResult', 'trenLabels', 'trenProfit'
        ));
        return $error ? $view->with('error', $error) : $view;
    }
}

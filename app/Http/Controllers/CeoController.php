<?php

namespace App\Http\Controllers;

use App\Services\DatabaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CeoController extends Controller
{
    
    public function dashboard()
    {
        $dashboardData = DatabaseService::getDashboardEksekutif();
        $arusKasRaw = DatabaseService::getArusKasBulanan();

        // Format data arus kas untuk kebutuhan Chart.js
        $chartLabels = [];
        $chartPemasukan = [];
        $chartPengeluaran = [];

        foreach ($arusKasRaw as $row) {
            // Menggabungkan Bulan dan Tahun (misal: "Januari 2026")
            $chartLabels[] = ($row->bulan ?? $row->periode_bulan ?? '') . ' ' . ($row->tahun ?? $row->periode_tahun ?? '');
            $chartPemasukan[] = (float) ($row->total_pemasukan ?? $row->pemasukan ?? 0);
            $chartPengeluaran[] = (float) ($row->total_pengeluaran ?? $row->pengeluaran ?? 0);
        }

        return view('ceo.dashboard', compact(
            'dashboardData',
            'chartLabels',
            'chartPemasukan',
            'chartPengeluaran'
        ));
    }

   
    public function profitKlien(Request $request)
    {
        // Ambil daftar klien untuk dropdown pilihan
        $klienList = DB::table('tbl_klien')->where('status', 'Aktif')->get();

        $selectedKlienId = $request->input('id_klien');
        $selectedBulan = $request->input('bulan', date('n'));
        $selectedTahun = $request->input('tahun', date('Y'));
        $profitResult = null;
        $selectedKlienDetail = null;

        if ($selectedKlienId) {
            $profitResult = DatabaseService::getProfitKlien(
                (int) $selectedKlienId,
                (int) $selectedBulan,
                (int) $selectedTahun
            );

            $selectedKlienDetail = DB::table('tbl_klien')->where('id', $selectedKlienId)->first();
        }

        return view('ceo.profit_klien', compact(
            'klienList',
            'selectedKlienId',
            'selectedBulan',
            'selectedTahun',
            'profitResult',
            'selectedKlienDetail'
        ));
    }
}
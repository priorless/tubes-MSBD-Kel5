<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $batasHari = SertifikatController::BATAS_HARI_PERINGATAN;

        $dokumenHampirHabis = DB::table('tbl_sertifikat as s')
            ->join('tbl_karyawan as k', 'k.id', '=', 's.id_karyawan')
            ->select('s.id', 's.nama_dokumen', 's.tgl_kadaluarsa', 'k.nip', 'k.nama')
            ->selectRaw('fn_sisa_hari_dokumen(s.tgl_kadaluarsa) AS sisa_hari')
            ->whereRaw('fn_sisa_hari_dokumen(s.tgl_kadaluarsa) < ?', [$batasHari]);

        return view('hrd.dashboard', [
            // Jumlah karyawan per status dibaca dari view ringkasan yang sudah ada di database.
            'ringkasan' => DB::table('v_dashboard_eksekutif')
                ->first(['lapangan_bertugas', 'lapangan_siap', 'internal_aktif']),
            'totalKaryawan' => DB::table('tbl_karyawan')->count(),
            'jumlahDokumenHampirHabis' => (clone $dokumenHampirHabis)->count(),
            'dokumenHampirHabis' => $dokumenHampirHabis->orderBy('sisa_hari')->limit(5)->get(),
            'komposisiLayanan' => DB::table('tbl_jenis_layanan as jl')
                ->leftJoin('tbl_karyawan as k', 'k.id_jenis_layanan', '=', 'jl.id')
                ->groupBy('jl.id', 'jl.nama_layanan')
                ->orderBy('jl.nama_layanan')
                ->selectRaw('jl.nama_layanan, COUNT(k.id) AS jumlah')
                ->get(),
            'poolTeratas' => DB::table('v_pool_karyawan_hrd')->orderBy('tgl_masuk')->orderBy('nama')->limit(5)->get(),
            'karyawanTerbaru' => DB::table('tbl_karyawan')->orderByDesc('id')->limit(5)->get(['id', 'nip', 'nama', 'kategori', 'status', 'tgl_masuk']),
            'batasHari' => $batasHari,
        ]);
    }
}

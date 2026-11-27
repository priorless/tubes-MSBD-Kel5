<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hrd\SertifikatController;
use App\Support\KaryawanAktif;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Sertifikat Saya: karyawan hanya MELIHAT sertifikat miliknya (status & sisa hari dari v_sertifikat_karyawan).
 * Menambah dan menghapus sertifikat tetap tugas HRD.
 */
class SertifikatSayaController extends Controller
{
    public function __invoke(): View
    {
        return view('tenaga-kerja.sertifikat', [
            'sertifikat' => DB::table('v_sertifikat_karyawan')
                ->where('id_karyawan', (int) KaryawanAktif::id())
                ->orderBy('sisa_hari')
                ->orderBy('nama_dokumen')
                ->get(),
            'batasHari' => SertifikatController::BATAS_HARI_PERINGATAN,
        ]);
    }
}

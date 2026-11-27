<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Hrd\SertifikatController;
use App\Models\Role;
use App\Support\KaryawanAktif;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Membuka berkas dokumen karyawan dari disk privat: berkas sertifikat dan bukti pengajuan ketidakhadiran.
 * Hanya karyawan pemilik (dari akun yang login) dan HRD yang boleh membukanya.
 * Path file selalu dibaca dari database, tidak pernah dari input pengguna.
 */
class BerkasDokumenController extends Controller
{
    /** Disk privat bukti pengajuan ketidakhadiran (sama dengan berkas sertifikat). */
    public const string DISK = SertifikatController::DISK;

    public function sertifikat(Request $request, int $sertifikat): StreamedResponse
    {
        $baris = DB::table('tbl_sertifikat')->where('id', $sertifikat)->first(['id_karyawan', 'file_upload as path']);

        return $this->kirim($request, $baris);
    }

    public function ketidakhadiran(Request $request, int $pengajuan): StreamedResponse
    {
        $baris = DB::table('tbl_pengajuan_ketidakhadiran')->where('id', $pengajuan)->first(['id_karyawan', 'file_bukti as path']);

        return $this->kirim($request, $baris);
    }

    private function kirim(Request $request, ?object $baris): StreamedResponse
    {
        abort_if($baris === null, 404);

        $bolehMelihat = $request->user()->hasRole(Role::Hrd)
            || KaryawanAktif::id() === (int) $baris->id_karyawan;

        abort_unless($bolehMelihat, 403);

        $disk = Storage::disk(self::DISK);
        abort_if(blank($baris->path) || ! $disk->exists($baris->path), 404);

        return $disk->response($baris->path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

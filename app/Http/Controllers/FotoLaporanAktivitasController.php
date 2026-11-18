<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\FotoAbsensiService;
use App\Support\KaryawanAktif;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto laporan aktivitas dan foto kendaraan P2H dari disk privat (pola sama dengan FotoAbsensiController).
 * Hanya karyawan pemilik dan HRD yang boleh membuka; path file selalu dibaca dari database.
 */
class FotoLaporanAktivitasController extends Controller
{
    public function __construct(private FotoAbsensiService $foto) {}

    /** Foto laporan aktivitas: versi utama | koreksi. */
    public function laporan(Request $request, int $laporan, string $versi): StreamedResponse
    {
        $baris = DB::table('tbl_laporan_aktivitas')->where('id', $laporan)->first(['id_karyawan', "foto_$versi as path"]);

        return $this->kirim($request, $baris);
    }

    /** Foto kendaraan P2H sebuah sesi absensi: awal (check-in) | akhir (check-out). */
    public function kendaraan(Request $request, int $absensi, string $jenis): StreamedResponse
    {
        $baris = DB::table('tbl_p2h')->where('id_absensi', $absensi)->first(['id_karyawan', "foto_kendaraan_$jenis as path"]);

        return $this->kirim($request, $baris);
    }

    private function kirim(Request $request, ?object $baris): StreamedResponse
    {
        abort_if($baris === null, 404);
        abort_unless($request->user()->hasRole(Role::Hrd) || KaryawanAktif::id() === (int) $baris->id_karyawan, 403);

        $disk = Storage::disk($this->foto->disk());
        abort_if(blank($baris->path) || ! $disk->exists($baris->path), 404);

        return $disk->response($baris->path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

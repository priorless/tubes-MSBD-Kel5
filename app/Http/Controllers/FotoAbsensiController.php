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
 * Menampilkan foto absensi unggahan dari disk privat.
 * Hanya karyawan pemilik absensi (dari akun yang login) dan HRD yang boleh membukanya.
 * Path file selalu dibaca dari database, tidak pernah dari input pengguna.
 */
class FotoAbsensiController extends Controller
{
    public function __construct(private FotoAbsensiService $foto) {}

    public function __invoke(Request $request, int $absensi, string $jenis): StreamedResponse
    {
        $baris = DB::table('tbl_absensi')->where('id', $absensi)->first(['id_karyawan', "foto_$jenis as path"]);

        abort_if($baris === null, 404);

        $bolehMelihat = $request->user()->hasRole(Role::Hrd)
            || KaryawanAktif::id() === (int) $baris->id_karyawan;

        abort_unless($bolehMelihat, 403);

        $disk = Storage::disk($this->foto->disk());
        abort_if(blank($baris->path) || ! $disk->exists($baris->path), 404);

        return $disk->response($baris->path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

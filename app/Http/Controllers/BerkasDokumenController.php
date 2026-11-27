<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Hrd\SertifikatController;
use App\Models\Role;
use App\Support\KaryawanAktif;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /**
     * Berkas sertifikat: tampil di browser, atau diunduh dengan ?unduh=1 (nama file: sertifikat-NIP-nama-dokumen.ext).
     */
    public function sertifikat(Request $request, int $sertifikat): StreamedResponse
    {
        $baris = DB::table('tbl_sertifikat as s')
            ->join('tbl_karyawan as k', 'k.id', '=', 's.id_karyawan')
            ->where('s.id', $sertifikat)
            ->first(['s.id_karyawan', 's.file_upload as path', 's.nama_dokumen', 'k.nip']);

        $namaUnduh = $baris !== null && $request->boolean('unduh')
            ? 'sertifikat-'.Str::slug($baris->nip.' '.$baris->nama_dokumen).'.'.pathinfo((string) $baris->path, PATHINFO_EXTENSION)
            : null;

        return $this->kirim($request, $baris, $namaUnduh);
    }

    public function ketidakhadiran(Request $request, int $pengajuan): StreamedResponse
    {
        $baris = DB::table('tbl_pengajuan_ketidakhadiran')->where('id', $pengajuan)->first(['id_karyawan', 'file_bukti as path']);

        return $this->kirim($request, $baris);
    }

    /**
     * @param  string|null  $namaUnduh  diisi = dikirim sebagai lampiran (unduh), null = tampil di browser
     */
    private function kirim(Request $request, ?object $baris, ?string $namaUnduh = null): StreamedResponse
    {
        abort_if($baris === null, 404);

        $bolehMelihat = $request->user()->hasRole(Role::Hrd)
            || KaryawanAktif::id() === (int) $baris->id_karyawan;

        abort_unless($bolehMelihat, 403);

        $disk = Storage::disk(self::DISK);
        abort_if(blank($baris->path) || ! $disk->exists($baris->path), 404);

        $header = [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return $namaUnduh !== null
            ? $disk->download($baris->path, $namaUnduh, $header)
            : $disk->response($baris->path, null, $header);
    }
}

<?php

use App\Http\Controllers\Hrd\KaryawanController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Foto profil karyawan dipindah dari disk public (bisa dibuka tanpa login lewat /storage) ke disk privat local,
 * mengikuti berkas sertifikat (2026_11_27_212431). Path di tbl_karyawan.foto tetap sama, sehingga tidak ada
 * perubahan struktur maupun data tabel. Berkas disalin dulu, lalu salinan public baru dihapus; berkas yang
 * sudah ada di tujuan tidak ditimpa. down() memindahkan kembali ke disk public.
 */
return new class extends Migration
{
    private const string DISK_LAMA = 'public';

    public function up(): void
    {
        $this->pindahkanFoto(self::DISK_LAMA, KaryawanController::DISK_FOTO);
    }

    public function down(): void
    {
        $this->pindahkanFoto(KaryawanController::DISK_FOTO, self::DISK_LAMA);
    }

    private function pindahkanFoto(string $dari, string $ke): void
    {
        if (! Schema::hasTable('tbl_karyawan') || ! Schema::hasColumn('tbl_karyawan', 'foto')) {
            return;
        }

        $asal = Storage::disk($dari);
        $tujuan = Storage::disk($ke);

        DB::table('tbl_karyawan')->whereNotNull('foto')->where('foto', '<>', '')->pluck('foto')
            ->each(function (string $path) use ($asal, $tujuan): void {
                if (! $asal->exists($path)) {
                    return;
                }

                if (! $tujuan->exists($path) && ! $tujuan->put($path, $asal->get($path))) {
                    throw new RuntimeException("Foto {$path} gagal disalin; berkas asal tidak dihapus.");
                }

                $asal->delete($path);
            });
    }
};

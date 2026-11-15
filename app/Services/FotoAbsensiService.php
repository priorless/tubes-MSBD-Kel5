<?php

namespace App\Services;

use App\Support\FotoAbsensiTidakValid;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto absensi yang diunggah karyawan (dari galeri/kamera perangkatnya).
 *
 * Sistem HANYA menerima dan menyimpan foto: keterangan tanggal, jam, dan lokasi pada foto
 * dibuat oleh aplikasi kamera karyawan, bukan oleh sistem. Foto hanya diputar sesuai orientasi
 * EXIF, diperkecil bila sangat besar (keterangan pada foto tetap terbaca), lalu di-encode ulang
 * ke JPEG — metadata (termasuk GPS) dan payload tersembunyi tidak ikut tersimpan.
 * File disimpan di disk privat dan hanya dapat dibuka lewat route absensi.foto.
 */
class FotoAbsensiService
{
    public function disk(): string
    {
        return (string) config('tenaga_kerja.foto.disk', 'local');
    }

    /**
     * Path baru (belum ditulis) untuk foto check-in/check-out.
     *
     * @param  'masuk'|'keluar'  $jenis
     */
    public function pathBaru(string $jenis, string $tanggal): string
    {
        return sprintf('%s/%s/%s-%s.jpg',
            trim((string) config('tenaga_kerja.foto.folder', 'absensi'), '/'),
            substr($tanggal, 0, 7),
            $jenis,
            Str::uuid()->toString());
    }

    /** Tulis JPEG hasil siapkan() ke disk privat. */
    public function simpan(string $path, string $jpeg): void
    {
        if (! Storage::disk($this->disk())->put($path, $jpeg)) {
            throw new FotoAbsensiTidakValid('Foto absensi gagal disimpan. Silakan coba lagi.');
        }
    }

    public function hapus(string $path): void
    {
        Storage::disk($this->disk())->delete($path);
    }

    /** JPEG siap simpan (bytes): orientasi benar, sisi terpanjang maksimal config foto.sisi_maksimal. */
    public function siapkan(string $isiGambar): string
    {
        $sumber = @imagecreatefromstring($isiGambar);
        if (! $sumber instanceof GdImage) {
            throw new FotoAbsensiTidakValid('Foto tidak dapat dibaca. Pilih file foto JPG, PNG, atau WEBP lalu coba lagi.');
        }
        $sumber = $this->putarSesuaiExif($sumber, $isiGambar);

        $lebar = imagesx($sumber);
        $tinggi = imagesy($sumber);
        $maks = (int) config('tenaga_kerja.foto.sisi_maksimal', 1920);
        $rasio = min(1, $maks / max($lebar, $tinggi));
        $lebarBaru = max(1, (int) round($lebar * $rasio));
        $tinggiBaru = max(1, (int) round($tinggi * $rasio));

        // Selalu digambar ulang ke kanvas baru: metadata & data tersembunyi tidak ikut tersimpan.
        $hasil = imagecreatetruecolor($lebarBaru, $tinggiBaru);
        imagefill($hasil, 0, 0, imagecolorallocate($hasil, 255, 255, 255));
        imagecopyresampled($hasil, $sumber, 0, 0, 0, 0, $lebarBaru, $tinggiBaru, $lebar, $tinggi);

        ob_start();
        imagejpeg($hasil, null, 88);

        return (string) ob_get_clean();
    }

    /** Foto ponsel sering disimpan miring dengan penanda orientasi EXIF; putar agar tegak. */
    private function putarSesuaiExif(GdImage $gambar, string $isiGambar): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($isiGambar, "\xFF\xD8")) {
            return $gambar;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($isiGambar));
        $orientasi = (int) ($exif['Orientation'] ?? 1);

        if (in_array($orientasi, [2, 4, 5, 7], true)) {
            imageflip($gambar, IMG_FLIP_HORIZONTAL);
        }

        $sudut = match ($orientasi) {
            3, 4 => 180,
            6, 7 => 270,
            5, 8 => 90,
            default => 0,
        };

        return $sudut === 0 ? $gambar : (imagerotate($gambar, $sudut, 0) ?: $gambar);
    }
}

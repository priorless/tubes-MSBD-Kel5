<?php

namespace Tests\Feature\TenagaKerja;

use App\Services\FotoAbsensiService;
use App\Support\FotoAbsensiTidakValid;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foto absensi unggahan: sistem hanya menerima dan menyimpan foto (tanpa watermark),
 * di-encode ulang ke JPEG dan diperkecil secukupnya, di disk privat.
 */
class FotoAbsensiServiceTest extends TestCase
{
    private FotoAbsensiService $layanan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->layanan = new FotoAbsensiService;
    }

    private function gambar(int $lebar, int $tinggi): string
    {
        $gambar = imagecreatetruecolor($lebar, $tinggi);
        imagefill($gambar, 0, 0, imagecolorallocate($gambar, 255, 255, 255));
        ob_start();
        imagepng($gambar);

        return (string) ob_get_clean();
    }

    public function test_large_upload_is_reencoded_to_jpeg_and_resized_without_adding_anything(): void
    {
        $jpeg = $this->layanan->siapkan($this->gambar(4000, 3000));

        $info = getimagesizefromstring($jpeg);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame([1920, 1440], [$info[0], $info[1]]);

        // Tidak ada watermark/teks dari sistem: foto putih tetap putih sampai ke pojok bawah.
        $hasil = imagecreatefromstring($jpeg);
        $warna = imagecolorat($hasil, 1910, 1430);
        $this->assertGreaterThan(740, (($warna >> 16) & 0xFF) + (($warna >> 8) & 0xFF) + ($warna & 0xFF));
    }

    public function test_small_upload_keeps_its_size(): void
    {
        $info = getimagesizefromstring($this->layanan->siapkan($this->gambar(800, 600)));

        $this->assertSame([800, 600], [$info[0], $info[1]]);
    }

    public function test_unreadable_image_is_rejected(): void
    {
        $this->expectException(FotoAbsensiTidakValid::class);

        $this->layanan->siapkan('bukan gambar');
    }

    public function test_photo_is_stored_on_private_disk_under_month_folder(): void
    {
        Storage::fake('local');

        $path = $this->layanan->pathBaru('masuk', '2026-11-15');
        $this->layanan->simpan($path, $this->layanan->siapkan($this->gambar(640, 480)));

        $this->assertMatchesRegularExpression('#^absensi/2026-11/masuk-[0-9a-f-]{36}\.jpg$#', $path);
        Storage::disk('local')->assertExists($path);

        $this->layanan->hapus($path);
        Storage::disk('local')->assertMissing($path);
    }
}

<?php

namespace Tests\Feature\TenagaKerja;

use App\Models\Role;
use App\Models\User;
use App\Services\TenagaKerjaService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;
use PDOException;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Absensi foto unggahan: foto wajib, sistem hanya menerima foto (lokasi resmi tetap penempatan;
 * keterangan tanggal/jam/lokasi pada foto dibuat aplikasi kamera karyawan, data lokasi tidak diproses).
 * TenagaKerjaService di-mock karena trigger absensi hanya ada di MySQL db_sji.
 */
class LokasiAbsensiTest extends TestCase
{
    use CreatesSjiAuthTables;

    private const string PESAN_FOTO_WAJIB = 'Foto absensi wajib diunggah (JPG, PNG, atau WEBP, maksimal 15 MB).';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->withRole(Role::KaryawanLapangan)->create(['id_karyawan' => 7]));
    }

    /**
     * @param  callable(MockInterface): void  $harapan
     */
    private function mockLayanan(callable $harapan): void
    {
        $this->mock(TenagaKerjaService::class, function (MockInterface $mock) use ($harapan): void {
            $harapan($mock);
            $mock->shouldReceive('absensiHariIni')->andReturn(null)->byDefault();
            $mock->shouldReceive('tabel')->andReturn('tbl_absensi')->byDefault();
            $mock->shouldReceive('kolom')->andReturn(null)->byDefault();
            $mock->shouldReceive('waktuServer')->andReturn('2026-11-15 08:00:00')->byDefault();
            $mock->shouldReceive('penugasanAktif')->andReturn(null)->byDefault();
        });
    }

    private function foto(): UploadedFile
    {
        return UploadedFile::fake()->image('IMG_20261115_074512.jpg', 1080, 1920);
    }

    public function test_check_in_without_photo_is_rejected_before_touching_database(): void
    {
        $this->mockLayanan(fn (MockInterface $mock) => $mock->shouldReceive('checkIn')->never());

        $this->postJson(route('tenaga-kerja.absensi.check-in'))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'pesan' => self::PESAN_FOTO_WAJIB]);
    }

    public function test_non_image_or_oversized_upload_is_rejected(): void
    {
        $this->mockLayanan(fn (MockInterface $mock) => $mock->shouldReceive('checkIn')->never());

        $this->postJson(route('tenaga-kerja.absensi.check-in'), ['foto' => UploadedFile::fake()->create('absensi.pdf', 10, 'application/pdf')])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'pesan' => self::PESAN_FOTO_WAJIB]);

        $this->postJson(route('tenaga-kerja.absensi.check-in'), ['foto' => UploadedFile::fake()->image('besar.jpg', 1000, 1000)->size(16000)])
            ->assertStatus(422);
    }

    public function test_gallery_photo_is_accepted_and_passed_to_service(): void
    {
        $this->mockLayanan(function (MockInterface $mock): void {
            $mock->shouldReceive('checkIn')->once()->with(7, \Mockery::type('string'));
            $mock->shouldReceive('kolom')->andReturnUsing(fn (string $tabel, string $kunci) => config("tenaga_kerja.kolom.$kunci")[0] ?? null);
            $mock->shouldReceive('absensiHariIni')->andReturn((object) [
                'id' => 11, 'tgl' => '2026-11-15', 'jam_masuk' => '07:45:12', 'jam_keluar' => null,
                'status_kehadiran' => 'Terlambat', 'menit_terlambat' => 45,
                'foto_masuk' => 'absensi/2026-11/masuk-x.jpg', 'foto_keluar' => null,
            ]);
        });

        $this->postJson(route('tenaga-kerja.absensi.check-in'), ['foto' => $this->foto()])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'pesan' => 'Check-in berhasil. Anda tercatat terlambat 45 menit dari jadwal kerja.',
                'status' => 'masuk',
                'status_kehadiran' => 'Terlambat',
                'foto' => ['masuk' => route('absensi.foto', ['absensi' => 11, 'jenis' => 'masuk']), 'keluar' => null],
            ]);
    }

    public function test_rejection_from_database_trigger_is_shown_to_user(): void
    {
        $pesan = 'Anda belum memiliki penempatan aktif. Absensi dapat dilakukan setelah ditempatkan oleh bagian Operasional.';
        $galat = new PDOException($pesan);
        $galat->errorInfo = ['45000', 1644, $pesan];

        $this->mockLayanan(fn (MockInterface $mock) => $mock->shouldReceive('checkIn')->once()
            ->andThrow(new QueryException('mysql', 'insert', [], $galat)));

        $this->postJson(route('tenaga-kerja.absensi.check-in'), ['foto' => $this->foto()])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'pesan' => $pesan]);
    }

    public function test_check_out_without_photo_is_rejected(): void
    {
        $this->mockLayanan(fn (MockInterface $mock) => $mock->shouldReceive('checkOut')->never());

        $this->postJson(route('tenaga-kerja.absensi.check-out'))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'pesan' => self::PESAN_FOTO_WAJIB]);
    }

    public function test_form_submit_without_photo_is_rejected_with_notification(): void
    {
        $this->mockLayanan(fn (MockInterface $mock) => $mock->shouldReceive('checkIn')->never());

        $this->post(route('tenaga-kerja.absensi.check-in'))
            ->assertRedirect(route('tenaga-kerja.absensi'))
            ->assertSessionHas('gagal', self::PESAN_FOTO_WAJIB);
    }
}

<?php

namespace Tests\Feature\TenagaKerja;

use App\Models\Role;
use App\Models\User;
use App\Services\TenagaKerjaService;
use Mockery\MockInterface;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Lokasi (GPS) wajib saat check-in/check-out dan hanya ditampilkan (disimpan di sesi, bukan database).
 * TenagaKerjaService di-mock karena procedure/trigger absensi hanya ada di MySQL db_sji.
 */
class LokasiAbsensiTest extends TestCase
{
    use CreatesSjiAuthTables;

    private const string PESAN_LOKASI_WAJIB = 'Lokasi Anda belum terdeteksi. Izinkan akses lokasi (GPS) di browser, lalu coba lagi.';

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
            $mock->shouldReceive('waktuServer')->andReturn('2026-11-14 08:00:00')->byDefault();
        });
    }

    public function test_check_in_without_location_is_rejected_before_touching_database(): void
    {
        $this->mockLayanan(fn (MockInterface $mock) => $mock->shouldReceive('checkIn')->never());

        $this->postJson(route('tenaga-kerja.absensi.check-in'))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'pesan' => self::PESAN_LOKASI_WAJIB]);
    }

    public function test_check_in_with_invalid_coordinates_is_rejected(): void
    {
        $this->mockLayanan(fn (MockInterface $mock) => $mock->shouldReceive('checkIn')->never());

        $this->postJson(route('tenaga-kerja.absensi.check-in'), ['latitude' => 200, 'longitude' => 98.67])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'pesan' => self::PESAN_LOKASI_WAJIB]);
    }

    public function test_check_in_with_location_succeeds_and_returns_location_for_display(): void
    {
        $this->mockLayanan(fn (MockInterface $mock) => $mock->shouldReceive('checkIn')->once()->with(7));

        $this->postJson(route('tenaga-kerja.absensi.check-in'), ['latitude' => 3.5951956, 'longitude' => 98.6722227, 'akurasi' => 12.4])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'lokasi' => ['masuk' => ['latitude' => 3.595196, 'longitude' => 98.672223, 'akurasi' => 12]],
            ]);
    }

    public function test_check_out_without_location_is_rejected(): void
    {
        $this->mockLayanan(fn (MockInterface $mock) => $mock->shouldReceive('checkOut')->never());

        $this->postJson(route('tenaga-kerja.absensi.check-out'), ['akurasi' => 10])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'pesan' => self::PESAN_LOKASI_WAJIB]);
    }

    public function test_form_submit_without_javascript_is_rejected_with_notification(): void
    {
        $this->mockLayanan(fn (MockInterface $mock) => $mock->shouldReceive('checkIn')->never());

        $this->post(route('tenaga-kerja.absensi.check-in'))
            ->assertRedirect(route('tenaga-kerja.absensi'))
            ->assertSessionHas('gagal', self::PESAN_LOKASI_WAJIB);
    }
}

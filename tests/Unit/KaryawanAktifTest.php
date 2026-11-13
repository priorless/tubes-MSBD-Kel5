<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\KaryawanAktif;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Aturan identitas Modul Tenaga Kerja (Orang 4).
 * Tidak menyentuh database: user dibuat di memori lalu dipasang ke guard.
 */
class KaryawanAktifTest extends TestCase
{
    private const DEV_ID = 99;

    protected function setUp(): void
    {
        parent::setUp();

        // Simulasikan lingkungan lokal dengan TK_DEV_ID_KARYAWAN terisi.
        $this->app->detectEnvironment(fn () => 'local');
        config(['tenaga_kerja.dev_id_karyawan' => self::DEV_ID]);
    }

    private function loginSebagai(?int $idKaryawan): void
    {
        Auth::setUser((new User)->forceFill(['id' => 1, 'id_karyawan' => $idKaryawan]));
    }

    public function test_user_login_tanpa_id_karyawan_tidak_memakai_dev_id_maupun_session(): void
    {
        session([config('tenaga_kerja.session_key') => 5]);
        $this->loginSebagai(null);

        $this->assertNull(KaryawanAktif::id());
        $this->assertTrue(KaryawanAktif::akunTanpaKaryawan());
        $this->assertFalse(KaryawanAktif::modeUjiCoba());
    }

    public function test_user_login_dengan_id_karyawan_memakai_id_dari_akun(): void
    {
        $this->loginSebagai(7);

        $this->assertSame(7, KaryawanAktif::id());
        $this->assertFalse(KaryawanAktif::akunTanpaKaryawan());
        $this->assertFalse(KaryawanAktif::modeUjiCoba());
    }

    public function test_tamu_di_lokal_memakai_dev_id_dan_ditandai_mode_uji_coba(): void
    {
        $this->assertSame(self::DEV_ID, KaryawanAktif::id());
        $this->assertTrue(KaryawanAktif::modeUjiCoba());
        $this->assertFalse(KaryawanAktif::akunTanpaKaryawan());
    }

    public function test_tamu_di_luar_lokal_tidak_memakai_dev_id(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertNull(KaryawanAktif::id());
        $this->assertFalse(KaryawanAktif::modeUjiCoba());
    }

    public function test_tamu_dengan_session_memakai_id_session(): void
    {
        session([config('tenaga_kerja.session_key') => 5]);

        $this->assertSame(5, KaryawanAktif::id());
        $this->assertFalse(KaryawanAktif::modeUjiCoba());
    }
}

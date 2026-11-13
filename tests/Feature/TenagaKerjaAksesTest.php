<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Akses Modul Tenaga Kerja (Orang 4) setelah integrasi login CEO.
 * Database tes = SQLite in-memory (tbl_roles & tbl_users versi minimal), bukan db_sji.
 */
class TenagaKerjaAksesTest extends TestCase
{
    use CreatesSjiAuthTables, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pastikan nilai uji coba TERSEDIA, agar tes membuktikan nilai itu tidak dipakai saat login.
        $this->app->detectEnvironment(fn () => 'local');
        config(['tenaga_kerja.dev_id_karyawan' => 99]);
    }

    public function test_tamu_diarahkan_ke_halaman_login(): void
    {
        $this->get(route('tenaga-kerja.absensi'))->assertRedirect(route('login'));
    }

    public function test_karyawan_lapangan_tanpa_id_karyawan_ditolak(): void
    {
        $user = User::factory()->withRole(Role::KaryawanLapangan)->create(['id_karyawan' => null]);

        $this->actingAs($user)
            ->get(route('tenaga-kerja.absensi'))
            ->assertStatus(401)
            ->assertSee('Akun Anda belum terhubung dengan data karyawan');
    }

    public function test_role_selain_karyawan_lapangan_ditolak_walau_punya_id_karyawan(): void
    {
        $user = User::factory()->withRole(Role::Hrd)->create(['id_karyawan' => 7]);

        $this->actingAs($user)->get(route('tenaga-kerja.absensi'))->assertForbidden();
    }

    public function test_karyawan_lapangan_terhubung_dapat_membuka_absensi_tanpa_mode_uji_coba(): void
    {
        $user = User::factory()->withRole(Role::KaryawanLapangan)->create(['id_karyawan' => 7]);

        // View/tabel MySQL tidak ada di SQLite: halaman tetap tampil dengan pesan ramah.
        $this->actingAs($user)
            ->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertDontSee('Mode uji coba')
            ->assertSee(route('logout'), false);
    }

    public function test_wajib_ganti_password_diarahkan_ke_halaman_ganti_password(): void
    {
        $user = User::factory()->withRole(Role::KaryawanLapangan)
            ->create(['id_karyawan' => 7, 'must_change_password' => true]);

        $this->actingAs($user)
            ->get(route('tenaga-kerja.absensi'))
            ->assertRedirect(route('password.change'));
    }

    public function test_akun_nonaktif_dikeluarkan(): void
    {
        $user = User::factory()->withRole(Role::KaryawanLapangan)->nonaktif()->create(['id_karyawan' => 7]);

        $this->actingAs($user)->get(route('tenaga-kerja.absensi'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}

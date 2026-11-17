<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use CreatesSjiAuthTables, RefreshDatabase;

    public function test_login_page_is_displayed(): void
    {
        $this->get('/login')->assertOk()->assertSee('Masuk');
    }

    public function test_ceo_is_redirected_to_ceo_dashboard_after_login(): void
    {
        $user = User::factory()->withRole(Role::Ceo)->create(['username' => 'direktur']);

        $this->post('/login', ['username' => 'direktur', 'password' => 'password'])
            ->assertRedirect(route('ceo.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_karyawan_lapangan_is_redirected_to_absensi_after_login(): void
    {
        User::factory()->withRole(Role::KaryawanLapangan)->create(['username' => 'satpam01']);

        $this->post('/login', ['username' => 'satpam01', 'password' => 'password'])
            ->assertRedirect(route('tenaga-kerja.absensi'));
    }

    #[DataProvider('halamanAwalModulProvider')]
    public function test_staff_is_redirected_to_their_module_after_login(string $namaRole, string $namaRoute): void
    {
        User::factory()->withRole($namaRole)->create(['username' => 'staff01']);

        $this->post('/login', ['username' => 'staff01', 'password' => 'password'])
            ->assertRedirect(route($namaRoute));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function halamanAwalModulProvider(): array
    {
        return [
            'HRD' => [Role::Hrd, 'hrd.dashboard'],
            'Operasional' => [Role::Operasional, 'operasional.beranda'],
            'Keuangan' => [Role::Keuangan, 'keuangan.payroll.index'],
        ];
    }

    public function test_role_without_registered_home_route_falls_back_to_beranda(): void
    {
        User::factory()->withRole('Auditor')->create(['username' => 'auditor01']);

        $this->post('/login', ['username' => 'auditor01', 'password' => 'password'])
            ->assertRedirect(route('beranda'));
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->withRole(Role::Ceo)->create(['username' => 'direktur']);

        $this->from('/login')
            ->post('/login', ['username' => 'direktur', 'password' => 'salah'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['username' => 'Username atau password salah.']);

        $this->assertGuest();
    }

    public function test_nonaktif_user_cannot_login(): void
    {
        User::factory()->withRole(Role::Hrd)->nonaktif()->create(['username' => 'mantan']);

        $this->post('/login', ['username' => 'mantan', 'password' => 'password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_too_many_attempts(): void
    {
        User::factory()->withRole(Role::Ceo)->create(['username' => 'direktur']);

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['username' => 'direktur', 'password' => 'salah']);
        }

        $this->post('/login', ['username' => 'direktur', 'password' => 'password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    /**
     * Akun dari trigger trg_auto_akun_karyawan memakai SHA2(nip, 256): tetap bisa login,
     * hash langsung diganti bcrypt dan user wajib ganti password.
     */
    public function test_legacy_sha256_password_is_accepted_and_rehashed(): void
    {
        $user = User::factory()->withRole(Role::KaryawanLapangan)->create(['username' => 'NIP0001']);
        User::query()->whereKey($user->id)->update(['password' => hash('sha256', 'NIP0001')]);

        $this->post('/login', ['username' => 'NIP0001', 'password' => 'NIP0001'])
            ->assertRedirect(route('password.change'));

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('NIP0001', $user->password));
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->withRole(Role::Ceo)->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }
}

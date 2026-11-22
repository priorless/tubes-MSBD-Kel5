<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use CreatesSjiAuthTables, RefreshDatabase;

    public function test_guest_sees_landing_page_with_login_button(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('landing')
            ->assertSeeText('PT Satu Jaya Indonesia')
            ->assertSeeTextInOrder(['Cleaning Service', 'Satpam', 'Driver'])
            ->assertSee('href="'.route('login').'"', false)
            ->assertSeeText('Masuk ke Sistem')
            ->assertDontSeeText('Buka Dashboard');
    }

    #[DataProvider('halamanAwalRoleProvider')]
    public function test_logged_in_user_sees_dashboard_button_to_their_home_page(string $namaRole, string $namaRoute): void
    {
        $user = User::factory()->withRole($namaRole)->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertViewIs('landing')
            ->assertSeeText('Buka Dashboard')
            ->assertSee('href="'.route($namaRoute).'"', false)
            ->assertDontSee('href="'.route('login').'"', false);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function halamanAwalRoleProvider(): array
    {
        return [
            'Operasional' => [Role::Operasional, 'operasional.beranda'],
            'Karyawan lapangan' => [Role::KaryawanLapangan, 'tenaga-kerja.absensi'],
        ];
    }
}

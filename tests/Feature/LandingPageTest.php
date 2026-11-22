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
            ->assertSeeText('Tenaga Kerja Andal, Dikelola dengan Tertib.')
            ->assertSeeTextInOrder(['Cleaning Service', 'Satpam', 'Driver'])
            ->assertSee('href="'.route('login').'"', false)
            ->assertSeeText('Masuk ke Sistem')
            ->assertDontSeeText('Buka Dashboard');
    }

    public function test_activity_section_links_only_official_social_media(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeText('Mengenal Lebih Dekat Kegiatan Satu Jaya')
            ->assertSee('href="https://www.instagram.com/satujayaindonesia?knvt=MXVtaGJ0ems1cXA0dQ=="', false)
            ->assertSee('href="https://www.youtube.com/@pt.satujayaindonesia3187/videos"', false)
            ->assertDontSee('mailto:', false)
            ->assertDontSee('wa.me', false);
    }

    public function test_activity_links_keep_the_kontak_anchor(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="kontak"', false)
            ->assertSee('<a href="#kontak" class="lp-menu-link">Aktivitas</a>', false)
            ->assertSee('<a href="#kontak">Aktivitas Perusahaan</a>', false)
            ->assertSee('<a href="#kontak" class="lp-btn lp-btn-ghost">Lihat Aktivitas</a>', false);
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

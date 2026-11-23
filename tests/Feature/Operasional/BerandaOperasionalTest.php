<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\Concerns\CreatesTemplateJadwalTables;
use Tests\TestCase;

class BerandaOperasionalTest extends TestCase
{
    use CreatesSjiAuthTables;
    use CreatesTemplateJadwalTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00:00'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('operasional.beranda'))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function otherRoles(): array
    {
        return ['HRD' => [Role::Hrd], 'Keuangan' => [Role::Keuangan], 'CEO' => [Role::Ceo]];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_are_forbidden(string $role): void
    {
        $this->createTables();

        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('operasional.beranda'))
            ->assertForbidden();
    }

    public function test_summary_counts_come_from_the_database(): void
    {
        $this->createOperationalData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.beranda'))
            ->assertOk()
            ->assertSeeTextInOrder([
                'Klien aktif', '1',
                'Karyawan ditempatkan', '2',
                'Tanggal terjadwal November 2026', '24',
                'Jadwal belum lengkap November 2026', '3', '46 tanggal belum diatur',
            ]);
    }

    public function test_attention_list_shows_overdue_incomplete_and_ending_placements(): void
    {
        $this->createOperationalData();

        $response = $this->actingAs($this->operasional())->get(route('operasional.beranda'));

        $response->assertOk()
            ->assertSeeText('5 hal menunggu tindakan.')
            ->assertSeeText('Masa penempatan sudah lewat, tetapi masih Aktif')
            ->assertSeeText('Berakhir 10/11/2026')
            ->assertSeeTextInOrder(['Jadwal November 2026 belum lengkap', 'Andi', '20 tanggal belum diatur', 'Budi', '16 tanggal belum diatur', 'Dedi', '10 tanggal belum diatur'])
            ->assertSee('href="'.e(route('operasional.jadwal-kerja', ['id_karyawan' => 1, 'bulan' => '2026-11'])).'"', false)
            ->assertSeeTextInOrder(['Penempatan berakhir dalam 30 hari', 'Budi', 'Sisa 13 hari'])
            ->assertDontSeeText('Citra');
    }

    public function test_empty_state_is_shown_when_nothing_needs_attention(): void
    {
        $this->createTables();
        $this->createClient('KL001');

        $this->actingAs($this->operasional())
            ->get(route('operasional.beranda'))
            ->assertOk()
            ->assertSeeText('Semua beres')
            ->assertSeeTextInOrder(['Karyawan ditempatkan', '0', 'Tanggal terjadwal November 2026', '0']);
    }

    public function test_greeting_quick_access_and_grouped_sidebar(): void
    {
        $this->createTables();
        $user = $this->operasional();

        $this->actingAs($user)
            ->get(route('operasional.beranda'))
            ->assertOk()
            ->assertSeeText('Selamat datang, '.$user->namaTampilan())
            ->assertSee('href="'.route('operasional.jadwal-kerja').'" class="op-akses-cepat"', false)
            ->assertSee('href="'.route('operasional.penempatan.create').'" class="op-akses-cepat"', false)
            ->assertSee('href="'.route('operasional.klien.create').'" class="op-akses-cepat"', false)
            ->assertSeeTextInOrder(['Akses cepat', 'Atur Jadwal Kerja', 'Buat Penempatan Baru', 'Tambah Klien', 'Lihat Laporan'])
            ->assertSee('<title>Dashboard Operasional - SJI</title>', false)
            ->assertSeeInOrder(['aria-label="Menu utama"', 'Operasional', 'Dashboard Operasional', 'Data Utama', 'Data Klien', 'Penempatan', 'Kegiatan Operasional', 'Rotasi Karyawan', 'Jadwal Kerja', 'Monitoring Sebaran'], false)
            ->assertSee('href="'.route('operasional.beranda').'"', false)
            ->assertSee('aria-current="page"', false);
    }

    /**
     * Klien 1 Aktif, klien 2 Nonaktif. Andi & Budi bekerja hari ini; Citra sudah Selesai;
     * Dedi masih Aktif walau masa penempatannya berakhir 10/11/2026.
     */
    private function createOperationalData(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        DB::table('tbl_klien')->where('id', $this->createClient('KL002'))->update(['status' => 'Nonaktif']);
        $andiId = $this->createEmployee('SJI001', 'Andi');
        $budiId = $this->createEmployee('SJI002', 'Budi');
        $citraId = $this->createEmployee('SJI003', 'Citra');
        $dediId = $this->createEmployee('SJI004', 'Dedi');
        $this->createPlacement(101, $andiId, $clientId, '2026-01-01');
        $this->createPlacement(102, $budiId, $clientId, '2026-11-01', '2026-11-30');
        $this->createPlacement(103, $citraId, $clientId, '2026-01-01', '2026-06-30', 'Selesai');
        $this->createPlacement(104, $dediId, $clientId, '2026-11-01', '2026-11-10');

        foreach (range(1, 10) as $day) {
            $this->createSchedule(101, sprintf('2026-11-%02d', $day), 'Libur');
        }

        foreach (range(1, 14) as $day) {
            $this->createSchedule(102, sprintf('2026-11-%02d', $day), 'Libur');
        }
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}

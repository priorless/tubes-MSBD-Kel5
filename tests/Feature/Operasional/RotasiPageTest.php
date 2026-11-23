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

class RotasiPageTest extends TestCase
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
        $this->get(route('operasional.rotasi.create'))->assertRedirect(route('login'));
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
        $this->createRotationData();

        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('operasional.rotasi.create'))
            ->assertForbidden();
    }

    public function test_form_is_one_page_with_the_existing_field_names_and_a_confirmation(): void
    {
        $this->createRotationData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.rotasi.create'))
            ->assertOk()
            ->assertSeeTextInOrder(['Karyawan & penempatan saat ini', 'Penempatan tujuan', 'Proses Rotasi'])
            ->assertDontSee('data-langkah=', false)
            ->assertDontSee('>Lanjut</button>', false)
            ->assertSee("onsubmit=\"return confirm('Proses rotasi ini? Rotasi tidak dapat dibatalkan dari aplikasi.')\"", false)
            ->assertSee('id="dampak-jadwal"', false)
            ->assertSee('action="'.route('operasional.rotasi.store').'"', false)
            ->assertSee('name="id_penempatan_lama"', false)
            ->assertSee('name="id_klien_baru"', false)
            ->assertSee('name="tgl_mulai_baru"', false)
            ->assertSee('name="lokasi_baru"', false)
            ->assertSeeText('Tanggal efektif rotasi.')
            ->assertDontSeeText('otomatis berakhir');
    }

    public function test_current_placement_details_and_upcoming_schedule_are_available_for_the_summary(): void
    {
        $this->createRotationData();

        $response = $this->actingAs($this->operasional())->get(route('operasional.rotasi.create'));

        $response->assertOk()
            ->assertSee('data-klien="PT Alpha"', false)
            ->assertSee('data-lokasi="Pos Utama"', false)
            ->assertSee('data-jam="07:00–19:00"', false)
            ->assertSee('data-mulai="2026-01-01"', false)
            ->assertSee('data-periode="01/01/2026 – tanpa batas"', false)
            ->assertSee('data-jadwal="'.e(json_encode(['2026-11-20', '2026-11-25'])).'"', false)
            ->assertDontSee('2026-11-10', false)
            ->assertSeeText('SJI001 — Andi (PT Alpha)')
            ->assertDontSeeText('Citra');
    }

    public function test_only_active_clients_are_offered_as_destination(): void
    {
        $this->createRotationData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.rotasi.create'))
            ->assertOk()
            ->assertSeeText('KL002 — PT Beta')
            ->assertDontSeeText('PT Gamma');
    }

    public function test_placement_can_be_preselected_from_its_detail_page(): void
    {
        $this->createRotationData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.rotasi.create', ['id_penempatan' => 101]))
            ->assertOk()
            ->assertSee('<option value="101" selected', false);
    }

    public function test_rotation_cannot_be_processed_without_an_active_placement(): void
    {
        $this->createTables();
        $this->createClient('KL001');

        $this->actingAs($this->operasional())
            ->get(route('operasional.rotasi.create'))
            ->assertOk()
            ->assertSeeText('Tidak ada penempatan aktif yang dapat dirotasi.')
            ->assertSee('<button type="submit" class="btn-warning" disabled>Proses Rotasi</button>', false);
    }

    public function test_old_create_url_redirects_to_the_rotation_form(): void
    {
        $this->actingAs($this->operasional())
            ->get('/operasional/rotasi/create')
            ->assertStatus(301)
            ->assertRedirect('/operasional/rotasi');
    }

    /**
     * Andi Aktif di PT Alpha dengan jadwal 10/11 (lewat), 20/11, dan 25/11. Citra sudah Selesai.
     * PT Gamma Nonaktif sehingga tidak boleh menjadi tujuan.
     */
    private function createRotationData(): void
    {
        $this->createTables();
        DB::table('tbl_klien')->insert([
            ['id' => 1, 'kode_klien' => 'KL001', 'nama_perusahaan' => 'PT Alpha', 'status' => 'Aktif'],
            ['id' => 2, 'kode_klien' => 'KL002', 'nama_perusahaan' => 'PT Beta', 'status' => 'Aktif'],
            ['id' => 3, 'kode_klien' => 'KL003', 'nama_perusahaan' => 'PT Gamma', 'status' => 'Nonaktif'],
        ]);
        $this->createPlacement(101, $this->createEmployee('SJI001', 'Andi'), 1, '2026-01-01');
        $this->createPlacement(102, $this->createEmployee('SJI003', 'Citra'), 1, '2025-01-01', '2025-12-31', 'Selesai');
        DB::table('tbl_penempatan')->where('id', 101)->update(['lokasi_kerja' => 'Pos Utama', 'jam_datang' => '07:00:00', 'jam_pulang' => '19:00:00']);
        $this->createSchedule(101, '2026-11-10', 'Libur');
        $this->createSchedule(101, '2026-11-20', 'Kerja', '07:00', '19:00');
        $this->createSchedule(101, '2026-11-25', 'Libur');
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}

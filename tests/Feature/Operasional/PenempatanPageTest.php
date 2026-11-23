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

class PenempatanPageTest extends TestCase
{
    use CreatesSjiAuthTables;
    use CreatesTemplateJadwalTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00:00'));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, int>}>
     */
    public static function pages(): array
    {
        return [
            'index' => ['operasional.penempatan.index', []],
            'show' => ['operasional.penempatan.show', ['penempatan' => 101]],
            'create' => ['operasional.penempatan.create', []],
        ];
    }

    /**
     * @param  array<string, int>  $parameters
     */
    #[DataProvider('pages')]
    public function test_guest_is_redirected_to_login(string $routeName, array $parameters): void
    {
        $this->get(route($routeName, $parameters))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, int>}>
     */
    public static function forbiddenRolesAndPages(): array
    {
        $cases = [];

        foreach ([Role::Hrd, Role::Keuangan, Role::Ceo] as $role) {
            foreach (self::pages() as $key => [$routeName, $parameters]) {
                $cases["{$role} {$key}"] = [$role, $routeName, $parameters];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, int>  $parameters
     */
    #[DataProvider('forbiddenRolesAndPages')]
    public function test_other_roles_are_forbidden(string $role, string $routeName, array $parameters): void
    {
        $this->createPlacementData();

        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route($routeName, $parameters))
            ->assertForbidden();
    }

    public function test_list_shows_employee_client_service_location_period_and_status(): void
    {
        $this->createPlacementData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.penempatan.index'))
            ->assertOk()
            ->assertSeeText('Semua (3)')
            ->assertSeeText('Aktif (2)')
            ->assertSeeText('Dirotasi (1)')
            ->assertSeeTextInOrder(['Andi', 'SJI001', 'PT Alpha', 'Satpam', 'Pos Utama', '01/01/2026', 'tanpa batas', 'Aktif'])
            ->assertSeeTextInOrder(['Citra', 'PT Alpha', '01/01/2025', '31/12/2025', 'Dirotasi'])
            ->assertSee('href="'.route('operasional.penempatan.show', 101).'"', false)
            ->assertSee('href="'.route('operasional.penempatan.create').'" class="btn-primary">+ Penempatan Baru</a>', false);
    }

    /**
     * @return array<string, array{0: array<string, int|string>, 1: list<string>, 2: list<string>}>
     */
    public static function filters(): array
    {
        return [
            'search by name' => [['q' => 'budi'], ['Budi'], ['Andi', 'Citra']],
            'search by location' => [['q' => 'Gudang'], ['Budi'], ['Andi']],
            'client' => [['id_klien' => 2], ['Budi'], ['Andi', 'Citra']],
            'service type' => [['id_jenis_layanan' => 1], ['Andi', 'Citra'], ['Budi']],
            'status' => [['status' => 'Rotasi'], ['Citra'], ['Andi', 'Budi']],
        ];
    }

    /**
     * @param  array<string, int|string>  $query
     * @param  list<string>  $visible
     * @param  list<string>  $hidden
     */
    #[DataProvider('filters')]
    public function test_filters_narrow_the_list(array $query, array $visible, array $hidden): void
    {
        $this->createPlacementData();

        $response = $this->actingAs($this->operasional())->get(route('operasional.penempatan.index', $query));

        $response->assertOk();
        foreach ($visible as $name) {
            $response->assertSee('>'.$name.'</a>', false);
        }
        foreach ($hidden as $name) {
            $response->assertDontSee('>'.$name.'</a>', false);
        }
    }

    public function test_empty_list_and_empty_filter_results_guide_the_next_step(): void
    {
        $this->createTables();
        $this->actingAs($this->operasional());

        $this->get(route('operasional.penempatan.index'))
            ->assertOk()
            ->assertSeeText('Belum ada penempatan');
        $this->get(route('operasional.penempatan.index', ['q' => 'zzz']))
            ->assertOk()
            ->assertSeeText('Tidak ada penempatan yang cocok')
            ->assertSeeText('Tampilkan semua penempatan');
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->from(route('operasional.penempatan.index'))
            ->get(route('operasional.penempatan.index', ['status' => 'Batal']))
            ->assertSessionHasErrors(['status' => 'Filter status tidak valid.']);
    }

    public function test_detail_shows_placement_contract_schedule_and_history(): void
    {
        $this->createPlacementData();
        DB::table('tbl_kontrak')->insert(['id_penempatan' => 101, 'status' => 'Aktif']);
        $this->createSchedule(101, '2026-11-03', 'Kerja', '08:00', '16:00');
        $this->createSchedule(101, '2026-11-04', 'Libur');
        $this->createPlacement(104, 1, 2, '2025-01-01', '2025-06-30', 'Selesai');

        $response = $this->actingAs($this->operasional())->get(route('operasional.penempatan.show', 101));

        $response->assertOk()
            ->assertSeeText('Andi di PT Alpha')
            ->assertSeeTextInOrder(['Karyawan', 'Andi', 'SJI001', 'Satpam'])
            ->assertSeeTextInOrder(['Tempat kerja', 'PT Alpha', 'Pos Utama', '07:00–19:00'])
            ->assertSeeTextInOrder(['Periode & kontrak', '01/01/2026', 'tanpa batas', 'Aktif', 'Status kontrak', 'Aktif'])
            ->assertSeeText('1 hari kerja dan 1 hari libur sudah diatur.')
            ->assertSeeTextInOrder(['Riwayat penempatan Andi', 'PT Beta', '01/01/2025', '30/06/2025', 'Selesai'])
            ->assertSee('href="'.e(route('operasional.jadwal-kerja', ['id_karyawan' => 1])).'" class="btn-primary">Atur Jadwal</a>', false)
            ->assertSee('href="'.e(route('operasional.rotasi.create', ['id_penempatan' => 101])).'"', false)
            ->assertSee('href="'.route('operasional.klien.show', 1).'"', false);
    }

    public function test_finished_placement_detail_hides_rotation_and_schedule_actions(): void
    {
        $this->createPlacementData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.penempatan.show', 103))
            ->assertOk()
            ->assertSeeText('Status: Dirotasi')
            ->assertSeeText('Belum ada kontrak')
            ->assertDontSee('>Rotasi Karyawan</a>', false)
            ->assertDontSee('>Atur Jadwal</a>', false)
            ->assertDontSee(route('operasional.penempatan.akhiri', 103), false);
    }

    public function test_active_placement_detail_offers_ending_no_later_than_today(): void
    {
        $this->createPlacementData();

        $html = $this->actingAs($this->operasional())
            ->get(route('operasional.penempatan.show', 101))
            ->assertOk()
            ->assertSeeText('Akhiri penempatan')
            ->getContent();

        $this->assertStringContainsString('action="'.route('operasional.penempatan.akhiri', 101).'"', $html);
        $this->assertMatchesRegularExpression('/name="tgl_selesai" required\s+value="2026-11-17" min="2026-01-01" max="2026-11-17"/', $html);
    }

    public function test_ending_a_placement_after_today_is_rejected_before_calling_the_database(): void
    {
        $this->createPlacementData();

        $this->actingAs($this->operasional())
            ->from(route('operasional.penempatan.show', 101))
            ->post(route('operasional.penempatan.akhiri', 101), ['tgl_selesai' => '2026-11-18'])
            ->assertRedirect(route('operasional.penempatan.show', 101))
            ->assertSessionHasErrors(['tgl_selesai' => 'Tanggal selesai tidak boleh melewati hari ini. Penempatan langsung berakhir saat diproses.']);

        $this->assertSame('Aktif', DB::table('tbl_penempatan')->where('id', 101)->value('status'));
    }

    public function test_ending_an_unknown_placement_returns_not_found(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->post(route('operasional.penempatan.akhiri', 999), ['tgl_selesai' => '2026-11-17'])
            ->assertNotFound();
    }

    public function test_new_placement_cannot_start_before_the_previous_placement_ended(): void
    {
        $this->createPlacementData();

        $this->actingAs($this->operasional())
            ->from(route('operasional.penempatan.create'))
            ->post(route('operasional.penempatan.store'), [
                'id_karyawan' => 3,
                'id_klien' => 2,
                'tgl_mulai' => '2025-12-01',
                'lokasi' => 'Gudang Barat',
                'jam_datang' => '08:00',
                'jam_pulang' => '16:00',
            ])
            ->assertRedirect(route('operasional.penempatan.create'))
            ->assertSessionHasErrors(['tgl_mulai' => 'Tanggal mulai harus setelah penempatan sebelumnya berakhir (31 Desember 2025), agar gaji dan tagihan tidak terhitung dua kali.']);

        $this->assertSame(3, DB::table('tbl_penempatan')->count());
    }

    public function test_new_placement_end_date_cannot_precede_its_start_date(): void
    {
        $this->createPlacementData();

        $this->actingAs($this->operasional())
            ->from(route('operasional.penempatan.create'))
            ->post(route('operasional.penempatan.store'), [
                'id_karyawan' => 3,
                'id_klien' => 2,
                'tgl_mulai' => '2026-12-01',
                'tgl_selesai' => '2026-11-30',
                'lokasi' => 'Gudang Barat',
                'jam_datang' => '08:00',
                'jam_pulang' => '16:00',
            ])
            ->assertRedirect(route('operasional.penempatan.create'))
            ->assertSessionHasErrors(['tgl_selesai' => 'Tanggal selesai tidak boleh lebih awal daripada tanggal mulai.']);

        $this->assertSame(3, DB::table('tbl_penempatan')->count());
    }

    public function test_unknown_placement_returns_not_found(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())->get(route('operasional.penempatan.show', 999))->assertNotFound();
    }

    public function test_create_form_is_one_page_with_hrd_service_type_and_preselected_client(): void
    {
        $this->createPlacementData();
        $this->createEmployee('SJI009', 'Eka', 2);
        DB::table('tbl_karyawan')->where('nip', 'SJI001')->update(['status' => 'Ditempatkan']);

        $response = $this->actingAs($this->operasional())->get(route('operasional.penempatan.create', ['id_klien' => 2]));

        $response->assertOk()
            ->assertSeeTextInOrder(['Karyawan', 'Klien & lokasi', 'Periode & jam kerja', 'Simpan Penempatan'])
            ->assertDontSee('data-langkah=', false)
            ->assertDontSee('>Lanjut</button>', false)
            ->assertSee('action="'.route('operasional.penempatan.store').'"', false)
            ->assertSee('data-layanan="Driver"', false)
            ->assertSeeText('SJI009 — Eka')
            ->assertDontSeeText('SJI001 — Andi')
            ->assertSee('<option value="2" selected>', false)
            ->assertSee('name="id_karyawan"', false)
            ->assertSee('name="lokasi"', false)
            ->assertSee('name="jam_datang"', false)
            ->assertSee('name="tgl_selesai"', false)
            ->assertDontSeeText('Saya sudah memeriksa');
    }

    public function test_create_form_cannot_be_saved_without_available_employees(): void
    {
        $this->createTables();
        $this->createClient('KL001');

        $this->actingAs($this->operasional())
            ->get(route('operasional.penempatan.create'))
            ->assertOk()
            ->assertSeeText('Tidak ada karyawan lapangan berstatus Tersedia.')
            ->assertSee('<button type="submit" class="btn-primary" disabled>Simpan Penempatan</button>', false);
    }

    public function test_sidebar_penempatan_opens_the_list(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->get(route('operasional.penempatan.create'))
            ->assertOk()
            ->assertSee('href="'.route('operasional.penempatan.index').'"', false);
    }

    /**
     * Klien 1 = PT Alpha, klien 2 = PT Beta. Satpam = layanan 1, Driver = layanan 2.
     * Andi (Satpam) Aktif di Alpha, Budi (Driver) Aktif di Beta, Citra (Satpam) dirotasi dari Alpha.
     */
    private function createPlacementData(): void
    {
        $this->createTables();
        DB::table('tbl_klien')->insert([
            ['id' => 1, 'kode_klien' => 'KL001', 'nama_perusahaan' => 'PT Alpha', 'status' => 'Aktif'],
            ['id' => 2, 'kode_klien' => 'KL002', 'nama_perusahaan' => 'PT Beta', 'status' => 'Aktif'],
        ]);
        $this->createService('Satpam');
        $this->createService('Driver');
        $this->createEmployee('SJI001', 'Andi', 1);
        $this->createEmployee('SJI002', 'Budi', 2);
        $this->createEmployee('SJI003', 'Citra', 1);
        $this->createPlacement(101, 1, 1, '2026-01-01');
        $this->createPlacement(102, 2, 2, '2026-03-01', '2026-12-31');
        $this->createPlacement(103, 3, 1, '2025-01-01', '2025-12-31', 'Rotasi');
        DB::table('tbl_penempatan')->where('id', 101)->update(['lokasi_kerja' => 'Pos Utama', 'jam_datang' => '07:00:00', 'jam_pulang' => '19:00:00']);
        DB::table('tbl_penempatan')->where('id', 102)->update(['lokasi_kerja' => 'Gudang Timur']);
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}

<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\Concerns\CreatesTemplateJadwalTables;
use Tests\TestCase;

class KlienControllerTest extends TestCase
{
    use CreatesSjiAuthTables;
    use CreatesTemplateJadwalTables;

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, int>}>
     */
    public static function clientRoutes(): array
    {
        return [
            'index' => ['get', 'operasional.klien.index', []],
            'show' => ['get', 'operasional.klien.show', ['klien' => 1]],
            'status' => ['patch', 'operasional.klien.status', ['klien' => 1]],
        ];
    }

    /**
     * @param  array<string, int>  $parameters
     */
    #[DataProvider('clientRoutes')]
    public function test_guest_is_redirected_to_login(string $method, string $routeName, array $parameters): void
    {
        $this->{$method}(route($routeName, $parameters))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, int>}>
     */
    public static function forbiddenRolesAndRoutes(): array
    {
        $cases = [];

        foreach ([Role::Hrd, Role::Keuangan, Role::Ceo] as $role) {
            foreach (self::clientRoutes() as $key => [$method, $routeName, $parameters]) {
                $cases["{$role} {$key}"] = [$role, $method, $routeName, $parameters];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, int>  $parameters
     */
    #[DataProvider('forbiddenRolesAndRoutes')]
    public function test_other_roles_are_forbidden(string $role, string $method, string $routeName, array $parameters): void
    {
        $this->createClientData();

        $this->actingAs(User::factory()->withRole($role)->create())
            ->{$method}(route($routeName, $parameters), ['status' => 'Nonaktif'])
            ->assertForbidden();

        $this->assertDatabaseHas('tbl_klien', ['id' => 1, 'status' => 'Aktif']);
    }

    public function test_list_shows_service_types_active_employees_and_status_counts(): void
    {
        $this->createClientData();

        $response = $this->actingAs($this->operasional())->get(route('operasional.klien.index'));

        $response->assertOk()
            ->assertSeeText('Semua (2)')
            ->assertSeeText('Aktif (1)')
            ->assertSeeText('Nonaktif (1)')
            ->assertSeeTextInOrder(['PT Alpha', 'Bu Rina', 'Driver', 'Satpam', '2 orang', 'Aktif'])
            ->assertSeeTextInOrder(['PT Beta', '0 orang', 'Nonaktif'])
            ->assertSee('href="'.route('operasional.klien.show', 1).'"', false)
            ->assertDontSee('Hapus</button>', false);
    }

    public function test_search_and_status_filter_narrow_the_list(): void
    {
        $this->createClientData();
        $this->actingAs($this->operasional());

        $this->get(route('operasional.klien.index', ['q' => 'beta']))
            ->assertOk()
            ->assertSee('PT Beta')
            ->assertDontSee('PT Alpha');
        $this->get(route('operasional.klien.index', ['q' => 'KL001']))
            ->assertOk()
            ->assertSee('PT Alpha')
            ->assertDontSee('PT Beta');
        $this->get(route('operasional.klien.index', ['status' => 'Nonaktif']))
            ->assertOk()
            ->assertSee('PT Beta')
            ->assertDontSee('PT Alpha');
    }

    public function test_empty_results_and_empty_data_guide_the_next_step(): void
    {
        $this->createTables();
        $this->actingAs($this->operasional());

        $this->get(route('operasional.klien.index'))
            ->assertOk()
            ->assertSeeText('Belum ada klien')
            ->assertSee('href="'.route('operasional.klien.create').'" class="btn-primary btn-sm">+ Tambah Klien</a>', false);
        $this->get(route('operasional.klien.index', ['q' => 'tidak ada']))
            ->assertOk()
            ->assertSeeText('Tidak ada klien yang cocok')
            ->assertSeeText('Tampilkan semua klien');
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->from(route('operasional.klien.index'))
            ->get(route('operasional.klien.index', ['status' => 'Hapus']))
            ->assertRedirect(route('operasional.klien.index'))
            ->assertSessionHasErrors(['status' => 'Filter status tidak valid.']);
    }

    public function test_detail_shows_all_five_sections_from_real_relations(): void
    {
        $this->createClientData();

        $response = $this->actingAs($this->operasional())->get(route('operasional.klien.show', 1));

        $response->assertOk()
            ->assertSeeTextInOrder(['Informasi perusahaan', 'PT Alpha', 'Jl. Merdeka 1'])
            ->assertSeeTextInOrder(['Kontak perusahaan', 'Bu Rina', 'HRD Manager', '0811', 'rina@alpha.test'])
            ->assertSeeTextInOrder(['Jenis layanan', 'Driver', '1 karyawan', 'Satpam', '1 karyawan'])
            ->assertSeeTextInOrder(['Karyawan yang ditempatkan', '2 karyawan dengan penempatan Aktif.', 'Andi', 'Budi'])
            ->assertSeeTextInOrder(['Riwayat kerja sama', 'Penempatan pertama dimulai 01/01/2025.', 'Citra', 'Dirotasi'])
            ->assertDontSeeText('1. Informasi perusahaan')
            ->assertSee('href="'.e(route('operasional.jadwal-kerja', ['id_karyawan' => 1])).'"', false)
            ->assertSee('action="'.route('operasional.klien.status', 1).'"', false)
            ->assertSee('name="status" value="Nonaktif"', false);
    }

    public function test_unknown_client_detail_returns_not_found(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())->get(route('operasional.klien.show', 999))->assertNotFound();
    }

    public function test_deactivating_and_reactivating_keeps_data_and_placements(): void
    {
        $this->createClientData();
        $this->actingAs($this->operasional());

        $this->from(route('operasional.klien.show', 1))
            ->patch(route('operasional.klien.status', 1), ['status' => 'Nonaktif'])
            ->assertRedirect(route('operasional.klien.show', 1))
            ->assertSessionHas('success', 'Kerja sama dengan PT Alpha dinonaktifkan. Data dan riwayatnya tetap tersimpan.');
        $this->assertDatabaseHas('tbl_klien', ['id' => 1, 'status' => 'Nonaktif', 'nama_perusahaan' => 'PT Alpha']);
        $this->assertSame(2, DB::table('tbl_penempatan')->where('id_klien', 1)->where('status', 'Aktif')->count());

        $this->from(route('operasional.klien.show', 1))
            ->patch(route('operasional.klien.status', 1), ['status' => 'Aktif'])
            ->assertSessionHas('success', 'Kerja sama dengan PT Alpha diaktifkan kembali.');
        $this->assertDatabaseHas('tbl_klien', ['id' => 1, 'status' => 'Aktif']);
    }

    public function test_status_change_rejects_an_unknown_value(): void
    {
        $this->createClientData();

        $this->actingAs($this->operasional())
            ->from(route('operasional.klien.show', 1))
            ->patch(route('operasional.klien.status', 1), ['status' => 'Dihapus'])
            ->assertSessionHasErrors(['status' => 'Status klien tidak valid.']);

        $this->assertDatabaseHas('tbl_klien', ['id' => 1, 'status' => 'Aktif']);
    }

    public function test_new_client_opens_its_detail_with_the_next_step(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->post(route('operasional.klien.store'), [
                'kode_klien' => 'KL009', 'nama_perusahaan' => 'PT Baru', 'alamat' => 'Jl. Baru', 'pic_nama' => 'Pak Dodi',
                'pic_telp' => '0812', 'status' => 'Aktif',
            ])
            ->assertRedirect(route('operasional.klien.show', 1))
            ->assertSessionHas('success', 'Klien berhasil ditambahkan. Langkah berikutnya: buat penempatan karyawan untuk klien ini.');

        $this->assertDatabaseHas('tbl_klien', ['kode_klien' => 'KL009', 'nama_perusahaan' => 'PT Baru']);
    }

    public function test_form_is_grouped_into_three_sections_without_tutorial_text(): void
    {
        $this->createClientData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.klien.edit', 1))
            ->assertOk()
            ->assertSeeTextInOrder(['Informasi perusahaan', 'Kode Klien', 'Kontak perusahaan', 'Nama Penanggung Jawab (PIC)', 'Status kerja sama'])
            ->assertSee('placeholder="Contoh: KL001"', false)
            ->assertDontSeeText('1. Informasi perusahaan')
            ->assertDontSeeText('Orang yang dihubungi untuk urusan penempatan')
            ->assertSee('action="'.route('operasional.klien.update', 1).'"', false)
            ->assertSee('href="'.route('operasional.klien.show', 1).'" class="btn-secondary">Batal</a>', false);
    }

    /**
     * PT Alpha (Aktif): Andi (Satpam) & Budi (Driver) Aktif, Citra (Satpam) sudah dirotasi.
     * PT Beta (Nonaktif): tanpa penempatan.
     */
    private function createClientData(): void
    {
        $this->createTables();
        DB::table('tbl_klien')->insert([
            ['id' => 1, 'kode_klien' => 'KL001', 'nama_perusahaan' => 'PT Alpha', 'alamat' => 'Jl. Merdeka 1', 'pic_nama' => 'Bu Rina',
                'pic_jabatan' => 'HRD Manager', 'pic_telp' => '0811', 'pic_email' => 'rina@alpha.test', 'status' => 'Aktif'],
            ['id' => 2, 'kode_klien' => 'KL002', 'nama_perusahaan' => 'PT Beta', 'alamat' => 'Jl. Sudirman 2', 'pic_nama' => 'Pak Joko',
                'pic_jabatan' => null, 'pic_telp' => '0822', 'pic_email' => null, 'status' => 'Nonaktif'],
        ]);
        $satpamId = $this->createService('Satpam');
        $driverId = $this->createService('Driver');
        $this->createPlacement(101, $this->createEmployee('SJI001', 'Andi', $satpamId), 1, '2026-01-01');
        $this->createPlacement(102, $this->createEmployee('SJI002', 'Budi', $driverId), 1, '2026-03-01', '2026-12-31');
        $this->createPlacement(103, $this->createEmployee('SJI003', 'Citra', $satpamId), 1, '2025-01-01', '2025-12-31', 'Rotasi');
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}

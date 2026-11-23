<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_management_fee_is_saved_with_two_decimals_and_shown_on_the_detail(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->post(route('operasional.klien.store'), [...$this->newClientInput('KL010'), 'management_fee' => '7,5'])
            ->assertRedirect(route('operasional.klien.show', 1));

        $this->assertEquals(7.5, DB::table('tbl_klien')->where('kode_klien', 'KL010')->value('management_fee'));

        $this->get(route('operasional.klien.show', 1))
            ->assertOk()
            ->assertSeeTextInOrder(['Management fee', '7,50%']);
    }

    public function test_empty_management_fee_stays_undetermined_and_differs_from_zero_percent(): void
    {
        $this->createTables();
        $this->actingAs($this->operasional());

        $this->post(route('operasional.klien.store'), [...$this->newClientInput('KL011'), 'management_fee' => ''])->assertSessionHasNoErrors();
        $this->post(route('operasional.klien.store'), [...$this->newClientInput('KL012'), 'management_fee' => '0'])->assertSessionHasNoErrors();

        $this->assertNull(DB::table('tbl_klien')->where('kode_klien', 'KL011')->value('management_fee'));
        $this->assertEquals(0, DB::table('tbl_klien')->where('kode_klien', 'KL012')->value('management_fee'));
        $this->assertNotNull(DB::table('tbl_klien')->where('kode_klien', 'KL012')->value('management_fee'));

        $this->get(route('operasional.klien.show', 1))->assertSeeTextInOrder(['Management fee', 'Belum ditentukan']);
        $this->get(route('operasional.klien.show', 2))->assertSeeTextInOrder(['Management fee', '0,00%'])->assertDontSeeText('Belum ditentukan');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidManagementFees(): array
    {
        return [
            'negatif' => ['-0.01', 'Management Fee tidak boleh kurang dari 0%.'],
            'lebih dari 100' => ['100.01', 'Management Fee tidak boleh lebih dari 100%.'],
            'tiga desimal' => ['7.555', 'Management Fee maksimal dua angka di belakang koma.'],
            'bukan angka' => ['tujuh', 'Management Fee harus berupa angka.'],
        ];
    }

    #[DataProvider('invalidManagementFees')]
    public function test_invalid_management_fee_is_rejected_without_saving(string $nilai, string $pesan): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->from(route('operasional.klien.create'))
            ->post(route('operasional.klien.store'), [...$this->newClientInput('KL013'), 'management_fee' => $nilai])
            ->assertRedirect(route('operasional.klien.create'))
            ->assertSessionHasErrors(['management_fee' => $pesan]);

        $this->assertDatabaseCount('tbl_klien', 0);
    }

    public function test_edit_form_shows_the_saved_fee_and_update_can_change_or_clear_it(): void
    {
        $this->createClientData();
        DB::table('tbl_klien')->where('id', 1)->update(['management_fee' => 5]);
        $this->actingAs($this->operasional());

        $this->get(route('operasional.klien.edit', 1))
            ->assertOk()
            ->assertSee('name="management_fee"', false)
            ->assertSeeTextInOrder(['Status kerja sama', 'Management Fee (opsional)']);

        $inputAlpha = [...$this->newClientInput('KL001'), 'nama_perusahaan' => 'PT Alpha'];

        $this->put(route('operasional.klien.update', 1), [...$inputAlpha, 'management_fee' => '12.25'])->assertSessionHasNoErrors();
        $this->assertEquals(12.25, DB::table('tbl_klien')->where('id', 1)->value('management_fee'));

        $this->put(route('operasional.klien.update', 1), [...$inputAlpha, 'management_fee' => ''])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('tbl_klien')->where('id', 1)->value('management_fee'));
    }

    public function test_client_pages_keep_working_and_warn_when_the_fee_column_is_not_migrated(): void
    {
        $this->createClientData();
        Schema::table('tbl_klien', fn (Blueprint $table) => $table->dropColumn('management_fee'));
        $this->actingAs($this->operasional());

        $this->get(route('operasional.klien.create'))
            ->assertOk()
            ->assertDontSee('name="management_fee"', false)
            ->assertSeeText('Isian Management Fee belum tersedia karena database belum dimigrasi.');
        $this->get(route('operasional.klien.show', 1))
            ->assertOk()
            ->assertSeeText('Belum tersedia — database belum dimigrasi');

        $this->post(route('operasional.klien.store'), [...$this->newClientInput('KL014'), 'management_fee' => '5'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertDatabaseHas('tbl_klien', ['kode_klien' => 'KL014']);
    }

    public function test_percentage_bpjs_is_saved_and_shown_on_the_detail(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->post(route('operasional.klien.store'), [...$this->newClientInput('KL020'), 'bpjs_metode' => 'Persentase', 'bpjs_nilai' => '4,5'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('operasional.klien.show', 1));

        $this->assertDatabaseHas('tbl_klien', ['kode_klien' => 'KL020', 'bpjs_metode' => 'Persentase', 'bpjs_nilai' => 4.5]);
        $this->get(route('operasional.klien.show', 1))
            ->assertOk()
            ->assertSeeTextInOrder(['BPJS', '4,5% dari gaji']);
    }

    public function test_fixed_bpjs_can_be_changed_and_cleared_from_the_edit_form(): void
    {
        $this->createClientData();
        DB::table('tbl_klien')->where('id', 1)->update(['bpjs_metode' => 'Nominal', 'bpjs_nilai' => 100000]);
        $this->actingAs($this->operasional());
        $inputAlpha = [...$this->newClientInput('KL001'), 'nama_perusahaan' => 'PT Alpha'];

        $this->get(route('operasional.klien.edit', 1))
            ->assertOk()
            ->assertSeeTextInOrder(['Pengaturan BPJS', 'Metode Perhitungan', 'Nilai BPJS'])
            ->assertSee('value="Nominal" class="form-check" data-bpjs-metode checked', false)
            ->assertSee('value="100000', false);
        $this->get(route('operasional.klien.show', 1))->assertSeeTextInOrder(['BPJS', 'Rp 100.000 per tenaga']);

        $this->put(route('operasional.klien.update', 1), [...$inputAlpha, 'bpjs_metode' => 'Persentase', 'bpjs_nilai' => '5'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tbl_klien', ['id' => 1, 'bpjs_metode' => 'Persentase', 'bpjs_nilai' => 5]);

        $this->put(route('operasional.klien.update', 1), [...$inputAlpha, 'bpjs_metode' => '', 'bpjs_nilai' => '5'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tbl_klien', ['id' => 1, 'bpjs_metode' => null, 'bpjs_nilai' => null]);
        $this->get(route('operasional.klien.show', 1))->assertSeeTextInOrder(['BPJS', 'Tanpa BPJS']);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidBpjsSettings(): array
    {
        return [
            'persentase di atas 100' => [['bpjs_metode' => 'Persentase', 'bpjs_nilai' => '100.01'], 'Persentase BPJS tidak boleh lebih dari 100%.'],
            'nilai kosong' => [['bpjs_metode' => 'Nominal', 'bpjs_nilai' => ''], 'Nilai BPJS wajib diisi untuk metode yang dipilih.'],
            'nominal nol' => [['bpjs_metode' => 'Nominal', 'bpjs_nilai' => '0'], 'Nilai BPJS harus lebih dari 0. Pilih "Tanpa BPJS" bila klien tidak memakai BPJS.'],
            'tiga desimal' => [['bpjs_metode' => 'Persentase', 'bpjs_nilai' => '4.555'], 'Nilai BPJS maksimal dua angka di belakang koma.'],
        ];
    }

    /**
     * @param  array<string, string>  $pengaturan
     */
    #[DataProvider('invalidBpjsSettings')]
    public function test_invalid_bpjs_setting_is_rejected_without_saving(array $pengaturan, string $pesan): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->from(route('operasional.klien.create'))
            ->post(route('operasional.klien.store'), [...$this->newClientInput('KL021'), ...$pengaturan])
            ->assertRedirect(route('operasional.klien.create'))
            ->assertSessionHasErrors(['bpjs_nilai' => $pesan]);

        $this->assertDatabaseCount('tbl_klien', 0);
    }

    public function test_unknown_bpjs_method_is_rejected(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->post(route('operasional.klien.store'), [...$this->newClientInput('KL022'), 'bpjs_metode' => 'Kesehatan', 'bpjs_nilai' => '4'])
            ->assertSessionHasErrors(['bpjs_metode' => 'Metode BPJS tidak valid.']);

        $this->assertDatabaseCount('tbl_klien', 0);
    }

    /**
     * @return array<string, string>
     */
    private function newClientInput(string $kode): array
    {
        return [
            'kode_klien' => $kode, 'nama_perusahaan' => 'PT '.$kode, 'alamat' => 'Jl. Baru', 'pic_nama' => 'Pak Dodi',
            'pic_telp' => '0812', 'status' => 'Aktif',
        ];
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

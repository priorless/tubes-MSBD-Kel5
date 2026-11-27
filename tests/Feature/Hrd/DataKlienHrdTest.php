<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\Concerns\CreatesTemplateJadwalTables;
use Tests\TestCase;

/**
 * Data Klien di modul HRD memakai controller, tampilan, dan tabel tbl_klien yang sama dengan Operasional.
 */
class DataKlienHrdTest extends TestCase
{
    use CreatesSjiAuthTables;
    use CreatesTemplateJadwalTables;

    public function test_hrd_sees_clients_created_by_operasional_with_links_inside_the_hrd_module(): void
    {
        $this->createClientData();

        $this->actingAs($this->operasional())
            ->post(route('operasional.klien.store'), $this->clientInput('KL010'))
            ->assertRedirect(route('operasional.klien.show', 2));

        $this->actingAs($this->hrd())
            ->get(route('hrd.klien.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['Semua (2)', 'PT Alpha', 'PT KL010'])
            ->assertSee('href="'.route('hrd.klien.index').'"', false)
            ->assertSee('href="'.route('hrd.klien.create').'"', false)
            ->assertSee('href="'.route('hrd.klien.show', 2).'"', false)
            ->assertSee('href="'.route('hrd.klien.edit', 2).'"', false)
            ->assertDontSee(route('operasional.klien.index'), false);
    }

    public function test_client_created_by_hrd_is_stored_once_and_visible_to_operasional(): void
    {
        $this->createTables();

        $this->actingAs($this->hrd())
            ->post(route('hrd.klien.store'), $this->clientInput('KL020'))
            ->assertRedirect(route('hrd.klien.show', 1))
            ->assertSessionHas('success', 'Klien berhasil ditambahkan. Langkah berikutnya: buat penempatan karyawan untuk klien ini.');

        $this->assertDatabaseCount('tbl_klien', 1);
        $this->assertDatabaseHas('tbl_klien', ['kode_klien' => 'KL020', 'nama_perusahaan' => 'PT KL020', 'status' => 'Aktif']);

        $this->actingAs($this->operasional())
            ->get(route('operasional.klien.index'))
            ->assertOk()
            ->assertSeeText('PT KL020');
    }

    public function test_hrd_store_applies_the_same_validation_as_operasional(): void
    {
        $this->createClientData();

        $this->actingAs($this->hrd())
            ->from(route('hrd.klien.create'))
            ->post(route('hrd.klien.store'), ['kode_klien' => 'KL001'])
            ->assertRedirect(route('hrd.klien.create'))
            ->assertSessionHasErrors([
                'kode_klien' => 'Kode klien sudah digunakan.',
                'nama_perusahaan' => 'Nama perusahaan wajib diisi.',
                'pic_telp' => 'Nomor telepon PIC wajib diisi.',
            ]);

        $this->assertDatabaseCount('tbl_klien', 1);
    }

    public function test_hrd_detail_shows_client_data_without_operasional_only_actions(): void
    {
        $this->createClientData();

        $this->actingAs($this->hrd())
            ->get(route('hrd.klien.show', 1))
            ->assertOk()
            ->assertSeeTextInOrder(['Informasi perusahaan', 'PT Alpha', 'Kontak perusahaan', 'Bu Rina', 'Karyawan yang ditempatkan', 'Andi'])
            ->assertSee('href="'.route('hrd.klien.edit', 1).'"', false)
            ->assertSee('action="'.route('hrd.klien.status', 1).'"', false)
            ->assertDontSee(route('operasional.penempatan.create', ['id_klien' => 1]), false)
            ->assertDontSee(route('operasional.jadwal-kerja', ['id_karyawan' => 1]), false)
            ->assertDontSeeText('Lihat Jadwal');
    }

    public function test_operasional_detail_keeps_its_placement_and_schedule_actions(): void
    {
        $this->createClientData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.klien.show', 1))
            ->assertOk()
            ->assertSee('href="'.e(route('operasional.penempatan.create', ['id_klien' => 1])).'"', false)
            ->assertSee('href="'.e(route('operasional.jadwal-kerja', ['id_karyawan' => 1])).'"', false)
            ->assertSee('action="'.route('operasional.klien.status', 1).'"', false);
    }

    public function test_hrd_edits_a_client_and_changes_its_status_in_the_shared_table(): void
    {
        $this->createClientData();
        $this->actingAs($this->hrd());

        $this->get(route('hrd.klien.edit', 1))
            ->assertOk()
            ->assertSee('action="'.route('hrd.klien.update', 1).'"', false)
            ->assertSee('href="'.route('hrd.klien.show', 1).'" class="btn-secondary">Batal</a>', false);

        $this->put(route('hrd.klien.update', 1), [...$this->clientInput('KL001'), 'nama_perusahaan' => 'PT Alpha Baru'])
            ->assertRedirect(route('hrd.klien.show', 1))
            ->assertSessionHas('success', 'Data klien berhasil diperbarui.');

        $this->from(route('hrd.klien.show', 1))
            ->patch(route('hrd.klien.status', 1), ['status' => 'Nonaktif'])
            ->assertRedirect(route('hrd.klien.show', 1))
            ->assertSessionHas('success', 'Kerja sama dengan PT Alpha Baru dinonaktifkan. Data dan riwayatnya tetap tersimpan.');

        $this->assertDatabaseHas('tbl_klien', ['id' => 1, 'kode_klien' => 'KL001', 'nama_perusahaan' => 'PT Alpha Baru', 'status' => 'Nonaktif']);
    }

    public function test_hrd_sidebar_shows_data_klien_as_active_and_operasional_keeps_its_own_menu(): void
    {
        $this->createClientData();
        $navigasi = static function (string $html): string {
            preg_match('/<nav[^>]*aria-label="Menu utama"[^>]*>(.*?)<\/nav>/s', $html, $nav);

            return $nav[1] ?? '';
        };

        $navHrd = $navigasi($this->actingAs($this->hrd())->get(route('hrd.klien.show', 1))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('hrd.klien.index'), '/').'"[^>]*aria-current="page"/s', $navHrd);
        $this->assertStringNotContainsString(route('operasional.klien.index'), $navHrd);

        $navOperasional = $navigasi($this->actingAs($this->operasional())->get(route('operasional.klien.index'))->assertOk()->getContent());
        $this->assertStringContainsString('href="'.route('operasional.klien.index').'"', $navOperasional);
        $this->assertStringNotContainsString(route('hrd.klien.index'), $navOperasional);
    }

    public function test_hrd_cannot_delete_a_client(): void
    {
        $this->createTables();
        DB::table('tbl_klien')->insert(['id' => 5, ...$this->clientInput('KL005')]);

        $this->actingAs($this->hrd())
            ->delete('/hrd/klien/5')
            ->assertStatus(405);

        $this->assertDatabaseHas('tbl_klien', ['id' => 5]);
    }

    #[TestWith([Role::Keuangan])]
    #[TestWith([Role::Ceo])]
    #[TestWith([Role::Operasional])]
    public function test_roles_other_than_hrd_cannot_open_the_hrd_client_pages(string $role): void
    {
        $this->createClientData();

        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('hrd.klien.index'))
            ->assertForbidden();
    }

    /**
     * @return array<string, string>
     */
    private function clientInput(string $kode): array
    {
        return [
            'kode_klien' => $kode, 'nama_perusahaan' => 'PT '.$kode, 'alamat' => 'Jl. Baru', 'pic_nama' => 'Pak Dodi',
            'pic_telp' => '0812', 'status' => 'Aktif',
        ];
    }

    /**
     * PT Alpha (Aktif) dengan Andi (Satpam) yang sedang ditempatkan.
     */
    private function createClientData(): void
    {
        $this->createTables();
        DB::table('tbl_klien')->insert([
            'id' => 1, 'kode_klien' => 'KL001', 'nama_perusahaan' => 'PT Alpha', 'alamat' => 'Jl. Merdeka 1', 'pic_nama' => 'Bu Rina',
            'pic_jabatan' => 'HRD Manager', 'pic_telp' => '0811', 'pic_email' => 'rina@alpha.test', 'status' => 'Aktif',
        ]);
        $this->createPlacement(101, $this->createEmployee('SJI001', 'Andi', $this->createService('Satpam')), 1, '2026-01-01');
    }

    private function hrd(): User
    {
        return User::factory()->withRole(Role::Hrd)->create();
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}

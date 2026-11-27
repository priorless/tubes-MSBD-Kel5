<?php

namespace Tests\Feature\Ceo;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\Concerns\CreatesTemplateJadwalTables;
use Tests\TestCase;

/**
 * Halaman Klien CEO (hanya baca): jumlah klien, jumlah & nama karyawan yang sedang ditempatkan per klien.
 */
class KlienCeoTest extends TestCase
{
    use CreatesSjiAuthTables;
    use CreatesTemplateJadwalTables;

    public function test_ceo_sees_clients_with_only_actively_placed_employees(): void
    {
        $this->createTables();
        $alpha = $this->createClient('KL001');
        $beta = $this->createClient('KL002');
        DB::table('tbl_klien')->where('id', $beta)->update(['status' => 'Nonaktif']);
        $satpam = $this->createService('Satpam');

        $this->createPlacement(1, $this->createEmployee('SJI001', 'Andi', $satpam), $alpha, '2026-01-01');
        $this->createPlacement(2, $this->createEmployee('SJI002', 'Budi', $satpam), $alpha, '2026-02-01');
        $this->createPlacement(3, $this->createEmployee('SJI003', 'Citra Selesai', $satpam), $alpha, '2025-01-01', '2025-12-31', 'Selesai');

        $this->actingAs(User::factory()->withRole(Role::Ceo)->create())
            ->get(route('ceo.klien'))
            ->assertOk()
            ->assertSeeTextInOrder(['Jumlah klien', '2', 'Klien aktif', '1', 'Orang ditempatkan', '2'])
            ->assertSeeTextInOrder(['Klien KL001', 'Aktif', '2 orang', 'Andi', 'Budi', 'Klien KL002', 'Nonaktif', '0 orang', 'Belum ada yang ditempatkan'])
            ->assertDontSeeText('Citra Selesai')
            ->assertDontSee(route('hrd.klien.create'), false)
            ->assertDontSee(route('operasional.klien.create'), false)
            ->assertDontSeeText('profit');
    }

    public function test_roles_other_than_ceo_cannot_open_the_ceo_client_page(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Hrd)->create())
            ->get(route('ceo.klien'))
            ->assertForbidden();

        $this->actingAs(User::factory()->withRole(Role::Keuangan)->create())
            ->get(route('ceo.klien'))
            ->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class MonitoringPageTest extends TestCase
{
    use CreatesSjiAuthTables;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('operasional.monitoring'))->assertRedirect(route('login'));
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
        $this->createMonitoringView();

        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('operasional.monitoring'))
            ->assertForbidden();
    }

    public function test_summary_counts_employees_clients_and_items_to_check(): void
    {
        $this->createMonitoringData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.monitoring'))
            ->assertOk()
            ->assertSeeTextInOrder(['Karyawan ditempatkan', '4', 'Klien yang dilayani', '2', 'Perlu diperiksa', '2']);
    }

    public function test_distribution_per_client_and_per_service_type(): void
    {
        $this->createMonitoringData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.monitoring'))
            ->assertOk()
            ->assertSeeTextInOrder(['Karyawan per klien', 'PT Alpha', '2 orang', 'PT Beta', '2 orang'])
            ->assertSeeTextInOrder(['Karyawan per jenis layanan', 'Satpam', '3 orang', 'Driver', '1 orang'])
            ->assertSee('style="width: 75%"', false)
            ->assertSee('style="width: 25%"', false);
    }

    public function test_items_to_check_list_overdue_first_then_ending_soon(): void
    {
        $this->createMonitoringData();

        $response = $this->actingAs($this->operasional())->get(route('operasional.monitoring'));

        $response->assertOk()
            ->assertSeeTextInOrder(['Perlu diperiksa', 'Citra', 'PT Beta', '14/11/2026', 'Lewat 3 hari', 'Budi', 'PT Alpha', 'Sisa 10 hari', 'Semua penempatan aktif'])
            ->assertSee('href="'.route('operasional.penempatan.show', 3).'" class="btn-secondary btn-sm">Lihat Penempatan</a>', false);
    }

    public function test_full_table_keeps_highlighting_and_a_confirmed_end_action(): void
    {
        $this->createMonitoringData();

        $html = $this->actingAs($this->operasional())->get(route('operasional.monitoring'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="table-danger"'));
        $this->assertSame(4, substr_count($html, '<summary class="btn-outline-danger btn-sm cursor-pointer list-none">Akhiri&hellip;</summary>'));
        $this->assertStringContainsString('action="'.route('operasional.penempatan.akhiri', 1).'"', $html);
        $this->assertStringContainsString('onsubmit="return confirm(', $html);
        $this->assertStringContainsString('href="'.route('operasional.penempatan.show', 1).'" class="text-sji-biru hover:underline">Andi</a>', $html);
    }

    public function test_end_date_starts_no_earlier_than_the_placement_start(): void
    {
        $this->createMonitoringView();
        $this->insertPlacement(1, 'Andi', 'PT Alpha', 'Satpam', 9999, null);
        DB::table('v_monitoring_sebaran_operasional')->where('id_penempatan', 1)->update(['tgl_mulai' => '2099-01-15']);
        $this->insertPlacement(2, 'Budi', 'PT Alpha', 'Satpam', 9999, null);

        $html = $this->actingAs($this->operasional())->get(route('operasional.monitoring'))->assertOk()->getContent();

        $hariIni = now('Asia/Jakarta')->toDateString();

        // Belum dimulai: hanya bisa dibatalkan pada tanggal mulainya.
        $this->assertMatchesRegularExpression(
            '/id="tgl_selesai_1" name="tgl_selesai" required\s+value="2099-01-15" min="2099-01-15" max="2099-01-15"/',
            $html,
        );
        // Sudah berjalan: paling lambat hari ini.
        $this->assertMatchesRegularExpression(
            '/id="tgl_selesai_2" name="tgl_selesai" required\s+value="'.$hariIni.'" min="2026-01-01" max="'.$hariIni.'"/',
            $html,
        );
    }

    public function test_empty_state_when_nothing_needs_checking(): void
    {
        $this->createMonitoringView();
        $this->insertPlacement(1, 'Andi', 'PT Alpha', 'Satpam', 9999, null);

        $this->actingAs($this->operasional())
            ->get(route('operasional.monitoring'))
            ->assertOk()
            ->assertSeeText('Tidak ada yang perlu diperiksa')
            ->assertSeeTextInOrder(['Perlu diperiksa', '0']);
    }

    /**
     * Andi (Alpha, Satpam, sisa 40), Budi (Alpha, Driver, sisa 10), Citra (Beta, Satpam, lewat 3 hari),
     * Dedi (Beta, Satpam, tanpa batas akhir).
     */
    private function createMonitoringData(): void
    {
        $this->createMonitoringView();
        $this->insertPlacement(1, 'Andi', 'PT Alpha', 'Satpam', 40, '2026-12-27');
        $this->insertPlacement(2, 'Budi', 'PT Alpha', 'Driver', 10, '2026-11-27');
        $this->insertPlacement(3, 'Citra', 'PT Beta', 'Satpam', -3, '2026-11-14');
        $this->insertPlacement(4, 'Dedi', 'PT Beta', 'Satpam', 9999, null);
    }

    /**
     * Pengganti view v_monitoring_sebaran_operasional (MariaDB) dengan kolom yang sama untuk SQLite.
     */
    private function createMonitoringView(): void
    {
        Schema::create('v_monitoring_sebaran_operasional', function (Blueprint $table): void {
            $table->integer('id_penempatan');
            $table->string('nip');
            $table->string('nama');
            $table->string('nama_layanan');
            $table->string('nama_perusahaan');
            $table->string('lokasi_kerja')->nullable();
            $table->time('jam_datang')->nullable();
            $table->time('jam_pulang')->nullable();
            $table->date('tgl_mulai');
            $table->date('tgl_selesai')->nullable();
            $table->integer('sisa_hari_kontrak');
        });
    }

    private function insertPlacement(int $id, string $name, string $client, string $service, int $remainingDays, ?string $endDate): void
    {
        DB::table('v_monitoring_sebaran_operasional')->insert([
            'id_penempatan' => $id,
            'nip' => 'SJI00'.$id,
            'nama' => $name,
            'nama_layanan' => $service,
            'nama_perusahaan' => $client,
            'lokasi_kerja' => 'Lokasi '.$id,
            'tgl_mulai' => '2026-01-01',
            'tgl_selesai' => $endDate,
            'sisa_hari_kontrak' => $remainingDays,
        ]);
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}

<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class MonitoringPageTest extends TestCase
{
    use CreatesSjiAuthTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00:00'));
    }

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

    public function test_placement_status_is_derived_from_dates(): void
    {
        $this->createMonitoringData();
        $this->insertPlacement(5, 'Eka', 'PT Alpha', 'Driver', 44, '2026-12-31', '2026-11-18');

        $response = $this->actingAs($this->operasional())->get(route('operasional.monitoring'))->assertOk();

        $response->assertSeeTextInOrder([
            'Status penempatan',
            'Berjalan', '3 penempatan',
            'Belum Mulai', '1 penempatan',
            'Lewat Kontrak', '1 penempatan',
        ]);
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/>Eka<.*?badge badge-info"[^>]*>Belum Mulai</s', $html);
        $this->assertMatchesRegularExpression('/>Citra<.*?badge badge-danger"[^>]*>Lewat Kontrak</s', $html);
        $this->assertMatchesRegularExpression('/>Dedi<.*?badge badge-success"[^>]*>Berjalan</s', $html);
    }

    public function test_placement_ending_today_is_still_running(): void
    {
        $this->createMonitoringView();
        $this->insertPlacement(1, 'Andi', 'PT Alpha', 'Satpam', 0, '2026-11-17');
        $this->insertPlacement(2, 'Budi', 'PT Alpha', 'Satpam', 30, '2026-12-17', '2026-11-17');

        $this->actingAs($this->operasional())
            ->get(route('operasional.monitoring'))
            ->assertOk()
            ->assertSeeTextInOrder(['Berjalan', '2 penempatan', 'Belum Mulai', '0 penempatan', 'Lewat Kontrak', '0 penempatan']);
    }

    public function test_every_service_type_is_listed_including_those_without_employees(): void
    {
        $this->createMonitoringData();
        $this->insertPlacement(5, 'Eka', 'PT Alpha', null, 40, '2026-12-27');

        $this->actingAs($this->operasional())
            ->get(route('operasional.monitoring'))
            ->assertOk()
            ->assertSeeTextInOrder(['Karyawan per jenis layanan', 'Satpam', '3 orang', 'Belum diisi HRD', '1 orang', 'Driver', '1 orang', 'Cleaning Service', '0 orang', 'Status penempatan']);
    }

    public function test_client_filter_narrows_summary_and_table(): void
    {
        $this->createMonitoringData();

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.monitoring', ['id_klien' => 2]))
            ->assertOk()
            ->assertSee('<option value="2" selected>', false)
            ->assertSeeTextInOrder(['Karyawan ditempatkan', '2', 'Klien yang dilayani', '1', 'Perlu diperiksa', '1'])
            ->assertSeeTextInOrder(['Karyawan per jenis layanan', 'Satpam', '2 orang', 'Driver', '0 orang'])
            ->assertSeeText('Citra')
            ->assertSeeText('Dedi')
            ->assertDontSeeText('Andi')
            ->assertDontSeeText('Budi');

        $response->assertSee('href="'.route('operasional.monitoring').'" class="btn-secondary">Reset</a>', false);
    }

    public function test_service_type_filter_shows_only_that_service(): void
    {
        $this->createMonitoringData();

        $html = $this->actingAs($this->operasional())
            ->get(route('operasional.monitoring', ['id_jenis_layanan' => 2]))
            ->assertOk()
            ->assertSeeTextInOrder(['Karyawan per jenis layanan', 'Driver', '1 orang', 'Status penempatan'])
            ->assertSeeText('Budi')
            ->assertDontSeeText('Andi')
            ->getContent();

        $bagianLayanan = str($html)->after('Karyawan per jenis layanan')->before('Status penempatan')->toString();
        $this->assertStringNotContainsString('Satpam', $bagianLayanan);
        $this->assertStringNotContainsString('Cleaning Service', $bagianLayanan);
    }

    public function test_combined_filter_without_results_offers_a_way_back(): void
    {
        $this->createMonitoringData();

        $this->actingAs($this->operasional())
            ->get(route('operasional.monitoring', ['id_klien' => 2, 'id_jenis_layanan' => 2]))
            ->assertOk()
            ->assertSeeText('Tidak ada penempatan aktif yang sesuai filter.')
            ->assertSee('href="'.route('operasional.monitoring').'" class="text-sji-biru hover:underline">Tampilkan semua</a>', false);
    }

    public function test_pagination_keeps_the_active_filter(): void
    {
        $this->createMonitoringView();
        foreach (range(1, 16) as $id) {
            $this->insertPlacement($id, 'Karyawan '.$id, 'PT Beta', 'Satpam', 100, '2027-02-25');
        }

        $this->actingAs($this->operasional())
            ->get(route('operasional.monitoring', ['id_klien' => 2]))
            ->assertOk()
            ->assertSee('id_klien=2&amp;page=2', false);
    }

    public function test_invalid_filter_is_rejected(): void
    {
        $this->createMonitoringData();

        $this->actingAs($this->operasional())
            ->from(route('operasional.monitoring'))
            ->get(route('operasional.monitoring', ['id_klien' => 'semua']))
            ->assertRedirect(route('operasional.monitoring'))
            ->assertSessionHasErrors(['id_klien' => 'Filter klien tidak valid.']);
    }

    public function test_page_still_works_and_warns_when_the_view_is_not_migrated(): void
    {
        $this->createMonitoringData();
        Schema::table('v_monitoring_sebaran_operasional', fn (Blueprint $table) => $table->dropColumn(['id_klien', 'id_jenis_layanan']));

        $this->actingAs($this->operasional())
            ->get(route('operasional.monitoring', ['id_klien' => 2]))
            ->assertOk()
            ->assertSeeText('Filter klien dan jenis layanan belum tersedia karena database belum dimigrasi.')
            ->assertSee('<select id="filter_klien" name="id_klien" class="form-input" disabled>', false)
            ->assertSeeText('Andi')
            ->assertSeeText('Citra');
    }

    /**
     * Andi (Alpha, Satpam, sisa 40), Budi (Alpha, Driver, sisa 10), Citra (Beta, Satpam, lewat 3 hari),
     * Dedi (Beta, Satpam, tanpa batas akhir). Klien: Alpha = 1, Beta = 2.
     * Jenis layanan: Satpam = 1, Driver = 2, Cleaning Service = 3 (tanpa karyawan).
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
     * Pengganti view v_monitoring_sebaran_operasional (MariaDB) dengan kolom yang sama untuk SQLite,
     * beserta tbl_klien dan tbl_jenis_layanan untuk pilihan filter.
     */
    private function createMonitoringView(): void
    {
        Schema::create('tbl_klien', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_perusahaan');
            $table->string('status')->default('Aktif');
        });
        Schema::create('tbl_jenis_layanan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_layanan');
        });
        DB::table('tbl_klien')->insert([['id' => 1, 'nama_perusahaan' => 'PT Alpha'], ['id' => 2, 'nama_perusahaan' => 'PT Beta']]);
        DB::table('tbl_jenis_layanan')->insert([
            ['id' => 1, 'nama_layanan' => 'Satpam'], ['id' => 2, 'nama_layanan' => 'Driver'], ['id' => 3, 'nama_layanan' => 'Cleaning Service'],
        ]);

        Schema::create('v_monitoring_sebaran_operasional', function (Blueprint $table): void {
            $table->integer('id_penempatan');
            $table->string('nip');
            $table->string('nama');
            $table->string('nama_layanan')->nullable();
            $table->string('nama_perusahaan');
            $table->string('lokasi_kerja')->nullable();
            $table->time('jam_datang')->nullable();
            $table->time('jam_pulang')->nullable();
            $table->date('tgl_mulai');
            $table->date('tgl_selesai')->nullable();
            $table->integer('sisa_hari_kontrak');
            $table->integer('id_klien');
            $table->integer('id_jenis_layanan')->nullable();
        });
    }

    private function insertPlacement(int $id, string $name, string $client, ?string $service, int $remainingDays, ?string $endDate, string $startDate = '2026-01-01'): void
    {
        DB::table('v_monitoring_sebaran_operasional')->insert([
            'id_penempatan' => $id,
            'nip' => 'SJI00'.$id,
            'nama' => $name,
            'nama_layanan' => $service,
            'nama_perusahaan' => $client,
            'lokasi_kerja' => 'Lokasi '.$id,
            'tgl_mulai' => $startDate,
            'tgl_selesai' => $endDate,
            'sisa_hari_kontrak' => $remainingDays,
            'id_klien' => DB::table('tbl_klien')->where('nama_perusahaan', $client)->value('id'),
            'id_jenis_layanan' => $service === null ? null : DB::table('tbl_jenis_layanan')->where('nama_layanan', $service)->value('id'),
        ]);
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}

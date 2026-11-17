<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\Concerns\CreatesTemplateJadwalTables;
use Tests\TestCase;

class TemplateJadwalControllerTest extends TestCase
{
    use CreatesSjiAuthTables;
    use CreatesTemplateJadwalTables;

    private const string FORM_URL = '/operasional/jadwal-kerja';

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, int>}>
     */
    public static function templateRoutes(): array
    {
        return [
            'store' => ['post', 'operasional.jadwal-kerja.template-jadwal.store', []],
            'store from schedule' => ['post', 'operasional.jadwal-kerja.template-jadwal.store-from-schedule', []],
            'update' => ['put', 'operasional.jadwal-kerja.template-jadwal.update', ['templateJadwal' => 1]],
            'deactivate' => ['patch', 'operasional.jadwal-kerja.template-jadwal.deactivate', ['templateJadwal' => 1]],
            'apply' => ['post', 'operasional.jadwal-kerja.template-jadwal.apply', ['templateJadwal' => 1]],
        ];
    }

    /**
     * @param  array<string, int>  $parameters
     */
    #[DataProvider('templateRoutes')]
    public function test_guest_is_redirected_to_login(string $method, string $routeName, array $parameters): void
    {
        $this->{$method}(route($routeName, $parameters))
            ->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, int>}>
     */
    public static function forbiddenRolesAndRoutes(): array
    {
        $cases = [];

        foreach ([Role::Hrd, Role::Keuangan, Role::Ceo] as $role) {
            foreach (self::templateRoutes() as $routeKey => [$method, $routeName, $parameters]) {
                $cases["{$role} {$routeKey}"] = [$role, $method, $routeName, $parameters];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, int>  $parameters
     */
    #[DataProvider('forbiddenRolesAndRoutes')]
    public function test_non_operasional_roles_are_forbidden(string $role, string $method, string $routeName, array $parameters): void
    {
        $this->createTables();
        $templateId = $this->personalTemplateFor($this->createEmployee('SJI001', 'Andi'));
        $user = User::factory()->withRole($role)->create();

        $this->actingAs($user)
            ->{$method}(route($routeName, $parameters), $this->validPersonalPayload(1))
            ->assertForbidden();

        $this->assertDatabaseHas('tbl_template_jadwal', ['id' => $templateId, 'status' => 'Aktif', 'nama_template' => 'Pola Andi']);
        $this->assertDatabaseCount('tbl_template_jadwal', 1);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_operasional_creates_a_personal_template(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->post(route('operasional.jadwal-kerja.template-jadwal.store'), $this->validPersonalPayload($employeeId))
            ->assertRedirect(route('operasional.jadwal-kerja.template-jadwal.show', 1))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Pola jadwal "Pola Andi" berhasil dibuat.');

        $this->assertDatabaseHas('tbl_template_jadwal', ['nama_template' => 'Pola Andi', 'jenis' => 'Pribadi', 'id_karyawan' => $employeeId]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['hari_ke' => 1, 'jam_pulang' => '04:00:00', 'durasi_menit' => 480]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['hari_ke' => 2, 'status' => 'Libur']);
    }

    public function test_operasional_creates_a_shared_template(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        $serviceId = $this->createService('Satpam');

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->post(route('operasional.jadwal-kerja.template-jadwal.store'), [
                'nama_template' => 'Pola Security Klien A',
                'jenis' => 'Bersama',
                'id_klien' => $clientId,
                'id_jenis_layanan' => $serviceId,
                'pola' => [10 => ['status' => 'Kerja', 'jam_masuk' => '20:00', 'jam_pulang' => '08:00']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_template_jadwal', ['jenis' => 'Bersama', 'id_klien' => $clientId, 'id_jenis_layanan' => $serviceId, 'id_karyawan' => null]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['hari_ke' => 10, 'durasi_menit' => 720]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidStoreInputs(): array
    {
        $work = ['status' => 'Kerja', 'jam_masuk' => '08:00', 'jam_pulang' => '16:00'];

        return [
            'missing name' => [['nama_template' => ''], 'nama_template', 'Nama pola wajib diisi.'],
            'unknown type' => [['jenis' => 'Lain'], 'jenis', 'Jenis pola harus Pribadi atau Bersama.'],
            'personal without owner' => [['id_karyawan' => null], 'id_karyawan', 'Pola Pribadi wajib memilih karyawannya.'],
            'personal with client' => [['id_klien' => 1], 'id_klien', 'Pola Pribadi tidak terikat pada klien.'],
            'shared without client' => [['jenis' => 'Bersama', 'id_karyawan' => null], 'id_klien', 'Pola Bersama wajib memilih klien.'],
            'empty pattern' => [['pola' => []], 'pola', 'Isi minimal satu tanggal pada pola.'],
            'unknown day status' => [['pola' => [1 => ['status' => 'Cuti']]], 'pola.1.status', 'Status hari pada pola harus Kerja atau Libur.'],
            'work day without start' => [['pola' => [1 => ['status' => 'Kerja', 'jam_pulang' => '16:00']]], 'pola.1.jam_masuk', 'Jam masuk wajib diisi untuk hari Kerja.'],
            'bad time format' => [['pola' => [1 => [...$work, 'jam_pulang' => '25:00']]], 'pola.1.jam_pulang', 'Format jam pulang harus JJ:MM.'],
            'equal hours' => [['pola' => [1 => [...$work, 'jam_pulang' => '08:00']]], 'pola.1.jam_pulang', 'Jam pulang tidak boleh sama dengan jam masuk.'],
            'day 32' => [['pola' => [32 => $work]], 'pola', 'Hari pada pola harus antara 1 dan 31.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidStoreInputs')]
    public function test_store_rejects_invalid_input_with_a_clear_message(array $override, string $field, string $message): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createClient('KL001');

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->post(route('operasional.jadwal-kerja.template-jadwal.store'), [...$this->validPersonalPayload($employeeId), ...$override])
            ->assertRedirect(self::FORM_URL)
            ->assertSessionHasErrors([$field => $message]);

        $this->assertDatabaseCount('tbl_template_jadwal', 0);
    }

    public function test_operasional_updates_a_template_pattern(): void
    {
        $this->createTables();
        $templateId = $this->personalTemplateFor($this->createEmployee('SJI001', 'Andi'));

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->put(route('operasional.jadwal-kerja.template-jadwal.update', $templateId), [
                'nama_template' => 'Pola Andi Revisi',
                'pola' => [3 => ['status' => 'Kerja', 'jam_masuk' => '07:00', 'jam_pulang' => '15:00']],
            ])
            ->assertRedirect(route('operasional.jadwal-kerja.template-jadwal.show', $templateId))
            ->assertSessionHas('success', 'Pola berhasil diperbarui. Jadwal yang sudah dibuat dari pola ini tidak berubah.');

        $this->assertDatabaseHas('tbl_template_jadwal', ['id' => $templateId, 'nama_template' => 'Pola Andi Revisi']);
        $this->assertSame([3], DB::table('tbl_template_jadwal_detail')->pluck('hari_ke')->all());
    }

    public function test_update_cannot_change_the_template_owner(): void
    {
        $this->createTables();
        $ownerId = $this->createEmployee('SJI001', 'Andi');
        $otherId = $this->createEmployee('SJI002', 'Budi');
        $templateId = $this->personalTemplateFor($ownerId);

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->put(route('operasional.jadwal-kerja.template-jadwal.update', $templateId), [
                'nama_template' => 'Pola Andi',
                'id_karyawan' => $otherId,
                'pola' => [1 => ['status' => 'Libur']],
            ])
            ->assertSessionHasErrors(['jenis' => 'Jenis pola dan karyawan/klien terkait tidak dapat diubah. Buat pola baru bila diperlukan.']);

        $this->assertDatabaseHas('tbl_template_jadwal', ['id' => $templateId, 'id_karyawan' => $ownerId]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function templateWriteRoutes(): array
    {
        return [
            'update' => ['put', 'operasional.jadwal-kerja.template-jadwal.update'],
            'deactivate' => ['patch', 'operasional.jadwal-kerja.template-jadwal.deactivate'],
            'apply' => ['post', 'operasional.jadwal-kerja.template-jadwal.apply'],
        ];
    }

    #[DataProvider('templateWriteRoutes')]
    public function test_unknown_template_returns_not_found(string $method, string $routeName): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->{$method}(route($routeName, 999), ['nama_template' => 'X', 'pola' => [1 => ['status' => 'Libur']], 'bulan' => '2026-12', 'mode_konflik' => 'Lewati'])
            ->assertNotFound();
    }

    public function test_operasional_deactivates_a_template(): void
    {
        $this->createTables();
        $templateId = $this->personalTemplateFor($this->createEmployee('SJI001', 'Andi'));

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->patch(route('operasional.jadwal-kerja.template-jadwal.deactivate', $templateId))
            ->assertRedirect(self::FORM_URL)
            ->assertSessionHas('success', 'Pola dinonaktifkan. Jadwal yang sudah dibuat dari pola ini tetap tersimpan.');

        $this->assertDatabaseHas('tbl_template_jadwal', ['id' => $templateId, 'status' => 'Nonaktif']);
    }

    public function test_operasional_applies_a_shared_template_to_several_employees(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        $firstId = $this->createEmployee('SJI001', 'Andi');
        $secondId = $this->createEmployee('SJI002', 'Budi');
        $this->createPlacement(101, $firstId, $clientId, '2026-01-01');
        $this->createPlacement(102, $secondId, $clientId, '2026-01-01');
        $templateId = $this->sharedTemplateFor($clientId, [
            1 => ['status' => 'Kerja', 'jam_masuk' => '08:00', 'jam_pulang' => '16:00'],
            30 => ['status' => 'Libur'],
        ]);

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->post(route('operasional.jadwal-kerja.template-jadwal.apply', $templateId), [
                'bulan' => '2026-02',
                'id_karyawan' => [$firstId, $secondId],
                'mode_konflik' => 'Lewati',
            ])
            ->assertRedirect(route('operasional.jadwal-kerja.template-jadwal.show', $templateId))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'success',
                'Pola dipakai. Andi: 1 tanggal baru, 0 diganti, 0 jadwal lama dipertahankan, 0 di luar penempatan; '
                .'Budi: 1 tanggal baru, 0 diganti, 0 jadwal lama dipertahankan, 0 di luar penempatan. Hari ke-30 dilewati karena tidak ada pada bulan tujuan.',
            );

        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id_penempatan' => 101, 'tanggal_kerja' => '2026-02-01']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id_penempatan' => 102, 'tanggal_kerja' => '2026-02-01']);
        $this->assertDatabaseCount('tbl_template_jadwal_penerapan', 2);
    }

    public function test_apply_requires_a_conflict_mode(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $templateId = $this->personalTemplateFor($employeeId);

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->post(route('operasional.jadwal-kerja.template-jadwal.apply', $templateId), ['bulan' => '2026-12'])
            ->assertSessionHasErrors(['mode_konflik' => 'Pilih pertahankan atau ganti untuk jadwal yang sudah ada.']);

        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_apply_reports_a_personal_template_sent_to_another_employee(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        $ownerId = $this->createEmployee('SJI001', 'Andi');
        $otherId = $this->createEmployee('SJI002', 'Budi');
        $this->createPlacement(101, $ownerId, $clientId, '2026-01-01');
        $this->createPlacement(102, $otherId, $clientId, '2026-01-01');
        $templateId = $this->personalTemplateFor($ownerId);

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->post(route('operasional.jadwal-kerja.template-jadwal.apply', $templateId), [
                'bulan' => '2026-12',
                'id_karyawan' => [$otherId],
                'mode_konflik' => 'Timpa',
            ])
            ->assertSessionHasErrors(['id_karyawan' => 'Budi: Pola Pribadi hanya dapat dipakai untuk karyawan yang bersangkutan.']);

        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_operasional_saves_a_monthly_schedule_as_a_new_template(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $this->createSchedule(101, '2026-11-05', 'Kerja', '20:00', '08:00');
        $this->createSchedule(101, '2026-11-06', 'Libur');

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->post(route('operasional.jadwal-kerja.template-jadwal.store-from-schedule'), [
                'id_karyawan' => $employeeId,
                'bulan' => '2026-11',
                'tindakan' => 'buat_baru',
                'nama_template' => 'Pola November Andi',
                'jenis' => 'Pribadi',
            ])
            ->assertRedirect(route('operasional.jadwal-kerja.template-jadwal.show', 1))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Pola jadwal "Pola November Andi" dibuat dari jadwal bulan 2026-11.');

        $this->assertDatabaseHas('tbl_template_jadwal', ['nama_template' => 'Pola November Andi', 'jenis' => 'Pribadi', 'id_karyawan' => $employeeId]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['hari_ke' => 5, 'jam_masuk' => '20:00:00', 'durasi_menit' => 720]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['hari_ke' => 6, 'status' => 'Libur']);
    }

    public function test_operasional_replaces_an_existing_template_pattern_from_a_monthly_schedule(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $this->createSchedule(101, '2026-11-09', 'Libur');
        $templateId = $this->personalTemplateFor($employeeId);

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->post(route('operasional.jadwal-kerja.template-jadwal.store-from-schedule'), [
                'id_karyawan' => $employeeId,
                'bulan' => '2026-11',
                'tindakan' => 'perbarui',
                'id_template_jadwal' => $templateId,
            ])
            ->assertRedirect(route('operasional.jadwal-kerja.template-jadwal.show', $templateId))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Pola jadwal diperbarui dari jadwal bulan 2026-11.');

        $this->assertDatabaseHas('tbl_template_jadwal', ['id' => $templateId, 'nama_template' => 'Pola Andi']);
        $this->assertSame([9], DB::table('tbl_template_jadwal_detail')->pluck('hari_ke')->all());
    }

    public function test_save_from_schedule_requires_the_template_to_update(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->post(route('operasional.jadwal-kerja.template-jadwal.store-from-schedule'), [
                'id_karyawan' => $employeeId,
                'bulan' => '2026-11',
                'tindakan' => 'perbarui',
            ])
            ->assertSessionHasErrors(['id_template_jadwal' => 'Pilih pola yang akan diperbarui.']);

        $this->assertDatabaseCount('tbl_template_jadwal', 0);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, int>}>
     */
    public static function pageRoutes(): array
    {
        return [
            'index' => ['operasional.jadwal-kerja.template-jadwal.index', []],
            'create' => ['operasional.jadwal-kerja.template-jadwal.create', []],
            'show' => ['operasional.jadwal-kerja.template-jadwal.show', ['templateJadwal' => 1]],
            'edit' => ['operasional.jadwal-kerja.template-jadwal.edit', ['templateJadwal' => 1]],
            'apply preview' => ['operasional.jadwal-kerja.template-jadwal.apply.preview', ['templateJadwal' => 1]],
        ];
    }

    /**
     * @param  array<string, int>  $parameters
     */
    #[DataProvider('pageRoutes')]
    public function test_guest_is_redirected_to_login_from_pages(string $routeName, array $parameters): void
    {
        $this->get(route($routeName, $parameters))
            ->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, int>}>
     */
    public static function forbiddenRolesAndPages(): array
    {
        $cases = [];

        foreach ([Role::Hrd, Role::Keuangan, Role::Ceo] as $role) {
            foreach (self::pageRoutes() as $routeKey => [$routeName, $parameters]) {
                $cases["{$role} {$routeKey}"] = [$role, $routeName, $parameters];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, int>  $parameters
     */
    #[DataProvider('forbiddenRolesAndPages')]
    public function test_non_operasional_roles_cannot_open_pages(string $role, string $routeName, array $parameters): void
    {
        $this->createTables();
        $this->personalTemplateFor($this->createEmployee('SJI001', 'Andi'));

        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route($routeName, $parameters))
            ->assertForbidden();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, int>, 2: string}>
     */
    public static function pageHeadings(): array
    {
        return [
            'index' => ['operasional.jadwal-kerja.template-jadwal.index', [], 'Pola Jadwal Bulanan'],
            'create' => ['operasional.jadwal-kerja.template-jadwal.create', [], 'Buat Pola Jadwal Bulanan'],
            'show' => ['operasional.jadwal-kerja.template-jadwal.show', ['templateJadwal' => 1], 'Riwayat Pemakaian'],
            'edit' => ['operasional.jadwal-kerja.template-jadwal.edit', ['templateJadwal' => 1], 'Edit Pola: Pola Andi'],
            'apply preview' => ['operasional.jadwal-kerja.template-jadwal.apply.preview', ['templateJadwal' => 1], 'Gunakan Pola: Pola Andi'],
        ];
    }

    /**
     * @param  array<string, int>  $parameters
     */
    #[DataProvider('pageHeadings')]
    public function test_operasional_can_open_every_page(string $routeName, array $parameters, string $heading): void
    {
        $this->createTables();
        $this->personalTemplateFor($this->createEmployee('SJI001', 'Andi'));

        $this->actingAs($this->operasional())
            ->get(route($routeName, $parameters))
            ->assertOk()
            ->assertSeeText($heading);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function templatePages(): array
    {
        return [
            'show' => ['operasional.jadwal-kerja.template-jadwal.show'],
            'edit' => ['operasional.jadwal-kerja.template-jadwal.edit'],
            'apply preview' => ['operasional.jadwal-kerja.template-jadwal.apply.preview'],
        ];
    }

    #[DataProvider('templatePages')]
    public function test_unknown_template_page_returns_not_found(string $routeName): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->get(route($routeName, 999))
            ->assertNotFound();
    }

    public function test_index_filters_templates_by_type(): void
    {
        $this->createTables();
        $this->personalTemplateFor($this->createEmployee('SJI001', 'Andi'));
        $this->sharedTemplateFor($this->createClient('KL001'), [1 => ['status' => 'Libur']]);

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja.template-jadwal.index', ['jenis' => 'Bersama']));

        $response->assertOk()
            ->assertSee('Pola Bersama')
            ->assertDontSee('Pola Andi')
            ->assertSeeText('Pola Pribadi (1)')
            ->assertSeeText('Pola Bersama (1)')
            ->assertSeeText('Semua (2)');
    }

    public function test_index_rejects_an_unknown_type_filter(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->from(self::FORM_URL)
            ->get(route('operasional.jadwal-kerja.template-jadwal.index', ['jenis' => 'Lain']))
            ->assertRedirect(self::FORM_URL)
            ->assertSessionHasErrors(['jenis' => 'Filter jenis pola tidak valid.']);
    }

    public function test_create_form_lists_owners_clients_and_thirty_one_days(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createEmployee('SJI099', 'Tanpa Penempatan');
        $clientId = $this->createClient('KL001');
        $this->createService('Satpam');
        $this->createPlacement(101, $employeeId, $clientId, '2026-01-01');

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja.template-jadwal.create', ['jenis' => 'Bersama']));

        $response->assertOk()
            ->assertSee('action="'.route('operasional.jadwal-kerja.template-jadwal.store').'"', false)
            ->assertSee('SJI001')
            ->assertDontSee('SJI099')
            ->assertSee('Klien KL001')
            ->assertSee('Satpam')
            ->assertSee('value="Bersama" class="js-jenis" checked', false)
            ->assertSee('data-hari="31"', false)
            ->assertDontSee('data-hari="32"', false);
    }

    public function test_edit_form_is_prefilled_and_keeps_owner_read_only(): void
    {
        $this->createTables();
        $templateId = $this->personalTemplateFor($this->createEmployee('SJI001', 'Andi'));

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja.template-jadwal.edit', $templateId));

        $response->assertOk()
            ->assertSee('action="'.route('operasional.jadwal-kerja.template-jadwal.update', $templateId).'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('value="Pola Andi"', false)
            ->assertSee('{"1":{"status":"Kerja","jam_masuk":"08:00","jam_pulang":"16:00"}}', false)
            ->assertSeeText('Jenis dan karyawan/klien terkait tidak dapat diubah.')
            ->assertDontSee('name="id_karyawan"', false);
    }

    public function test_show_displays_pattern_and_application_history(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $templateId = $this->sharedTemplateFor(1, [
            4 => ['status' => 'Kerja', 'jam_masuk' => '20:00', 'jam_pulang' => '04:00'],
            5 => ['status' => 'Libur'],
        ]);
        $operasional = $this->operasional();
        DB::table('tbl_template_jadwal_penerapan')->insert([
            'id_template_jadwal' => $templateId, 'id_karyawan' => $employeeId, 'id_penempatan' => 101, 'bulan_tujuan' => '2026-12-01',
            'mode_konflik' => 'Timpa', 'jumlah_dibuat' => 2, 'jumlah_ditimpa' => 1, 'jumlah_dilewati' => 0, 'diterapkan_oleh' => $operasional->id,
        ]);

        $response = $this->actingAs($operasional)->get(route('operasional.jadwal-kerja.template-jadwal.show', $templateId));

        $response->assertOk()
            ->assertSeeText('Klien KL001')
            ->assertSeeText('Semua jenis layanan')
            ->assertSeeText('1 hari kerja, 1 libur, 29 belum diatur')
            ->assertSeeText('20:00–04:00 (+1 hari)')
            ->assertSeeText('Desember 2026')
            ->assertSeeText('2 tanggal baru, 1 diganti, 0 dilewati')
            ->assertSeeText($operasional->nama);
    }

    public function test_personal_apply_preview_shows_conflicts_and_missing_days(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $this->createSchedule(101, '2026-02-01', 'Libur');
        $templateId = $this->personalTemplateFor($employeeId);
        DB::table('tbl_template_jadwal_detail')->insert(['id_template_jadwal' => $templateId, 'hari_ke' => 30, 'status' => 'Libur']);

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja.template-jadwal.apply.preview', [$templateId, 'bulan' => '2026-02']));

        $response->assertOk()
            ->assertSeeText('2. Pratinjau Februari 2026')
            ->assertSeeText('Hari ke-30 pada pola dilewati karena tidak ada pada bulan tujuan.')
            ->assertSeeText('01/02/2026')
            ->assertSeeText('Sudah berjadwal')
            ->assertSeeText('3. Jadwal yang sudah ada (1 tanggal sudah berjadwal)')
            ->assertSee('action="'.route('operasional.jadwal-kerja.template-jadwal.apply', $templateId).'"', false)
            ->assertSee('name="mode_konflik" value="Timpa"', false)
            ->assertDontSee('name="id_karyawan[]"', false);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 1);
    }

    public function test_shared_apply_preview_lists_candidates_and_blocks_ineligible_employees(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        $otherClientId = $this->createClient('KL002');
        $eligibleId = $this->createEmployee('SJI001', 'Andi');
        $outsiderId = $this->createEmployee('SJI002', 'Budi');
        $this->createPlacement(101, $eligibleId, $clientId, '2026-01-01');
        $this->createPlacement(102, $outsiderId, $otherClientId, '2026-01-01');
        $templateId = $this->sharedTemplateFor($clientId, [1 => ['status' => 'Libur']]);

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja.template-jadwal.apply.preview', [$templateId, 'bulan' => '2026-12', 'id_karyawan' => [$eligibleId, $outsiderId]]));

        $response->assertOk()
            ->assertSee('name="id_karyawan[]" value="'.$eligibleId.'" checked', false)
            ->assertDontSee('type="checkbox" name="id_karyawan[]" value="'.$outsiderId.'"', false)
            ->assertSeeText('Tidak memiliki penempatan Aktif di klien pola ini pada bulan tujuan.')
            ->assertSee('Konfirmasi &amp; Gunakan Pola</button>', false)
            ->assertSee('class="btn-primary" disabled', false);
    }

    public function test_shared_apply_preview_waits_for_employee_selection(): void
    {
        $this->createTables();
        $templateId = $this->sharedTemplateFor($this->createClient('KL001'), [1 => ['status' => 'Libur']]);

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja.template-jadwal.apply.preview', [$templateId, 'bulan' => '2026-12']));

        $response->assertOk()
            ->assertSeeText('Pilih minimal satu karyawan penerima.')
            ->assertDontSeeText('2. Pratinjau');
    }

    public function test_apply_preview_of_a_deactivated_template_redirects_to_its_detail(): void
    {
        $this->createTables();
        $templateId = $this->personalTemplateFor($this->createEmployee('SJI001', 'Andi'));
        DB::table('tbl_template_jadwal')->where('id', $templateId)->update(['status' => 'Nonaktif']);

        $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja.template-jadwal.apply.preview', $templateId))
            ->assertRedirect(route('operasional.jadwal-kerja.template-jadwal.show', $templateId))
            ->assertSessionHas('error', 'Pola yang dinonaktifkan tidak dapat dipakai.');
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPersonalPayload(int $employeeId): array
    {
        return [
            'nama_template' => 'Pola Andi',
            'jenis' => 'Pribadi',
            'id_karyawan' => $employeeId,
            'pola' => [
                1 => ['status' => 'Kerja', 'jam_masuk' => '20:00', 'jam_pulang' => '04:00'],
                2 => ['status' => 'Libur'],
            ],
        ];
    }

    private function personalTemplateFor(int $employeeId): int
    {
        DB::table('tbl_template_jadwal')->insert(['id' => 1, 'nama_template' => 'Pola Andi', 'jenis' => 'Pribadi', 'id_karyawan' => $employeeId, 'status' => 'Aktif']);
        DB::table('tbl_template_jadwal_detail')->insert(['id_template_jadwal' => 1, 'hari_ke' => 1, 'status' => 'Kerja', 'jam_masuk' => '08:00:00', 'jam_pulang' => '16:00:00', 'durasi_menit' => 480]);

        return 1;
    }

    /**
     * @param  array<int, array<string, string>>  $pattern
     */
    private function sharedTemplateFor(int $clientId, array $pattern): int
    {
        $templateId = (int) DB::table('tbl_template_jadwal')->insertGetId(['nama_template' => 'Pola Bersama', 'jenis' => 'Bersama', 'id_klien' => $clientId, 'status' => 'Aktif']);

        foreach ($pattern as $day => $value) {
            $isWorkDay = $value['status'] === 'Kerja';
            DB::table('tbl_template_jadwal_detail')->insert([
                'id_template_jadwal' => $templateId,
                'hari_ke' => $day,
                'status' => $value['status'],
                'jam_masuk' => $isWorkDay ? $value['jam_masuk'].':00' : null,
                'jam_pulang' => $isWorkDay ? $value['jam_pulang'].':00' : null,
                'durasi_menit' => $isWorkDay ? 480 : null,
            ]);
        }

        return $templateId;
    }
}

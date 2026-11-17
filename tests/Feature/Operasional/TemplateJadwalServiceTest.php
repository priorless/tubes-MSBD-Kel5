<?php

namespace Tests\Feature\Operasional;

use App\Services\TemplateJadwalService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTemplateJadwalTables;
use Tests\TestCase;

class TemplateJadwalServiceTest extends TestCase
{
    use CreatesTemplateJadwalTables;

    private const int ID_USER = 77;

    public function test_personal_template_stores_work_holiday_and_leaves_unset_days_out(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');

        $templateId = $this->service()->buatTemplate(
            ['nama_template' => 'Pola Jadwal Andi', 'jenis' => 'Pribadi', 'id_karyawan' => $employeeId, 'keterangan' => ' Shift malam '],
            [1 => $this->work('20:00', '04:00'), 2 => $this->work('20:00', '08:00'), 3 => ['status' => 'Libur']],
            self::ID_USER,
        );

        $this->assertDatabaseHas('tbl_template_jadwal', [
            'id' => $templateId,
            'jenis' => 'Pribadi',
            'id_karyawan' => $employeeId,
            'id_klien' => null,
            'keterangan' => 'Shift malam',
            'status' => 'Aktif',
            'dibuat_oleh' => self::ID_USER,
        ]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['hari_ke' => 1, 'jam_pulang' => '04:00:00', 'durasi_menit' => 480]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['hari_ke' => 2, 'jam_pulang' => '08:00:00', 'durasi_menit' => 720]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['hari_ke' => 3, 'status' => 'Libur', 'jam_masuk' => null, 'durasi_menit' => null]);
        $this->assertDatabaseCount('tbl_template_jadwal_detail', 3);
    }

    public function test_shared_template_stores_client_and_service_type(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        $serviceId = $this->createService('Satpam');

        $templateId = $this->service()->buatTemplate(
            ['nama_template' => 'Pola Security Klien A', 'jenis' => 'Bersama', 'id_klien' => $clientId, 'id_jenis_layanan' => $serviceId],
            [5 => $this->work('08:00', '16:00')],
            self::ID_USER,
        );

        $this->assertDatabaseHas('tbl_template_jadwal', [
            'id' => $templateId,
            'jenis' => 'Bersama',
            'id_karyawan' => null,
            'id_klien' => $clientId,
            'id_jenis_layanan' => $serviceId,
        ]);
    }

    /**
     * @return array<string, array{0: Closure(int, int): array<string, mixed>, 1: array<int, mixed>, 2: string, 3: string}>
     */
    public static function invalidTemplates(): array
    {
        $work = ['status' => 'Kerja', 'jam_masuk' => '08:00', 'jam_pulang' => '16:00'];

        return [
            'personal without employee' => [fn (int $employee, int $client): array => ['nama_template' => 'A', 'jenis' => 'Pribadi'], [1 => $work], 'id_karyawan', 'Pola Pribadi wajib memilih karyawannya.'],
            'personal tied to a client' => [fn (int $employee, int $client): array => ['nama_template' => 'A', 'jenis' => 'Pribadi', 'id_karyawan' => $employee, 'id_klien' => $client], [1 => $work], 'id_klien', 'Pola Pribadi tidak terikat pada klien atau jenis layanan.'],
            'shared without client' => [fn (int $employee, int $client): array => ['nama_template' => 'A', 'jenis' => 'Bersama'], [1 => $work], 'id_klien', 'Pola Bersama wajib memilih klien.'],
            'unknown type' => [fn (int $employee, int $client): array => ['nama_template' => 'A', 'jenis' => 'Lain'], [1 => $work], 'jenis', 'Jenis pola harus Pribadi atau Bersama.'],
            'empty name' => [fn (int $employee, int $client): array => ['nama_template' => '  ', 'jenis' => 'Pribadi', 'id_karyawan' => $employee], [1 => $work], 'nama_template', 'Nama pola wajib diisi, maksimal 100 karakter.'],
            'empty pattern' => [fn (int $employee, int $client): array => ['nama_template' => 'A', 'jenis' => 'Pribadi', 'id_karyawan' => $employee], [], 'pola', 'Isi minimal satu tanggal pada pola.'],
            'day 32' => [fn (int $employee, int $client): array => ['nama_template' => 'A', 'jenis' => 'Pribadi', 'id_karyawan' => $employee], [32 => $work], 'pola', 'Hari pada pola harus antara 1 dan 31.'],
            'work day without hours' => [fn (int $employee, int $client): array => ['nama_template' => 'A', 'jenis' => 'Pribadi', 'id_karyawan' => $employee], [4 => ['status' => 'Kerja']], 'pola', 'Isi jam masuk dan jam pulang (JJ:MM) untuk hari ke-4.'],
            'equal hours' => [fn (int $employee, int $client): array => ['nama_template' => 'A', 'jenis' => 'Pribadi', 'id_karyawan' => $employee], [4 => ['status' => 'Kerja', 'jam_masuk' => '08:00', 'jam_pulang' => '08:00']], 'pola', 'Jam pulang hari ke-4 tidak boleh sama dengan jam masuk.'],
        ];
    }

    /**
     * @param  Closure(int, int): array<string, mixed>  $data
     * @param  array<int, mixed>  $pattern
     */
    #[DataProvider('invalidTemplates')]
    public function test_invalid_template_is_rejected_without_saving(Closure $data, array $pattern, string $field, string $message): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $clientId = $this->createClient('KL001');

        $error = $this->captureValidationError(fn () => $this->service()->buatTemplate($data($employeeId, $clientId), $pattern, self::ID_USER));

        $this->assertSame([$field => [$message]], $error);
        $this->assertDatabaseCount('tbl_template_jadwal', 0);
        $this->assertDatabaseCount('tbl_template_jadwal_detail', 0);
    }

    public function test_duplicate_template_name_is_rejected(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->personalTemplate($employeeId, [1 => $this->work('08:00', '16:00')], 'Pola A');

        $error = $this->captureValidationError(fn () => $this->personalTemplate($employeeId, [1 => $this->work('08:00', '16:00')], 'Pola A'));

        $this->assertSame(['nama_template' => ['Nama pola sudah digunakan.']], $error);
        $this->assertDatabaseCount('tbl_template_jadwal', 1);
    }

    public function test_editing_a_template_replaces_its_pattern_but_not_applied_schedules(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $templateId = $this->personalTemplate($employeeId, [1 => $this->work('08:00', '16:00'), 2 => ['status' => 'Libur']]);
        $this->service()->terapkan($templateId, '2026-12', [], 'Lewati', self::ID_USER);

        $this->service()->ubahTemplate($templateId, ['nama_template' => 'Pola Baru', 'keterangan' => 'Revisi'], [1 => $this->work('20:00', '04:00')], self::ID_USER);

        $this->assertDatabaseHas('tbl_template_jadwal', ['id' => $templateId, 'nama_template' => 'Pola Baru', 'keterangan' => 'Revisi']);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['id_template_jadwal' => $templateId, 'hari_ke' => 1, 'jam_masuk' => '20:00:00']);
        $this->assertDatabaseCount('tbl_template_jadwal_detail', 1);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-12-01', 'jam_masuk' => '08:00:00']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-12-02', 'status' => 'Libur']);
    }

    public function test_editing_cannot_change_template_type_or_owner(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $otherEmployeeId = $this->createEmployee('SJI002', 'Budi');
        $templateId = $this->personalTemplate($employeeId, [1 => $this->work('08:00', '16:00')]);

        $error = $this->captureValidationError(fn () => $this->service()->ubahTemplate(
            $templateId,
            ['nama_template' => 'Pola Andi', 'id_karyawan' => $otherEmployeeId],
            [1 => $this->work('09:00', '17:00')],
            self::ID_USER,
        ));

        $this->assertSame(['jenis' => ['Jenis pola dan karyawan/klien terkait tidak dapat diubah. Buat pola baru bila diperlukan.']], $error);
        $this->assertDatabaseHas('tbl_template_jadwal', ['id' => $templateId, 'id_karyawan' => $employeeId]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['id_template_jadwal' => $templateId, 'jam_masuk' => '08:00:00']);
    }

    public function test_deactivated_template_cannot_be_applied(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $templateId = $this->personalTemplate($employeeId, [1 => $this->work('08:00', '16:00')]);
        $this->service()->nonaktifkanTemplate($templateId, self::ID_USER);

        $error = $this->captureValidationError(fn () => $this->service()->terapkan($templateId, '2026-12', [], 'Lewati', self::ID_USER));

        $this->assertSame(['id_template_jadwal' => ['Pola yang dinonaktifkan tidak dapat dipakai.']], $error);
        $this->assertDatabaseHas('tbl_template_jadwal', ['id' => $templateId, 'status' => 'Nonaktif']);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_personal_template_is_applied_to_its_owner_with_history_and_log(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $templateId = $this->personalTemplate($employeeId, [1 => $this->work('20:00', '08:00'), 3 => ['status' => 'Libur']]);

        $result = $this->service()->terapkan($templateId, '2026-12', [], 'Lewati', self::ID_USER);

        $this->assertSame([
            'hari_tidak_tersedia' => [],
            'karyawan' => [['id_karyawan' => $employeeId, 'nama' => 'Andi', 'dibuat' => 2, 'ditimpa' => 0, 'dilewati_konflik' => 0, 'dilewati_di_luar_penempatan' => 0]],
        ], $result);
        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'id_penempatan' => 101,
            'tanggal_kerja' => '2026-12-01',
            'status' => 'Kerja',
            'jam_masuk' => '20:00:00',
            'jam_pulang' => '08:00:00',
            'durasi_menit' => 720,
        ]);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-12-03', 'status' => 'Libur', 'jam_masuk' => null]);
        $this->assertDatabaseMissing('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-12-02']);
        $this->assertDatabaseHas('tbl_template_jadwal_penerapan', [
            'id_template_jadwal' => $templateId,
            'id_karyawan' => $employeeId,
            'id_penempatan' => 101,
            'bulan_tujuan' => '2026-12-01',
            'mode_konflik' => 'Lewati',
            'jumlah_dibuat' => 2,
            'diterapkan_oleh' => self::ID_USER,
        ]);
        $history = json_decode((string) DB::table('tbl_jadwal_kerja_riwayat')->where('aksi', 'Dibuat')->value('data_sesudah'), true);
        $this->assertSame(['template_jadwal' => $templateId, 'nama_template' => 'Pola Pribadi', 'hari_ke' => 1], $history['sumber']);
    }

    public function test_personal_template_cannot_be_applied_to_another_employee(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        $ownerId = $this->createEmployee('SJI001', 'Andi');
        $otherId = $this->createEmployee('SJI002', 'Budi');
        $this->createPlacement(101, $ownerId, $clientId, '2026-01-01');
        $this->createPlacement(102, $otherId, $clientId, '2026-01-01');
        $templateId = $this->personalTemplate($ownerId, [1 => $this->work('08:00', '16:00')]);

        $error = $this->captureValidationError(fn () => $this->service()->terapkan($templateId, '2026-12', [$ownerId, $otherId], 'Lewati', self::ID_USER));

        $this->assertSame(['id_karyawan' => ['Budi: Pola Pribadi hanya dapat dipakai untuk karyawan yang bersangkutan.']], $error);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
        $this->assertDatabaseCount('tbl_template_jadwal_penerapan', 0);
    }

    public function test_shared_template_is_applied_to_several_eligible_employees(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        $serviceId = $this->createService('Satpam');
        $firstId = $this->createEmployee('SJI001', 'Andi', $serviceId);
        $secondId = $this->createEmployee('SJI002', 'Budi', $serviceId);
        $this->createPlacement(101, $firstId, $clientId, '2026-01-01');
        $this->createPlacement(102, $secondId, $clientId, '2026-01-01');
        $templateId = $this->sharedTemplate($clientId, $serviceId, [10 => $this->work('20:00', '04:00')]);

        $result = $this->service()->terapkan($templateId, '2026-12', [$firstId, $secondId], 'Lewati', self::ID_USER);

        $this->assertSame([1, 1], array_column($result['karyawan'], 'dibuat'));
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id_penempatan' => 101, 'tanggal_kerja' => '2026-12-10', 'durasi_menit' => 480]);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id_penempatan' => 102, 'tanggal_kerja' => '2026-12-10', 'durasi_menit' => 480]);
        $this->assertDatabaseCount('tbl_template_jadwal_penerapan', 2);
    }

    public function test_shared_template_preview_explains_why_employees_are_not_eligible(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        $otherClientId = $this->createClient('KL002');
        $guardId = $this->createService('Satpam');
        $driverId = $this->createService('Driver');
        $eligibleId = $this->createEmployee('SJI001', 'Andi', $guardId);
        $wrongClientId = $this->createEmployee('SJI002', 'Budi', $guardId);
        $wrongServiceId = $this->createEmployee('SJI003', 'Citra', $driverId);
        $endedId = $this->createEmployee('SJI004', 'Dedi', $guardId);
        $this->createPlacement(101, $eligibleId, $clientId, '2026-01-01');
        $this->createPlacement(102, $wrongClientId, $otherClientId, '2026-01-01');
        $this->createPlacement(103, $wrongServiceId, $clientId, '2026-01-01');
        $this->createPlacement(104, $endedId, $clientId, '2026-01-01', '2026-11-30', 'Selesai');
        $templateId = $this->sharedTemplate($clientId, $guardId, [1 => $this->work('08:00', '16:00')]);

        $preview = $this->service()->pratinjauPenerapan($templateId, '2026-12', [$eligibleId, $wrongClientId, $wrongServiceId, $endedId]);

        $this->assertSame([
            'Andi' => null,
            'Budi' => 'Tidak memiliki penempatan Aktif di klien pola ini pada bulan tujuan.',
            'Citra' => 'Jenis layanan karyawan tidak sesuai dengan pola.',
            'Dedi' => 'Tidak memiliki penempatan Aktif di klien pola ini pada bulan tujuan.',
        ], array_column($preview['karyawan'], 'alasan', 'nama'));
    }

    public function test_applying_to_an_ineligible_employee_saves_nothing_for_anyone(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        $eligibleId = $this->createEmployee('SJI001', 'Andi');
        $outsiderId = $this->createEmployee('SJI002', 'Budi');
        $this->createPlacement(101, $eligibleId, $clientId, '2026-01-01');
        $templateId = $this->sharedTemplate($clientId, null, [1 => $this->work('08:00', '16:00')]);

        $error = $this->captureValidationError(fn () => $this->service()->terapkan($templateId, '2026-12', [$eligibleId, $outsiderId], 'Lewati', self::ID_USER));

        $this->assertSame(['id_karyawan' => ['Budi: Tidak memiliki penempatan Aktif di klien pola ini pada bulan tujuan.']], $error);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
        $this->assertDatabaseCount('tbl_template_jadwal_penerapan', 0);
    }

    public function test_preview_marks_conflicting_dates(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $this->createSchedule(101, '2026-12-02', 'Libur');
        $templateId = $this->personalTemplate($employeeId, [1 => $this->work('08:00', '16:00'), 2 => $this->work('08:00', '16:00')]);

        $preview = $this->service()->pratinjauPenerapan($templateId, '2026-12', []);

        $this->assertSame(['2026-12-01' => 'baru', '2026-12-02' => 'konflik'], array_column($preview['karyawan'][0]['tanggal'], 'kondisi', 'tanggal'));
        $this->assertSame(1, $preview['karyawan'][0]['jumlah_konflik']);
    }

    public function test_skip_mode_keeps_existing_schedules(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $existingId = $this->createSchedule(101, '2026-12-02', 'Libur');
        $templateId = $this->personalTemplate($employeeId, [1 => $this->work('08:00', '16:00'), 2 => $this->work('08:00', '16:00')]);

        $result = $this->service()->terapkan($templateId, '2026-12', [], 'Lewati', self::ID_USER);

        $this->assertSame(1, $result['karyawan'][0]['dibuat']);
        $this->assertSame(1, $result['karyawan'][0]['dilewati_konflik']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $existingId, 'status' => 'Libur', 'jam_masuk' => null]);
        $this->assertDatabaseMissing('tbl_jadwal_kerja_riwayat', ['id_jadwal_kerja' => $existingId]);
        $this->assertDatabaseHas('tbl_template_jadwal_penerapan', ['mode_konflik' => 'Lewati', 'jumlah_dibuat' => 1, 'jumlah_ditimpa' => 0, 'jumlah_dilewati' => 1]);
    }

    public function test_overwrite_mode_replaces_existing_schedules_and_keeps_previous_value_in_history(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $existingId = $this->createSchedule(101, '2026-12-02', 'Libur');
        $templateId = $this->personalTemplate($employeeId, [2 => $this->work('20:00', '04:00')]);

        $result = $this->service()->terapkan($templateId, '2026-12', [], 'Timpa', self::ID_USER);

        $this->assertSame(1, $result['karyawan'][0]['ditimpa']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $existingId, 'status' => 'Kerja', 'jam_masuk' => '20:00:00', 'durasi_menit' => 480]);
        $history = DB::table('tbl_jadwal_kerja_riwayat')->where('id_jadwal_kerja', $existingId)->first();
        $this->assertSame('Diubah', $history->aksi);
        $this->assertSame('Libur', json_decode((string) $history->data_sebelum, true)['status']);
        $this->assertDatabaseHas('tbl_template_jadwal_penerapan', ['mode_konflik' => 'Timpa', 'jumlah_ditimpa' => 1]);
    }

    public function test_conflict_mode_must_be_skip_or_overwrite(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $templateId = $this->personalTemplate($employeeId, [1 => $this->work('08:00', '16:00')]);

        $error = $this->captureValidationError(fn () => $this->service()->terapkan($templateId, '2026-12', [], 'Hapus', self::ID_USER));

        $this->assertSame(['mode_konflik' => ['Pilih pertahankan atau ganti untuk jadwal yang sudah ada.']], $error);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_days_missing_from_a_short_month_are_skipped_and_reported(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2024-01-01');
        $templateId = $this->personalTemplate($employeeId, [
            28 => $this->work('08:00', '16:00'),
            29 => $this->work('08:00', '16:00'),
            30 => $this->work('08:00', '16:00'),
            31 => ['status' => 'Libur'],
        ]);

        $nonLeapYear = $this->service()->terapkan($templateId, '2026-02', [], 'Lewati', self::ID_USER);
        $leapYear = $this->service()->terapkan($templateId, '2024-02', [], 'Lewati', self::ID_USER);

        $this->assertSame([29, 30, 31], $nonLeapYear['hari_tidak_tersedia']);
        $this->assertSame([30, 31], $leapYear['hari_tidak_tersedia']);
        $this->assertSame(
            ['2024-02-28', '2024-02-29', '2026-02-28'],
            DB::table('tbl_jadwal_kerja')->orderBy('tanggal_kerja')->pluck('tanggal_kerja')->all(),
        );
        $this->assertDatabaseHas('tbl_template_jadwal_penerapan', ['bulan_tujuan' => '2026-02-01', 'jumlah_dibuat' => 1, 'jumlah_dilewati' => 3]);
    }

    public function test_dates_outside_the_placement_period_are_skipped(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL009'), '2026-01-01', '2026-12-09', 'Rotasi');
        $this->createPlacement(102, $employeeId, $clientId, '2026-12-10', '2026-12-20');
        $templateId = $this->sharedTemplate($clientId, null, [
            5 => $this->work('08:00', '16:00'),
            15 => $this->work('08:00', '16:00'),
            25 => $this->work('08:00', '16:00'),
        ]);

        $result = $this->service()->terapkan($templateId, '2026-12', [$employeeId], 'Lewati', self::ID_USER);

        $this->assertSame(1, $result['karyawan'][0]['dibuat']);
        $this->assertSame(2, $result['karyawan'][0]['dilewati_di_luar_penempatan']);
        $this->assertSame(['2026-12-15'], DB::table('tbl_jadwal_kerja')->pluck('tanggal_kerja')->all());
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id_penempatan' => 102, 'tanggal_kerja' => '2026-12-15']);
    }

    public function test_pattern_can_be_built_from_an_employees_monthly_schedule(): void
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $this->createSchedule(101, '2026-11-03', 'Kerja', '20:00', '08:00');
        $this->createSchedule(101, '2026-11-04', 'Libur');
        $this->createSchedule(101, '2026-12-01', 'Libur');

        $pattern = $this->service()->polaDariJadwal($employeeId, '2026-11');

        $this->assertSame([
            3 => ['status' => 'Kerja', 'jam_masuk' => '20:00', 'jam_pulang' => '08:00'],
            4 => ['status' => 'Libur', 'jam_masuk' => null, 'jam_pulang' => null],
        ], $pattern);
    }

    private function service(): TemplateJadwalService
    {
        return app(TemplateJadwalService::class);
    }

    /**
     * @return array{status: string, jam_masuk: string, jam_pulang: string}
     */
    private function work(string $start, string $end): array
    {
        return ['status' => 'Kerja', 'jam_masuk' => $start, 'jam_pulang' => $end];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pattern
     */
    private function personalTemplate(int $employeeId, array $pattern, string $name = 'Pola Pribadi'): int
    {
        return $this->service()->buatTemplate(['nama_template' => $name, 'jenis' => 'Pribadi', 'id_karyawan' => $employeeId], $pattern, self::ID_USER);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pattern
     */
    private function sharedTemplate(int $clientId, ?int $serviceId, array $pattern): int
    {
        return $this->service()->buatTemplate(
            ['nama_template' => 'Pola Bersama', 'jenis' => 'Bersama', 'id_klien' => $clientId, 'id_jenis_layanan' => $serviceId],
            $pattern,
            self::ID_USER,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function captureValidationError(Closure $action): array
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('ValidationException was not thrown.');
    }
}

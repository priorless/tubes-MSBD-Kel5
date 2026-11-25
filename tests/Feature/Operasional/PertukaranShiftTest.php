<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Backup shift (roker 1 arah) lewat HTTP: karyawan meminta rekan menggantikan shift-nya (menu Backup Shift),
 * rekan menerima/menolak, lalu Operasional memutuskan (menu Jadwal Kerja). Skema SQLite ringkas; aturan trigger
 * MySQL diuji di Tests\Feature\TenagaKerja\PertukaranShiftHakAbsensiTest.
 */
class PertukaranShiftTest extends TestCase
{
    use CreatesSjiAuthTables;

    private const string TANGGAL_SHIFT = '2026-11-20';

    private const int LAYANAN_SATPAM = 1;

    private const int LAYANAN_DRIVER = 2;

    private int $requesterId;

    private int $partnerId;

    private int $requesterScheduleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00:00'));
        $this->createTables();

        $clientId = $this->createClient('KL001');
        $this->requesterId = $this->createEmployee('SJI001', 'Andi Pengaju');
        $this->partnerId = $this->createEmployee('SJI002', 'Budi Rekan');
        $this->createPlacement(101, $this->requesterId, $clientId);
        $this->createPlacement(102, $this->partnerId, $this->createClient('KL002'));
        $this->requesterScheduleId = $this->createSchedule(101, self::TANGGAL_SHIFT, '07:00', '15:00', 'Pagi');
        $this->createDayOff(102, self::TANGGAL_SHIFT);
    }

    public function test_employee_request_waits_for_the_coworker(): void
    {
        $requestId = $this->submitRequest();

        $this->assertDatabaseHas('tbl_pertukaran_shift', [
            'id' => $requestId,
            'id_jadwal_pengaju' => $this->requesterScheduleId,
            'id_karyawan_rekan' => $this->partnerId,
            'tanggal_kerja' => self::TANGGAL_SHIFT,
            'status' => 'Menunggu',
            'status_rekan' => 'Menunggu',
        ]);
    }

    public function test_page_offers_only_same_service_coworkers_without_a_work_shift_including_other_clients(): void
    {
        $busyId = $this->createEmployee('SJI005', 'Citra Sibuk');
        $this->createPlacement(105, $busyId, $this->createClient('KL003'));
        $this->createSchedule(105, self::TANGGAL_SHIFT, '15:00', '23:00', 'Siang');
        $driverId = $this->createEmployee('SJI006', 'Dodi Driver', self::LAYANAN_DRIVER);
        $this->createPlacement(106, $driverId, $this->createClient('KL004'));

        $this->actingAs($this->employeeUser($this->requesterId))
            ->get(route('tenaga-kerja.tukar-shift.index', ['id_jadwal' => $this->requesterScheduleId]))
            ->assertOk()
            ->assertSee('Budi Rekan (SJI002) &mdash; Klien KL002', false)
            ->assertDontSee('Citra Sibuk')
            ->assertDontSee('Dodi Driver');
    }

    public function test_request_with_a_coworker_who_has_a_work_shift_that_day_is_rejected(): void
    {
        $busyId = $this->createEmployee('SJI005', 'Citra Sibuk');
        $this->createPlacement(105, $busyId, $this->createClient('KL003'));
        $this->createSchedule(105, self::TANGGAL_SHIFT, '15:00', '23:00', 'Siang');

        $this->actingAs($this->employeeUser($this->requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload(['id_karyawan_rekan' => $busyId]))
            ->assertSessionHas('gagal', 'Citra Sibuk sudah memiliki jadwal kerja pada tanggal tersebut.');

        $this->assertDatabaseCount('tbl_pertukaran_shift', 0);
    }

    public function test_request_with_a_coworker_of_another_position_is_rejected(): void
    {
        $driverId = $this->createEmployee('SJI006', 'Dodi Driver', self::LAYANAN_DRIVER);
        $this->createPlacement(106, $driverId, $this->createClient('KL004'));

        $this->actingAs($this->employeeUser($this->requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload(['id_karyawan_rekan' => $driverId]))
            ->assertSessionHas('gagal', 'Rekan pengganti harus memiliki jabatan/jenis layanan yang sama.');
    }

    public function test_request_for_today_is_rejected_because_it_must_be_submitted_one_day_ahead(): void
    {
        $todayScheduleId = $this->createSchedule(101, '2026-11-17', '15:00', '23:00', 'Siang');

        $this->actingAs($this->employeeUser($this->requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload(['id_jadwal' => $todayScheduleId]))
            ->assertSessionHas('gagal', 'Pengajuan backup shift paling lambat H-1 sebelum tanggal shift.');
    }

    public function test_second_request_for_a_schedule_that_is_still_running_is_rejected(): void
    {
        $this->submitRequest();

        $this->actingAs($this->employeeUser($this->requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload())
            ->assertSessionHas('gagal', 'Jadwal ini sudah memiliki pengajuan backup shift yang masih berjalan.');

        $this->assertDatabaseCount('tbl_pertukaran_shift', 1);
    }

    public function test_operasional_can_approve_only_after_the_coworker_accepts(): void
    {
        $requestId = $this->submitRequest();
        $operasional = $this->operasionalUser();
        $approveUrl = route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId);

        $this->actingAs($operasional)->get(route('operasional.jadwal-kerja.pertukaran-shift.index'))
            ->assertOk()->assertSee('Menunggu Tanggapan Rekan')->assertDontSee($approveUrl, false);
        $this->actingAs($operasional)->patch($approveUrl)
            ->assertSessionHas('error', 'Rekan pengganti belum menerima permintaan ini.');

        $this->respond($requestId, 'terima');

        $this->actingAs($operasional)->get(route('operasional.jadwal-kerja.pertukaran-shift.index'))
            ->assertOk()->assertSee($approveUrl, false);
    }

    public function test_approval_assigns_the_shift_to_the_coworker_and_records_who_decided(): void
    {
        $requestId = $this->acceptedRequest();
        $operasional = $this->operasionalUser();

        $this->actingAs($operasional)
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId))
            ->assertSessionHas('success', 'Pengajuan disetujui. Shift sudah ditugaskan kepada rekan pengganti.');

        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->requesterScheduleId, 'id_karyawan_pengganti' => $this->partnerId, 'jam_masuk' => '07:00:00']);
        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Disetujui', 'diproses_oleh' => $operasional->id]);
        $this->assertSame(1, DB::table('tbl_jadwal_kerja_riwayat')->where('id_jadwal_kerja', $this->requesterScheduleId)->count());
    }

    public function test_coworker_rejection_closes_the_request_without_operasional(): void
    {
        $requestId = $this->submitRequest();

        $this->respond($requestId, 'tolak')->assertSessionHas('sukses', 'Anda menolak permintaan backup shift. Statusnya dapat dilihat pengaju di menu Backup Shift.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Ditolak', 'status_rekan' => 'Ditolak', 'diproses_oleh' => null]);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->requesterScheduleId, 'id_karyawan_pengganti' => null]);
    }

    public function test_only_the_requested_coworker_can_respond(): void
    {
        $requestId = $this->submitRequest();

        $this->actingAs($this->employeeUser($this->requesterId))
            ->patch(route('tenaga-kerja.tukar-shift.tanggapi', $requestId), ['keputusan' => 'terima'])
            ->assertSessionHas('gagal', 'Permintaan backup shift tidak ditemukan.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status_rekan' => 'Menunggu']);
    }

    public function test_coworker_response_shows_unavailable_message_when_backup_schema_is_missing(): void
    {
        Schema::table('tbl_jadwal_kerja', function (Blueprint $table): void {
            $table->dropColumn('id_karyawan_pengganti');
        });

        $this->actingAs($this->employeeUser($this->partnerId))
            ->patch(route('tenaga-kerja.tukar-shift.tanggapi', 1), ['keputusan' => 'terima'])
            ->assertRedirect(route('tenaga-kerja.tukar-shift.index'))
            ->assertSessionHas('gagal', 'Fitur backup shift belum tersedia. Hubungi bagian Operasional.');
    }

    public function test_operasional_rejection_keeps_the_schedule_unchanged(): void
    {
        $requestId = $this->acceptedRequest();

        $this->actingAs($this->operasionalUser())
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.reject', $requestId), ['catatan_keputusan' => 'Kuota penuh.'])
            ->assertSessionHas('success', 'Pengajuan ditolak. Jadwal pengaju tetap seperti semula.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Ditolak', 'catatan_keputusan' => 'Kuota penuh.']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->requesterScheduleId, 'id_karyawan_pengganti' => null]);
    }

    public function test_requester_cancels_with_an_explicit_status_and_can_submit_again(): void
    {
        $requestId = $this->submitRequest();

        $this->actingAs($this->employeeUser($this->requesterId))
            ->patch(route('tenaga-kerja.tukar-shift.batalkan', $requestId))
            ->assertSessionHas('sukses', 'Permintaan backup shift dibatalkan. Jadwal Anda tetap seperti semula.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Dibatalkan', 'catatan_keputusan' => null]);
        $this->submitRequest();
        $this->assertDatabaseCount('tbl_pertukaran_shift', 2);
    }

    public function test_only_the_requester_can_cancel_a_request(): void
    {
        $requestId = $this->submitRequest();

        $this->actingAs($this->employeeUser($this->partnerId))
            ->patch(route('tenaga-kerja.tukar-shift.batalkan', $requestId))
            ->assertSessionHas('gagal', 'Pengajuan backup shift tidak ditemukan.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Menunggu']);
    }

    public function test_roles_other_than_operasional_cannot_approve(): void
    {
        $requestId = $this->acceptedRequest();

        $this->actingAs($this->employeeUser($this->requesterId))
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId))
            ->assertForbidden();

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Menunggu']);
    }

    public function test_request_expires_when_operasional_has_not_decided_by_shift_start(): void
    {
        $requestId = $this->acceptedRequest();
        $operasional = $this->operasionalUser();

        $this->travelTo(CarbonImmutable::parse(self::TANGGAL_SHIFT.' 07:00:00'));

        $this->actingAs($operasional)
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId))
            ->assertSessionHas('error', 'Pengajuan ini sudah kedaluwarsa sebelumnya dan tidak dapat diproses lagi.');
        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Kedaluwarsa', 'diproses_oleh' => null]);
    }

    public function test_sidebar_badge_and_beranda_count_only_requests_accepted_by_the_coworker(): void
    {
        $requestId = $this->submitRequest();
        $operasional = $this->operasionalUser();

        $this->actingAs($operasional)->get(route('operasional.jadwal-kerja'))
            ->assertOk()->assertDontSee('data-badge-menu="operasional.jadwal-kerja"', false);

        $this->respond($requestId, 'terima');

        $this->actingAs($operasional)->get(route('operasional.jadwal-kerja'))
            ->assertOk()->assertSee('data-badge-menu="operasional.jadwal-kerja">1</span>', false);
        $this->actingAs($operasional)->get(route('operasional.beranda'))
            ->assertOk()->assertSee('Tinjau 1 pengajuan');
    }

    public function test_detail_page_warns_when_the_schedule_changed_after_the_request(): void
    {
        $requestId = $this->acceptedRequest();
        DB::table('tbl_jadwal_kerja')->where('id', $this->requesterScheduleId)->update(['jam_masuk' => '08:00:00']);

        $this->actingAs($this->operasionalUser())
            ->get(route('operasional.jadwal-kerja.pertukaran-shift.show', $requestId))
            ->assertOk()
            ->assertSee('Jadwal pengaju sudah diubah setelah pengajuan dibuat.');
    }

    public function test_list_filters_by_shift_date_and_rejects_an_end_date_before_the_start_date(): void
    {
        $this->submitRequest();
        $operasional = $this->operasionalUser();

        $this->actingAs($operasional)
            ->get(route('operasional.jadwal-kerja.pertukaran-shift.index', ['tanggal_dari' => '2026-11-21']))
            ->assertOk()->assertDontSee('Andi Pengaju');
        $this->actingAs($operasional)
            ->get(route('operasional.jadwal-kerja.pertukaran-shift.index', ['tanggal_dari' => '2026-11-21', 'tanggal_sampai' => '2026-11-20']))
            ->assertSessionHasErrors(['tanggal_sampai' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function requestPayload(array $override = []): array
    {
        return [
            'id_jadwal' => $this->requesterScheduleId,
            'id_karyawan_rekan' => $this->partnerId,
            'alasan' => 'Ada keperluan keluarga pada pagi hari.',
            ...$override,
        ];
    }

    private function submitRequest(): int
    {
        $this->actingAs($this->employeeUser($this->requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload())
            ->assertSessionHas('sukses');

        return (int) DB::table('tbl_pertukaran_shift')->latest('id')->value('id');
    }

    private function respond(int $requestId, string $decision): TestResponse
    {
        return $this->actingAs($this->employeeUser($this->partnerId))
            ->patch(route('tenaga-kerja.tukar-shift.tanggapi', $requestId), ['keputusan' => $decision]);
    }

    private function acceptedRequest(): int
    {
        $requestId = $this->submitRequest();
        $this->respond($requestId, 'terima')->assertSessionHas('sukses');

        return $requestId;
    }

    private function employeeUser(int $employeeId): User
    {
        return User::query()->where('id_karyawan', $employeeId)->first()
            ?? User::factory()->withRole(Role::KaryawanLapangan)->create(['id_karyawan' => $employeeId]);
    }

    private function operasionalUser(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }

    private function createTables(): void
    {
        Schema::create('tbl_klien', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_klien');
            $table->string('nama_perusahaan');
            $table->string('status')->default('Aktif');
        });
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip');
            $table->string('nama');
            $table->unsignedInteger('id_jenis_layanan')->nullable();
        });
        Schema::create('tbl_penempatan', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->unsignedInteger('id_klien');
            $table->date('tgl_mulai');
            $table->date('tgl_selesai')->nullable();
            $table->time('jam_datang')->nullable();
            $table->time('jam_pulang')->nullable();
            $table->string('status');
        });
        Schema::create('tbl_jadwal_kerja', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_penempatan');
            $table->date('tanggal_kerja');
            $table->string('status');
            $table->unsignedInteger('id_template_shift')->nullable();
            $table->string('nama_shift_snapshot')->nullable();
            $table->time('jam_masuk')->nullable();
            $table->time('jam_pulang')->nullable();
            $table->unsignedSmallInteger('durasi_menit')->nullable();
            $table->integer('id_karyawan_pengganti')->nullable();
            $table->integer('dibuat_oleh')->nullable();
            $table->integer('diubah_oleh')->nullable();
            $table->timestamps();
            $table->unique(['id_penempatan', 'tanggal_kerja']);
        });
        Schema::create('tbl_jadwal_kerja_riwayat', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_jadwal_kerja');
            $table->string('aksi');
            $table->json('data_sebelum')->nullable();
            $table->json('data_sesudah');
            $table->integer('diubah_oleh')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_karyawan_pengaju');
            $table->integer('id_karyawan_rekan');
            $table->unsignedInteger('id_jadwal_pengaju');
            $table->unsignedInteger('id_jadwal_aktif')->nullable()
                ->virtualAs("CASE WHEN status IN ('Menunggu', 'Disetujui') THEN id_jadwal_pengaju END");
            $table->date('tanggal_kerja');
            $table->json('snapshot_pengaju');
            $table->string('alasan', 500);
            $table->string('status')->default('Menunggu');
            $table->string('status_rekan')->default('Menunggu');
            $table->string('catatan_rekan', 500)->nullable();
            $table->timestamp('ditanggapi_rekan_pada')->nullable();
            $table->string('catatan_keputusan', 500)->nullable();
            $table->integer('diproses_oleh')->nullable();
            $table->timestamp('diproses_pada')->nullable();
            $table->timestamps();
            $table->unique('id_jadwal_aktif');
        });
    }

    private function createClient(string $code): int
    {
        return (int) DB::table('tbl_klien')->insertGetId(['kode_klien' => $code, 'nama_perusahaan' => 'Klien '.$code]);
    }

    private function createEmployee(string $nip, string $name, int $serviceId = self::LAYANAN_SATPAM): int
    {
        return (int) DB::table('tbl_karyawan')->insertGetId(['nip' => $nip, 'nama' => $name, 'id_jenis_layanan' => $serviceId]);
    }

    private function createPlacement(int $id, int $employeeId, int $clientId): void
    {
        DB::table('tbl_penempatan')->insert([
            'id' => $id,
            'id_karyawan' => $employeeId,
            'id_klien' => $clientId,
            'tgl_mulai' => '2026-01-01',
            'tgl_selesai' => null,
            'status' => 'Aktif',
        ]);
    }

    private function createSchedule(int $placementId, string $date, string $start, string $end, string $shiftName): int
    {
        $toMinutes = static fn (string $time): int => ((int) substr($time, 0, 2) * 60) + (int) substr($time, 3, 2);

        return (int) DB::table('tbl_jadwal_kerja')->insertGetId([
            'id_penempatan' => $placementId,
            'tanggal_kerja' => $date,
            'status' => 'Kerja',
            'nama_shift_snapshot' => $shiftName,
            'jam_masuk' => $start.':00',
            'jam_pulang' => $end.':00',
            'durasi_menit' => ($toMinutes($end) - $toMinutes($start) + 1440) % 1440,
        ]);
    }

    private function createDayOff(int $placementId, string $date): void
    {
        DB::table('tbl_jadwal_kerja')->insert(['id_penempatan' => $placementId, 'tanggal_kerja' => $date, 'status' => 'Libur']);
    }
}

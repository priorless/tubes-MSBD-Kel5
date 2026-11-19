<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Pengajuan tukar shift oleh karyawan (menu Tukar Shift) dan persetujuan Operasional (menu Jadwal Kerja).
 * Skema SQLite ringkas; tbl_pertukaran_shift dibuat oleh migration aslinya.
 */
class PertukaranShiftTest extends TestCase
{
    use CreatesSjiAuthTables;

    private const string TANGGAL_SHIFT = '2026-11-20';

    private const int LAYANAN_SATPAM = 1;

    private const int LAYANAN_DRIVER = 2;

    private int $clientId;

    private int $requesterId;

    private int $partnerId;

    private int $requesterScheduleId;

    private int $partnerScheduleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00:00'));
        $this->createTables();

        $this->clientId = $this->createClient('KL001');
        $this->requesterId = $this->createEmployee('SJI001', 'Andi Pengaju');
        $this->partnerId = $this->createEmployee('SJI002', 'Budi Rekan');
        $this->createPlacement(101, $this->requesterId, $this->clientId);
        $this->createPlacement(102, $this->partnerId, $this->clientId);
        $this->requesterScheduleId = $this->createSchedule(101, self::TANGGAL_SHIFT, '07:00', '15:00', 'Pagi');
        $this->partnerScheduleId = $this->createSchedule(102, self::TANGGAL_SHIFT, '15:00', '23:00', 'Siang');
    }

    public function test_employee_submits_a_swap_request_that_waits_for_approval(): void
    {
        $this->actingAs($this->employeeUser($this->requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload())
            ->assertRedirect(route('tenaga-kerja.tukar-shift.index'))
            ->assertSessionHas('sukses', 'Pengajuan tukar shift terkirim. Menunggu persetujuan rekan, lalu persetujuan akhir Operasional.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', [
            'id_karyawan_pengaju' => $this->requesterId,
            'id_karyawan_rekan' => $this->partnerId,
            'id_jadwal_pengaju' => $this->requesterScheduleId,
            'id_jadwal_rekan' => $this->partnerScheduleId,
            'status' => 'Menunggu',
            'diproses_oleh' => null,
        ]);
        $this->assertScheduleHours($this->requesterScheduleId, '07:00:00', 'Pagi');
    }

    public function test_tukar_shift_page_offers_only_coworkers_with_the_same_service(): void
    {
        $driverId = $this->createEmployee('SJI003', 'Citra Driver', self::LAYANAN_DRIVER);
        $this->createPlacement(103, $driverId, $this->clientId);
        $this->createSchedule(103, self::TANGGAL_SHIFT, '15:00', '23:00', 'Siang');

        $this->actingAs($this->employeeUser($this->requesterId))
            ->get(route('tenaga-kerja.tukar-shift.index'))
            ->assertOk()
            ->assertSee('Ajukan Tukar Shift dengan Rekan')
            ->assertSee('Budi Rekan', false)
            ->assertDontSee('Citra Driver', false);
    }

    public function test_request_with_a_coworker_from_another_service_is_rejected(): void
    {
        $driverId = $this->createEmployee('SJI003', 'Citra Driver', self::LAYANAN_DRIVER);
        $this->createPlacement(103, $driverId, $this->clientId);
        $this->createSchedule(103, self::TANGGAL_SHIFT, '15:00', '23:00', 'Siang');

        $this->actingAs($this->employeeUser($this->requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload(['id_karyawan_rekan' => $driverId]))
            ->assertSessionHas('gagal', 'Pertukaran hanya dapat dilakukan dengan rekan yang memiliki jenis layanan yang sama.');

        $this->assertDatabaseCount('tbl_pertukaran_shift', 0);
    }

    public function test_approval_is_refused_when_the_employees_no_longer_share_a_service(): void
    {
        $requestId = $this->createPendingRequest();
        DB::table('tbl_karyawan')->where('id', $this->partnerId)->update(['id_jenis_layanan' => self::LAYANAN_DRIVER]);

        $this->actingAs($this->operasionalUser())
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId))
            ->assertSessionHas('error', 'Kedua karyawan tidak memiliki jenis layanan yang sama.');

        $this->assertScheduleHours($this->requesterScheduleId, '07:00:00', 'Pagi');
        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Menunggu']);
    }

    public function test_coworker_cannot_accept_when_a_schedule_changed_after_the_request(): void
    {
        $requestId = $this->submitRequest();
        DB::table('tbl_jadwal_kerja')->where('id', $this->partnerScheduleId)->update(['jam_masuk' => '16:00:00', 'jam_pulang' => '23:59:00']);

        $this->actingAs($this->employeeUser($this->partnerId))
            ->patch(route('tenaga-kerja.tukar-shift.tanggapi', $requestId), ['tanggapan' => 'terima'])
            ->assertRedirect(route('tenaga-kerja.tukar-shift.index'))
            ->assertSessionHas('gagal', 'Jadwal salah satu karyawan sudah diubah setelah pengajuan dibuat. Tolak pengajuan ini dan minta karyawan mengajukan ulang.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Menunggu', 'status_rekan' => 'Menunggu']);
    }

    public function test_requester_cancels_a_pending_request_and_can_submit_again(): void
    {
        $requestId = $this->submitRequest();
        $requester = $this->employeeUser($this->requesterId);

        $this->actingAs($requester)
            ->patch(route('tenaga-kerja.tukar-shift.batalkan', $requestId))
            ->assertRedirect(route('tenaga-kerja.tukar-shift.index'))
            ->assertSessionHas('sukses', 'Pengajuan tukar shift dibatalkan. Jadwal Anda tetap seperti semula.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', [
            'id' => $requestId,
            'status' => 'Ditolak',
            'catatan_keputusan' => 'Dibatalkan oleh pengaju.',
            'diproses_oleh' => $requester->id,
        ]);
        $this->assertScheduleHours($this->requesterScheduleId, '07:00:00', 'Pagi');
        $this->actingAs($requester)->get(route('tenaga-kerja.tukar-shift.index'))->assertSee('Dibatalkan');
        $this->actingAs($requester)
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload())
            ->assertSessionHas('sukses');
    }

    public function test_only_the_requester_can_cancel_a_request(): void
    {
        $requestId = $this->submitRequest();

        $this->actingAs($this->employeeUser($this->partnerId))
            ->patch(route('tenaga-kerja.tukar-shift.batalkan', $requestId))
            ->assertSessionHas('gagal', 'Pengajuan pertukaran shift tidak ditemukan.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Menunggu']);
    }

    public function test_pending_request_whose_date_has_passed_is_shown_as_expired(): void
    {
        $this->submitRequest();
        $this->travelTo(CarbonImmutable::parse('2026-11-21 09:00:00'));

        $this->actingAs($this->employeeUser($this->requesterId))
            ->get(route('tenaga-kerja.tukar-shift.index'))
            ->assertOk()
            ->assertSee('Kedaluwarsa')
            ->assertDontSee('Batalkan</button>', false);
    }

    public function test_request_for_today_is_rejected_because_it_must_be_submitted_one_day_ahead(): void
    {
        $this->createSchedule(101, '2026-11-17', '07:00', '15:00', 'Pagi');
        $this->createSchedule(102, '2026-11-17', '15:00', '23:00', 'Siang');

        $this->actingAs($this->employeeUser($this->requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload(['tanggal_kerja' => '2026-11-17']))
            ->assertSessionHas('gagal', 'Pengajuan pertukaran shift paling lambat H-1 sebelum tanggal shift.');

        $this->assertDatabaseCount('tbl_pertukaran_shift', 0);
    }

    public function test_request_with_a_coworker_from_another_client_is_rejected(): void
    {
        $otherClientId = $this->createClient('KL002');
        $outsiderId = $this->createEmployee('SJI003', 'Citra Klien Lain');
        $this->createPlacement(103, $outsiderId, $otherClientId);
        $this->createSchedule(103, self::TANGGAL_SHIFT, '15:00', '23:00', 'Siang');

        $this->actingAs($this->employeeUser($this->requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload(['id_karyawan_rekan' => $outsiderId]))
            ->assertSessionHas('gagal', 'Pertukaran hanya dapat dilakukan dengan rekan pada klien yang sama.');

        $this->assertDatabaseCount('tbl_pertukaran_shift', 0);
    }

    public function test_second_request_for_a_schedule_that_is_already_pending_is_rejected(): void
    {
        $this->createPendingRequest();

        $this->actingAs($this->employeeUser($this->partnerId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload(['id_karyawan_rekan' => $this->requesterId]))
            ->assertSessionHas('gagal', 'Sudah ada pengajuan pertukaran untuk jadwal ini yang masih menunggu persetujuan.');

        $this->assertDatabaseCount('tbl_pertukaran_shift', 1);
    }

    public function test_pending_request_appears_on_the_operasional_schedule_tab(): void
    {
        $this->createPendingRequest();

        $this->actingAs($this->operasionalUser())
            ->get(route('operasional.jadwal-kerja.pertukaran-shift.index'))
            ->assertOk()
            ->assertSeeInOrder(['Andi Pengaju', 'Budi Rekan', '20/11/2026', 'Pagi · 07:00–15:00', 'Siang · 15:00–23:00', 'Menunggu Persetujuan'], false);
    }

    public function test_list_shows_requests_from_all_employees_with_pending_first_without_choosing_an_employee(): void
    {
        $processedId = $this->createPendingRequest();
        $this->actingAs($this->operasionalUser())->patch(route('operasional.jadwal-kerja.pertukaran-shift.reject', $processedId));
        $this->createSecondClientRequest();

        $this->actingAs($this->operasionalUser())
            ->get(route('operasional.jadwal-kerja.pertukaran-shift.index'))
            ->assertOk()
            ->assertSeeInOrder(['Citra Pemohon', 'Klien KL002', 'Menunggu Persetujuan', 'Andi Pengaju', 'Klien KL001', 'Ditolak'], false);
    }

    public function test_list_filters_by_employee_name_client_and_date(): void
    {
        $this->createPendingRequest();
        $this->createSecondClientRequest();
        $operasional = $this->operasionalUser();
        $secondClientId = (int) DB::table('tbl_klien')->where('kode_klien', 'KL002')->value('id');
        $index = fn (array $filter) => $this->actingAs($operasional)->get(route('operasional.jadwal-kerja.pertukaran-shift.index', $filter))->assertOk();

        $index(['cari' => 'budi'])->assertSee('Andi Pengaju')->assertDontSee('Citra Pemohon');
        $index(['id_klien' => $secondClientId])->assertSee('Citra Pemohon')->assertDontSee('Andi Pengaju');
        $index(['tanggal_dari' => '2026-11-21', 'tanggal_sampai' => '2026-11-30'])->assertSee('Citra Pemohon')->assertDontSee('Andi Pengaju');
        $index(['status' => 'Disetujui'])->assertDontSee('Citra Pemohon')->assertDontSee('Andi Pengaju');
    }

    public function test_list_rejects_an_end_date_before_the_start_date(): void
    {
        $this->actingAs($this->operasionalUser())
            ->get(route('operasional.jadwal-kerja.pertukaran-shift.index', ['tanggal_dari' => '2026-11-20', 'tanggal_sampai' => '2026-11-19']))
            ->assertSessionHasErrors(['tanggal_sampai' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
    }

    public function test_sidebar_badge_counts_only_pending_requests_and_drops_after_processing(): void
    {
        $firstId = $this->createPendingRequest();
        $this->createSecondClientRequest();
        $operasional = $this->operasionalUser();
        $badge = static fn (int $jumlah): string => 'data-badge-menu="operasional.jadwal-kerja">'.$jumlah.'</span>';

        $this->actingAs($operasional)->get(route('operasional.beranda'))->assertSee($badge(2), false);

        $this->actingAs($operasional)->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $firstId));

        $this->actingAs($operasional)->get(route('operasional.beranda'))->assertSee($badge(1), false);
    }

    public function test_sidebar_badge_skips_pending_requests_whose_date_has_passed(): void
    {
        $this->createPendingRequest();
        $this->createSecondClientRequest();
        $this->travelTo(CarbonImmutable::parse('2026-11-21 09:00:00'));

        $this->actingAs($this->operasionalUser())
            ->get(route('operasional.beranda'))
            ->assertSee('data-badge-menu="operasional.jadwal-kerja">1</span>', false);
    }

    public function test_beranda_summarises_requests_per_status_with_links_to_the_filtered_list(): void
    {
        $approvedId = $this->createPendingRequest();
        $operasional = $this->operasionalUser();
        $this->actingAs($operasional)->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $approvedId));
        $this->createSecondClientRequest();

        $this->actingAs($operasional)
            ->get(route('operasional.beranda'))
            ->assertOk()
            ->assertSeeTextInOrder(['Pengajuan Pertukaran Shift', 'Menunggu persetujuan', '1', 'Disetujui', '1', 'Ditolak', '0'])
            ->assertSee(route('operasional.jadwal-kerja.pertukaran-shift.index', ['status' => 'Menunggu']), false)
            ->assertSee('Pengajuan pertukaran shift menunggu persetujuan');
    }

    public function test_approval_swaps_both_schedules_and_records_who_decided(): void
    {
        $requestId = $this->createPendingRequest();
        $operasional = $this->operasionalUser();

        $this->actingAs($operasional)
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId))
            ->assertRedirect(route('operasional.jadwal-kerja.pertukaran-shift.show', $requestId))
            ->assertSessionHas('success', 'Pengajuan disetujui. Jadwal kedua karyawan sudah ditukar.');

        $this->assertScheduleHours($this->requesterScheduleId, '15:00:00', 'Siang');
        $this->assertScheduleHours($this->partnerScheduleId, '07:00:00', 'Pagi');
        $this->assertDatabaseHas('tbl_pertukaran_shift', [
            'id' => $requestId,
            'status' => 'Disetujui',
            'diproses_oleh' => $operasional->id,
            'diproses_pada' => '2026-11-17 09:00:00',
        ]);
        $this->assertSame(2, DB::table('tbl_jadwal_kerja_riwayat')->where('aksi', 'Diubah')->where('diubah_oleh', $operasional->id)->count());
    }

    public function test_rejection_keeps_both_schedules_unchanged(): void
    {
        $requestId = $this->createPendingRequest();
        $operasional = $this->operasionalUser();

        $this->actingAs($operasional)
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.reject', $requestId), ['catatan_keputusan' => 'Kuota shift siang penuh.'])
            ->assertSessionHas('success', 'Pengajuan ditolak. Jadwal kedua karyawan tetap seperti semula.');

        $this->assertScheduleHours($this->requesterScheduleId, '07:00:00', 'Pagi');
        $this->assertScheduleHours($this->partnerScheduleId, '15:00:00', 'Siang');
        $this->assertDatabaseHas('tbl_pertukaran_shift', [
            'id' => $requestId,
            'status' => 'Ditolak',
            'catatan_keputusan' => 'Kuota shift siang penuh.',
            'diproses_oleh' => $operasional->id,
        ]);
        $this->assertDatabaseCount('tbl_jadwal_kerja_riwayat', 0);
    }

    public function test_processed_request_cannot_be_approved_or_rejected_again(): void
    {
        $requestId = $this->createPendingRequest();
        $operasional = $this->operasionalUser();
        $this->actingAs($operasional)->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId));

        $this->actingAs($operasional)
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId))
            ->assertSessionHas('error', 'Pengajuan ini sudah disetujui sebelumnya dan tidak dapat diproses lagi.');
        $this->actingAs($operasional)
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.reject', $requestId))
            ->assertSessionHas('error', 'Pengajuan ini sudah disetujui sebelumnya dan tidak dapat diproses lagi.');

        $this->assertScheduleHours($this->requesterScheduleId, '15:00:00', 'Siang');
        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Disetujui']);
        $this->assertDatabaseCount('tbl_jadwal_kerja_riwayat', 2);
    }

    public function test_roles_other_than_operasional_cannot_approve(): void
    {
        $requestId = $this->createPendingRequest();

        $this->actingAs(User::factory()->withRole(Role::Hrd)->create())
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId))
            ->assertForbidden();
        $this->actingAs($this->employeeUser($this->requesterId))
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId))
            ->assertForbidden();

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Menunggu']);
        $this->assertScheduleHours($this->requesterScheduleId, '07:00:00', 'Pagi');
    }

    public function test_approval_is_refused_when_a_schedule_changed_after_the_request(): void
    {
        $requestId = $this->createPendingRequest();
        DB::table('tbl_jadwal_kerja')->where('id', $this->partnerScheduleId)->update(['jam_masuk' => '16:00:00', 'jam_pulang' => '23:59:00']);

        $this->actingAs($this->operasionalUser())
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId))
            ->assertSessionHas('error', 'Jadwal salah satu karyawan sudah diubah setelah pengajuan dibuat. Tolak pengajuan ini dan minta karyawan mengajukan ulang.');

        $this->assertScheduleHours($this->requesterScheduleId, '07:00:00', 'Pagi');
        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Menunggu']);
    }

    public function test_coworker_cannot_accept_when_the_new_shift_overlaps_the_next_day(): void
    {
        DB::table('tbl_jadwal_kerja')->where('id', $this->partnerScheduleId)->update([
            'jam_masuk' => '22:00:00', 'jam_pulang' => '06:00:00', 'nama_shift_snapshot' => 'Malam',
        ]);
        $this->createSchedule(101, '2026-11-21', '05:00', '13:00', 'Subuh');
        $requestId = $this->submitRequest();

        $this->actingAs($this->employeeUser($this->partnerId))
            ->patch(route('tenaga-kerja.tukar-shift.tanggapi', $requestId), ['tanggapan' => 'terima'])
            ->assertSessionHas('gagal', 'Shift baru Andi Pengaju bertumpuk dengan jadwalnya pada 21/11/2026.');

        $this->assertScheduleHours($this->requesterScheduleId, '07:00:00', 'Pagi');
        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Menunggu', 'status_rekan' => 'Menunggu']);
    }

    public function test_approval_is_refused_when_the_new_shift_overlaps_a_day_scheduled_after_the_coworker_accepted(): void
    {
        DB::table('tbl_jadwal_kerja')->where('id', $this->partnerScheduleId)->update([
            'jam_masuk' => '22:00:00', 'jam_pulang' => '06:00:00', 'nama_shift_snapshot' => 'Malam',
        ]);
        $requestId = $this->createPendingRequest();
        $this->createSchedule(101, '2026-11-21', '05:00', '13:00', 'Subuh');

        $this->actingAs($this->operasionalUser())
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $requestId))
            ->assertSessionHas('error', 'Shift baru Andi Pengaju bertumpuk dengan jadwalnya pada 21/11/2026.');

        $this->assertScheduleHours($this->requesterScheduleId, '07:00:00', 'Pagi');
        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $requestId, 'status' => 'Menunggu']);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function requestPayload(array $override = []): array
    {
        return array_filter([
            'tanggal_kerja' => self::TANGGAL_SHIFT,
            'id_karyawan_rekan' => $this->partnerId,
            'alasan' => 'Ada keperluan keluarga pada pagi hari.',
            ...$override,
        ], static fn (mixed $nilai): bool => $nilai !== null);
    }

    /**
     * Pengajuan baru yang masih menunggu tanggapan rekan.
     */
    private function submitRequest(): int
    {
        $this->actingAs($this->employeeUser($this->requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), $this->requestPayload())
            ->assertSessionHas('sukses');

        return (int) DB::table('tbl_pertukaran_shift')->latest('id')->value('id');
    }

    /**
     * Pengajuan yang sudah diterima rekan sehingga menunggu keputusan Operasional.
     */
    private function createPendingRequest(): int
    {
        $requestId = $this->submitRequest();

        $this->actingAs($this->employeeUser($this->partnerId))
            ->patch(route('tenaga-kerja.tukar-shift.tanggapi', $requestId), ['tanggapan' => 'terima'])
            ->assertSessionHas('sukses');

        return $requestId;
    }

    /**
     * Pengajuan kedua (Menunggu) dari pasangan karyawan lain di klien KL002 untuk tanggal 22/11/2026.
     */
    private function createSecondClientRequest(): int
    {
        $clientId = $this->createClient('KL002');
        $requesterId = $this->createEmployee('SJI003', 'Citra Pemohon');
        $partnerId = $this->createEmployee('SJI004', 'Dodi Rekan');
        $this->createPlacement(103, $requesterId, $clientId);
        $this->createPlacement(104, $partnerId, $clientId);
        $this->createSchedule(103, '2026-11-22', '07:00', '15:00', 'Pagi');
        $this->createSchedule(104, '2026-11-22', '15:00', '23:00', 'Siang');

        $this->actingAs($this->employeeUser($requesterId))
            ->post(route('tenaga-kerja.tukar-shift.store'), [
                ...$this->requestPayload(),
                'tanggal_kerja' => '2026-11-22',
                'id_karyawan_rekan' => $partnerId,
            ])
            ->assertSessionHas('sukses');

        return (int) DB::table('tbl_pertukaran_shift')->latest('id')->value('id');
    }

    private function assertScheduleHours(int $scheduleId, string $start, string $shiftName): void
    {
        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'id' => $scheduleId,
            'jam_masuk' => $start,
            'nama_shift_snapshot' => $shiftName,
        ]);
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

        (require database_path('migrations/2026_11_17_132028_create_tbl_pertukaran_shift_table.php'))->up();
        (require database_path('migrations/2026_11_17_230643_add_persetujuan_rekan_to_tbl_pertukaran_shift_table.php'))->up();
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
}

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
use Tests\Concerns\CreatesTemplateJadwalTables;
use Tests\TestCase;

class RekapOperasionalTest extends TestCase
{
    use CreatesSjiAuthTables;
    use CreatesTemplateJadwalTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00:00'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reportRoutes(): array
    {
        return ['page' => ['operasional.rekap.index'], 'csv' => ['operasional.rekap.export']];
    }

    #[DataProvider('reportRoutes')]
    public function test_guest_is_redirected_to_login(string $routeName): void
    {
        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenRolesAndRoutes(): array
    {
        $cases = [];

        foreach ([Role::Hrd, Role::Keuangan, Role::Ceo] as $role) {
            foreach (self::reportRoutes() as $key => [$routeName]) {
                $cases["{$role} {$key}"] = [$role, $routeName];
            }
        }

        return $cases;
    }

    #[DataProvider('forbiddenRolesAndRoutes')]
    public function test_other_roles_are_forbidden(string $role, string $routeName): void
    {
        $this->createReportData(false);

        $this->actingAs(User::factory()->withRole($role)->create())->get(route($routeName))->assertForbidden();
    }

    public function test_summary_compares_schedule_with_attendance(): void
    {
        $this->createReportData(false);

        $this->actingAs($this->operasional())
            ->get(route('operasional.rekap.index', ['bulan' => '2026-11']))
            ->assertOk()
            ->assertSeeText('Rekap kerja bulanan November 2026')
            ->assertSeeTextInOrder(['Karyawan', '2', 'Hari kerja terjadwal', '5', 'Hari hadir', '4', '1 hari terlambat', 'Tidak hadir', '3', 'Jam lembur', '2', '19 jam kerja tercatat']);
    }

    public function test_detail_row_per_employee(): void
    {
        $this->createReportData(false);

        $this->actingAs($this->operasional())
            ->get(route('operasional.rekap.index', ['bulan' => '2026-11']))
            ->assertOk()
            ->assertSeeTextInOrder(['Andi', 'SJI001 · PT Alpha', '5 / 2 / 23', '4', '1 hari', '0 / 1 / 0', '1', '19 jam', '2 jam', 'Status persetujuan belum tersedia'])
            ->assertSeeTextInOrder(['Budi', 'SJI002 · PT Beta', '0 / 0 / 3', '0', '0 hari', '0 / 0 / 1', '0', '0 jam', '0 jam']);
    }

    public function test_incomplete_data_is_listed_with_dates(): void
    {
        $this->createReportData(false);

        $this->actingAs($this->operasional())
            ->get(route('operasional.rekap.index', ['bulan' => '2026-11']))
            ->assertOk()
            ->assertSeeTextInOrder([
                'Data belum lengkap',
                'Andi: 23 tanggal jadwal belum diatur',
                'Andi: 1 hari kerja terjadwal tanpa catatan absensi', '05/11',
                'Andi: 2 kehadiran pada tanggal yang tidak dijadwalkan kerja', '06/11, 07/11',
                'Andi: 1 jam keluar lebih awal dari jam masuk (tercatat 0 jam)', '07/11',
                'Budi: 3 tanggal jadwal belum diatur',
            ])
            ->assertDontSeeText('20/11');
    }

    public function test_missing_columns_are_explained_instead_of_guessed(): void
    {
        $this->createReportData(false);

        $this->actingAs($this->operasional())
            ->get(route('operasional.rekap.index', ['bulan' => '2026-11']))
            ->assertOk()
            ->assertSeeText('Menit keterlambatan belum dicatat')
            ->assertSeeText('Status persetujuan lembur belum dicatat');
    }

    public function test_minutes_late_and_overtime_approval_are_shown_when_recorded(): void
    {
        $this->createReportData(true);

        $this->actingAs($this->operasional())
            ->get(route('operasional.rekap.index', ['bulan' => '2026-11', 'id_karyawan' => 1]))
            ->assertOk()
            ->assertSeeText('20 menit')
            ->assertSeeText('2 disetujui · 0 menunggu')
            ->assertDontSeeText('Sebagian data belum tersedia di database');
    }

    public function test_client_and_employee_filters_narrow_the_report(): void
    {
        $this->createReportData(false);
        $this->actingAs($this->operasional());

        $this->get(route('operasional.rekap.index', ['bulan' => '2026-11', 'id_klien' => 2]))
            ->assertOk()
            ->assertSeeText('SJI002 · PT Beta')
            ->assertDontSeeText('SJI001 · PT Alpha');
        $this->get(route('operasional.rekap.index', ['bulan' => '2026-11', 'id_karyawan' => 1]))
            ->assertOk()
            ->assertSeeText('SJI001 · PT Alpha')
            ->assertDontSeeText('SJI002 · PT Beta');
    }

    public function test_empty_month_shows_guidance(): void
    {
        $this->createReportData(false);

        $this->actingAs($this->operasional())
            ->get(route('operasional.rekap.index', ['bulan' => '2025-01']))
            ->assertOk()
            ->assertSeeText('Tidak ada penempatan pada Januari 2025')
            ->assertDontSee('Unduh CSV');
    }

    public function test_csv_export_contains_the_same_numbers(): void
    {
        $this->createReportData(false);

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.rekap.export', ['bulan' => '2026-11', 'id_klien' => 1]));

        $response->assertOk()->assertDownload('rekap-operasional-2026-11.csv');
        $lines = explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $response->streamedContent())));
        $this->assertSame(
            'NIP;Nama;Klien;"Hari kerja terjadwal";"Libur terjadwal";"Tanggal belum diatur";Hadir;"Terlambat (hari)";"Terlambat (menit)";Izin;Sakit;Alpha;'
            .'"Kerja tanpa catatan absensi";"Jam kerja tercatat";"Jam lembur";"Lembur disetujui (diteruskan ke Keuangan)";"Lembur menunggu persetujuan"',
            $lines[0],
        );
        $this->assertSame('SJI001;Andi;"PT Alpha";5;2;23;4;1;"belum tersedia";0;1;0;1;19;2;"belum tersedia";"belum tersedia"', $lines[1]);
        $this->assertCount(2, $lines);
    }

    public function test_sidebar_and_home_link_to_the_report(): void
    {
        $this->createTables();
        $this->createAttendanceTable(false);

        $this->actingAs($this->operasional())
            ->get(route('operasional.beranda'))
            ->assertOk()
            ->assertSeeInOrder(['Laporan', 'Rekap &amp; Laporan'], false)
            ->assertSee('href="'.route('operasional.rekap.index').'" class="btn-secondary justify-start">Lihat Laporan</a>', false);
    }

    /**
     * Andi (PT Alpha, mulai 01/11): jadwal Kerja 2,3,4,5,20 dan Libur 1,6.
     * Absensi Andi: 2 Hadir 08:00–16:30 (8 jam, lembur 2), 3 Terlambat 08:20–16:00 (7 jam, 20 menit),
     * 4 Sakit, 6 Hadir saat Libur 08:00–12:00 (4 jam), 7 Hadir tanpa jadwal 20:00–04:00 (0 jam).
     * Budi (PT Beta, 10–12/11): tanpa jadwal; Alpha 11/11; kehadiran 05/11 di luar masa penempatan diabaikan.
     */
    private function createReportData(bool $withRecordedColumns): void
    {
        $this->createTables();
        $this->createAttendanceTable($withRecordedColumns);
        DB::table('tbl_klien')->insert([
            ['id' => 1, 'kode_klien' => 'KL001', 'nama_perusahaan' => 'PT Alpha', 'status' => 'Aktif'],
            ['id' => 2, 'kode_klien' => 'KL002', 'nama_perusahaan' => 'PT Beta', 'status' => 'Aktif'],
        ]);
        $this->createPlacement(101, $this->createEmployee('SJI001', 'Andi'), 1, '2026-11-01');
        $this->createPlacement(102, $this->createEmployee('SJI002', 'Budi'), 2, '2026-11-10', '2026-11-12');

        foreach ([2, 3, 4, 5, 20] as $day) {
            $this->createSchedule(101, sprintf('2026-11-%02d', $day), 'Kerja', '08:00', '16:00');
        }
        foreach ([1, 6] as $day) {
            $this->createSchedule(101, sprintf('2026-11-%02d', $day), 'Libur');
        }

        $this->attend(1, '2026-11-02', 'Hadir', '08:00:00', '16:30:00', 2, 0, 'Disetujui', $withRecordedColumns);
        $this->attend(1, '2026-11-03', 'Terlambat', '08:20:00', '16:00:00', 0, 20, 'Tidak Ada', $withRecordedColumns);
        $this->attend(1, '2026-11-04', 'Sakit', null, null, 0, 0, 'Tidak Ada', $withRecordedColumns);
        $this->attend(1, '2026-11-06', 'Hadir', '08:00:00', '12:00:00', 0, 0, 'Tidak Ada', $withRecordedColumns);
        $this->attend(1, '2026-11-07', 'Hadir', '20:00:00', '04:00:00', 0, 0, 'Tidak Ada', $withRecordedColumns);
        $this->attend(2, '2026-11-05', 'Hadir', '08:00:00', '16:00:00', 0, 0, 'Tidak Ada', $withRecordedColumns);
        $this->attend(2, '2026-11-11', 'Alpha', null, null, 0, 0, 'Tidak Ada', $withRecordedColumns);
    }

    private function createAttendanceTable(bool $withRecordedColumns): void
    {
        Schema::create('tbl_absensi', function (Blueprint $table) use ($withRecordedColumns): void {
            $table->increments('id');
            $table->integer('id_karyawan');
            $table->date('tgl');
            $table->time('jam_masuk')->nullable();
            $table->time('jam_keluar')->nullable();
            $table->string('status_kehadiran');
            $table->integer('jam_lembur')->default(0);
            if ($withRecordedColumns) {
                $table->unsignedSmallInteger('menit_terlambat')->default(0);
                $table->string('status_lembur')->default('Tidak Ada');
            }
        });
    }

    private function attend(int $employeeId, string $date, string $status, ?string $in, ?string $out, int $overtime, int $minutesLate, string $overtimeStatus, bool $withRecordedColumns): void
    {
        DB::table('tbl_absensi')->insert([
            'id_karyawan' => $employeeId,
            'tgl' => $date,
            'jam_masuk' => $in,
            'jam_keluar' => $out,
            'status_kehadiran' => $status,
            'jam_lembur' => $overtime,
            ...($withRecordedColumns ? ['menit_terlambat' => $minutesLate, 'status_lembur' => $overtimeStatus] : []),
        ]);
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}

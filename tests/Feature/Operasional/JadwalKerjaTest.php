<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class JadwalKerjaTest extends TestCase
{
    use CreatesSjiAuthTables;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('operasional.jadwal-kerja'))
            ->assertRedirect(route('login'));
    }

    public function test_non_operasional_role_cannot_open_the_calendar(): void
    {
        $hrd = User::factory()->withRole(Role::Hrd)->create();

        $this->actingAs($hrd)
            ->get(route('operasional.jadwal-kerja'))
            ->assertForbidden();
    }

    public function test_operasional_can_save_an_overnight_work_schedule_and_history(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-01-01');
        $templateId = $this->createShiftTemplate($clientId, 'Malam', '20:00', '04:00');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->actingAs($operasional)->post(route('operasional.jadwal-kerja.store'), [
            'id_penempatan' => 101,
            'tanggal_kerja' => '2026-11-18',
            'tampilan' => 'bulan',
            'status' => 'Kerja',
            'id_template_shift' => $templateId,
            'jam_masuk' => '20:00',
            'jam_pulang' => '04:00',
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'id_penempatan' => 101,
            'tanggal_kerja' => '2026-11-18',
            'status' => 'Kerja',
            'id_template_shift' => $templateId,
            'jam_masuk' => '20:00:00',
            'jam_pulang' => '04:00:00',
            'durasi_menit' => 480,
        ]);
        $this->assertDatabaseHas('tbl_jadwal_kerja_riwayat', [
            'aksi' => 'Dibuat',
            'diubah_oleh' => $operasional->id,
        ]);
    }

    public function test_schedule_is_rejected_outside_the_placement_period(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-11-01', '2026-11-17');
        $templateId = $this->createShiftTemplate($clientId, 'Pagi', '08:00', '16:00');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->from(route('operasional.jadwal-kerja', ['tanggal' => '2026-11-18']))
            ->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.store'), [
                'id_penempatan' => 101,
                'tanggal_kerja' => '2026-11-18',
                'tampilan' => 'bulan',
                'status' => 'Kerja',
                'id_template_shift' => $templateId,
                'jam_masuk' => '08:00',
                'jam_pulang' => '16:00',
            ]);

        $response->assertSessionHasErrors([
            'tanggal_kerja' => 'Tanggal jadwal harus berada dalam masa penempatan.',
        ]);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_schedule_cannot_use_another_clients_shift_template(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Penempatan', 'Aktif');
        $otherClientId = $this->createClient('KL002', 'Klien Lain', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-11-01');
        $templateId = $this->createShiftTemplate($otherClientId, 'Shift Klien Lain', '08:00', '16:00');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->from(route('operasional.jadwal-kerja', ['tanggal' => '2026-11-18']))
            ->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.store'), [
                'id_penempatan' => 101,
                'tanggal_kerja' => '2026-11-18',
                'tampilan' => 'bulan',
                'status' => 'Kerja',
                'id_template_shift' => $templateId,
                'jam_masuk' => '08:00',
                'jam_pulang' => '16:00',
            ]);

        $response->assertSessionHasErrors([
            'id_template_shift' => 'Jam tersimpan tidak sesuai dengan klien pada penempatan.',
        ]);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_updating_a_schedule_retains_the_previous_value_in_history(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-11-01');
        $templateId = $this->createShiftTemplate($clientId, 'Pagi', '08:00', '16:00');
        $scheduleId = $this->createSchedule(101, '2026-11-18', $templateId, '08:00', '16:00', 'Pagi');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->actingAs($operasional)->put(
            route('operasional.jadwal-kerja.update', $scheduleId),
            [
                'status' => 'Libur',
                'tampilan' => 'bulan',
            ],
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'id' => $scheduleId,
            'status' => 'Libur',
            'id_template_shift' => null,
        ]);
        $this->assertDatabaseHas('tbl_jadwal_kerja_riwayat', [
            'id_jadwal_kerja' => $scheduleId,
            'aksi' => 'Diubah',
            'diubah_oleh' => $operasional->id,
        ]);

        $history = DB::table('tbl_jadwal_kerja_riwayat')
            ->where('id_jadwal_kerja', $scheduleId)
            ->where('aksi', 'Diubah')
            ->first();
        $this->assertStringContainsString('"nama_shift_snapshot":"Pagi"', (string) $history->data_sebelum);
    }

    public function test_monthly_copy_preserves_overridden_hours_and_records_history(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-01-01');
        $templateId = $this->createShiftTemplate($clientId, 'Malam', '20:00', '04:00');
        $sourceId = $this->createSchedule(101, '2026-01-05', $templateId, '20:30', '04:30', 'Malam');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $preview = $this->actingAs($operasional)->get(route('operasional.jadwal-kerja.copy.preview', [
            'id_penempatan' => 101,
            'bulan_tujuan' => '2026-02',
        ]));
        $preview->assertOk()
            ->assertSeeText('Januari 2026')
            ->assertSeeText('Februari 2026')
            ->assertSeeText('Siap disalin');

        $response = $this->post(route('operasional.jadwal-kerja.copy'), [
            'id_penempatan' => 101,
            'bulan_tujuan' => '2026-02',
            'item' => [
                [
                    'tanggal_sumber' => '2026-01-05',
                    'status' => 'Kerja',
                    'id_template_shift' => $templateId,
                    'jam_masuk' => '20:30',
                    'jam_pulang' => '04:30',
                ],
            ],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'id_penempatan' => 101,
            'tanggal_kerja' => '2026-02-05',
            'status' => 'Kerja',
            'id_template_shift' => $templateId,
            'nama_shift_snapshot' => 'Malam',
            'jam_masuk' => '20:30:00',
            'jam_pulang' => '04:30:00',
            'durasi_menit' => 480,
        ]);
        $this->assertDatabaseHas('tbl_jadwal_kerja_riwayat', [
            'id_jadwal_kerja' => DB::table('tbl_jadwal_kerja')->where('tanggal_kerja', '2026-02-05')->value('id'),
            'aksi' => 'Disalin',
        ]);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $sourceId, 'tanggal_kerja' => '2026-01-05']);
    }

    public function test_monthly_copy_preview_skips_january_thirty_first_for_february(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2024-01-01');
        $templateId = $this->createShiftTemplate($clientId, 'Pagi', '08:00', '16:00');
        $this->createSchedule(101, '2024-01-31', $templateId, '08:00', '16:00', 'Pagi');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)->get(route('operasional.jadwal-kerja.copy.preview', [
            'id_penempatan' => 101,
            'bulan_tujuan' => '2024-02',
        ]))->assertOk()
            ->assertSeeText('Tanggal tersebut tidak tersedia pada bulan tujuan.');
    }

    public function test_schedule_is_rejected_for_a_placement_that_is_no_longer_active(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Rotasi');
        $this->createPlacement(101, $employeeId, $clientId, '2026-11-01', '2026-11-30');
        $templateId = $this->createShiftTemplate($clientId, 'Pagi', '08:00', '16:00');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.store'), [
                'id_penempatan' => 101,
                'tanggal_kerja' => '2026-11-18',
                'tampilan' => 'bulan',
                'status' => 'Kerja',
                'id_template_shift' => $templateId,
                'jam_masuk' => '08:00',
                'jam_pulang' => '16:00',
            ]);

        $response->assertSessionHasErrors([
            'id_penempatan' => 'Jadwal baru hanya dapat dibuat untuk penempatan berstatus Aktif.',
        ]);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_monthly_copy_from_december_into_january_of_the_next_year(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-12-01');
        $templateId = $this->createShiftTemplate($clientId, 'Malam', '20:00', '04:00');
        $this->createSchedule(101, '2026-12-31', $templateId, '20:00', '04:00', 'Malam');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.copy'), [
                'id_penempatan' => 101,
                'bulan_tujuan' => '2027-01',
                'item' => [[
                    'tanggal_sumber' => '2026-12-31',
                    'status' => 'Kerja',
                    'id_template_shift' => $templateId,
                    'jam_masuk' => '20:00',
                    'jam_pulang' => '04:00',
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'id_penempatan' => 101,
            'tanggal_kerja' => '2027-01-31',
            'status' => 'Kerja',
            'jam_masuk' => '20:00:00',
            'jam_pulang' => '04:00:00',
            'durasi_menit' => 480,
        ]);
    }

    public function test_calendar_asks_to_choose_an_employee_first(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-11-01');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)
            ->get(route('operasional.jadwal-kerja', ['bulan' => '2026-11']))
            ->assertOk()
            ->assertSeeText('Belum ada karyawan dipilih.')
            ->assertDontSeeText('Langkah:')
            ->assertSeeText('SJI001')
            ->assertDontSee('data-tanggal=', false);
    }

    public function test_calendar_shows_work_hours_holiday_and_unconfigured_dates_for_one_employee(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $otherEmployeeId = $this->createEmployee('SJI002', 'Karyawan Lain');
        $this->createPlacement(101, $employeeId, $clientId, '2026-11-01');
        $this->createPlacement(102, $otherEmployeeId, $clientId, '2026-11-01');
        $templateId = $this->createShiftTemplate($clientId, 'Malam', '20:00', '04:00');
        $this->createSchedule(101, '2026-11-18', $templateId, '20:00', '04:00', 'Malam');
        $this->createSchedule(101, '2026-11-19', null, null, null, null, 'Libur');
        $this->createSchedule(102, '2026-11-20', null, '07:00', '15:00', null);
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->actingAs($operasional)->get(route('operasional.jadwal-kerja', [
            'id_karyawan' => $employeeId,
            'bulan' => '2026-11',
        ]));

        $response->assertOk()
            ->assertSee('data-tanggal="2026-11-18"', false)
            ->assertSee('aria-label="18/11/2026 Kerja"', false)
            ->assertSee('aria-label="19/11/2026 Libur"', false)
            ->assertSee('aria-label="20/11/2026 Belum Diatur"', false)
            ->assertSeeText('20:00')
            ->assertSeeText('(+1 hari)')
            ->assertDontSeeText('07:00');
    }

    public function test_unconfigured_date_is_not_treated_as_holiday(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-11-01');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-04'], 'jadikan_kerja', [
                'jam_masuk' => '08:00',
                'jam_pulang' => '16:00',
            ]))
            ->assertSessionHasErrors(['tanggal' => 'Tidak ada tanggal berstatus Libur di antara tanggal yang dipilih.']);

        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
        $this->get(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2026-11']))
            ->assertSee('aria-label="04/11/2026 Belum Diatur"', false);
    }

    public function test_panel_saves_a_single_date(): void
    {
        [$employeeId, $operasional] = $this->employeeWithActivePlacement();

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-10'], 'kerja', [
                'jam_masuk' => '08:00',
                'jam_pulang' => '16:00',
            ]))
            ->assertRedirect(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2026-11']))
            ->assertSessionHas('success', 'Jam kerja diterapkan ke 1 tanggal.');

        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'id_penempatan' => 101,
            'tanggal_kerja' => '2026-11-10',
            'status' => 'Kerja',
            'id_template_shift' => null,
            'nama_shift_snapshot' => null,
            'jam_masuk' => '08:00:00',
            'jam_pulang' => '16:00:00',
            'durasi_menit' => 480,
        ]);
        $this->assertDatabaseHas('tbl_jadwal_kerja_riwayat', ['aksi' => 'Dibuat', 'diubah_oleh' => $operasional->id]);
    }

    public function test_panel_applies_hours_only_to_the_selected_non_consecutive_dates(): void
    {
        [$employeeId, $operasional] = $this->employeeWithActivePlacement();
        $this->createSchedule(101, '2026-11-02', null, '07:00', '15:00', null);

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload(
                $employeeId,
                ['2026-11-01', '2026-11-03', '2026-11-05', '2026-11-08'],
                'kerja',
                ['jam_masuk' => '08:00', 'jam_pulang' => '16:00'],
            ))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Jam kerja diterapkan ke 4 tanggal.');

        $this->assertSame(
            ['2026-11-01', '2026-11-03', '2026-11-05', '2026-11-08'],
            DB::table('tbl_jadwal_kerja')->where('jam_masuk', '08:00:00')->orderBy('tanggal_kerja')->pluck('tanggal_kerja')->all(),
        );
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-11-02', 'jam_masuk' => '07:00:00']);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 5);
    }

    public function test_template_fills_hours_and_manual_changes_are_kept(): void
    {
        [$employeeId, $operasional, $clientId] = $this->employeeWithActivePlacement();
        $templateId = $this->createShiftTemplate($clientId, 'Shift Pagi', '08:00', '16:00');

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-10'], 'kerja', [
                'id_template_shift' => $templateId,
            ]))
            ->assertSessionHasNoErrors();
        $this->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-11'], 'kerja', [
            'id_template_shift' => $templateId,
            'jam_masuk' => '09:00',
            'jam_pulang' => '17:30',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'tanggal_kerja' => '2026-11-10',
            'id_template_shift' => $templateId,
            'nama_shift_snapshot' => 'Shift Pagi',
            'jam_masuk' => '08:00:00',
            'jam_pulang' => '16:00:00',
        ]);
        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'tanggal_kerja' => '2026-11-11',
            'id_template_shift' => $templateId,
            'jam_masuk' => '09:00:00',
            'jam_pulang' => '17:30:00',
            'durasi_menit' => 510,
        ]);
    }

    public function test_panel_saves_overnight_shifts_with_next_day_duration(): void
    {
        [$employeeId, $operasional] = $this->employeeWithActivePlacement();

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-12'], 'kerja', [
                'jam_masuk' => '20:00',
                'jam_pulang' => '04:00',
            ]))
            ->assertSessionHasNoErrors();
        $this->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-13'], 'kerja', [
            'jam_masuk' => '20:00',
            'jam_pulang' => '08:00',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-11-12', 'jam_pulang' => '04:00:00', 'durasi_menit' => 480]);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-11-13', 'jam_pulang' => '08:00:00', 'durasi_menit' => 720]);
    }

    public function test_panel_rejects_equal_hours_and_missing_hours(): void
    {
        [$employeeId, $operasional] = $this->employeeWithActivePlacement();

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-12'], 'kerja', [
                'jam_masuk' => '08:00',
                'jam_pulang' => '08:00',
            ]))
            ->assertSessionHasErrors(['jam_pulang' => 'Jam pulang tidak boleh sama dengan jam masuk.']);
        $this->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-12'], 'kerja'))
            ->assertSessionHasErrors(['jam_masuk' => 'Pilih jam tersimpan atau isi jam masuk dan jam pulang terlebih dahulu.']);

        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_panel_rejects_an_inactive_template_for_new_schedules(): void
    {
        [$employeeId, $operasional, $clientId] = $this->employeeWithActivePlacement();
        $templateId = $this->createShiftTemplate($clientId, 'Shift Lama', '08:00', '16:00');
        DB::table('tbl_template_shift')->where('id', $templateId)->update(['status' => 'Nonaktif']);

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-12'], 'kerja', [
                'id_template_shift' => $templateId,
            ]))
            ->assertSessionHasErrors(['id_template_shift' => 'Jam tersimpan yang dipilih sudah nonaktif.']);

        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_panel_marks_several_dates_as_holiday_without_hours(): void
    {
        [$employeeId, $operasional, $clientId] = $this->employeeWithActivePlacement();
        $templateId = $this->createShiftTemplate($clientId, 'Shift Pagi', '08:00', '16:00');
        $scheduleId = $this->createSchedule(101, '2026-11-14', $templateId, '08:00', '16:00', 'Shift Pagi');

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-14', '2026-11-15'], 'libur', [
                'jam_masuk' => '08:00',
                'jam_pulang' => '16:00',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '2 tanggal ditandai Libur.');

        foreach (['2026-11-14', '2026-11-15'] as $date) {
            $this->assertDatabaseHas('tbl_jadwal_kerja', [
                'tanggal_kerja' => $date,
                'status' => 'Libur',
                'id_template_shift' => null,
                'jam_masuk' => null,
                'jam_pulang' => null,
                'durasi_menit' => null,
            ]);
        }
        $this->assertDatabaseHas('tbl_jadwal_kerja_riwayat', ['id_jadwal_kerja' => $scheduleId, 'aksi' => 'Diubah']);
    }

    public function test_holiday_becomes_a_work_day_only_after_hours_are_provided(): void
    {
        [$employeeId, $operasional] = $this->employeeWithActivePlacement();
        $this->createSchedule(101, '2026-11-21', null, null, null, null, 'Libur');
        $this->createSchedule(101, '2026-11-23', null, '07:00', '15:00', null);

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-21'], 'jadikan_kerja'))
            ->assertSessionHasErrors('jam_masuk');
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-11-21', 'status' => 'Libur']);

        $this->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload(
            $employeeId,
            ['2026-11-21', '2026-11-22', '2026-11-23'],
            'jadikan_kerja',
            ['jam_masuk' => '20:00', 'jam_pulang' => '08:00'],
        ))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'tanggal_kerja' => '2026-11-21',
            'status' => 'Kerja',
            'jam_masuk' => '20:00:00',
            'jam_pulang' => '08:00:00',
            'durasi_menit' => 720,
        ]);
        $this->assertDatabaseMissing('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-11-22']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-11-23', 'jam_masuk' => '07:00:00']);
    }

    public function test_cancel_selection_is_client_side_only_and_keeps_saved_schedules(): void
    {
        [$employeeId, $operasional] = $this->employeeWithActivePlacement();
        $this->createSchedule(101, '2026-11-10', null, '08:00', '16:00', null);

        $this->actingAs($operasional)
            ->get(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2026-11']))
            ->assertOk()
            ->assertSee('<button type="button" id="tombol-batal"', false)
            ->assertSeeText('Batalkan Pilihan');

        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-11-10', 'status' => 'Kerja']);
    }

    public function test_panel_rejects_all_dates_when_one_is_outside_the_placement(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-11-10', '2026-11-20', 'Aktif');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-12', '2026-11-25'], 'kerja', [
                'jam_masuk' => '08:00',
                'jam_pulang' => '16:00',
            ]))
            ->assertSessionHasErrors(['tanggal' => 'Tanggal berikut berada di luar masa penempatan aktif karyawan: 25/11/2026.']);

        $this->assertDatabaseCount('tbl_jadwal_kerja', 0);
    }

    public function test_rotation_keeps_old_placement_schedules_and_new_dates_use_the_new_placement(): void
    {
        $this->createOperationalCalendarTables();
        $oldClientId = $this->createClient('KL001', 'Klien Lama', 'Aktif');
        $newClientId = $this->createClient('KL002', 'Klien Baru', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Rotasi');
        $this->createPlacement(101, $employeeId, $oldClientId, '2026-10-01', '2026-11-15', 'Rotasi');
        $this->createPlacement(102, $employeeId, $newClientId, '2026-11-16');
        $oldScheduleId = $this->createSchedule(101, '2026-11-10', null, '08:00', '16:00', null);
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)
            ->get(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2026-11']))
            ->assertOk()
            ->assertDontSee('data-tanggal="2026-11-10"', false)
            ->assertSee('data-tanggal="2026-11-16"', false)
            ->assertSeeText('Penempatan Rotasi (hanya lihat)')
            ->assertSeeText('08:00');

        $this->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-10'], 'libur'))
            ->assertSessionHasErrors('tanggal');
        $this->post(route('operasional.jadwal-kerja.atur'), $this->panelPayload($employeeId, ['2026-11-20'], 'kerja', [
            'jam_masuk' => '20:00',
            'jam_pulang' => '04:00',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'id' => $oldScheduleId,
            'id_penempatan' => 101,
            'status' => 'Kerja',
            'jam_masuk' => '08:00:00',
        ]);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id_penempatan' => 102, 'tanggal_kerja' => '2026-11-20']);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 2);
    }

    public function test_monthly_copy_requires_a_conflict_choice_and_can_skip_conflicts(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2024-01-01');
        $templateId = $this->createShiftTemplate($clientId, 'Pagi', '08:00', '16:00');
        $this->createSchedule(101, '2024-02-28', $templateId, '08:00', '16:00', 'Pagi');
        $this->createSchedule(101, '2024-02-29', $templateId, '08:00', '16:00', 'Pagi');
        $this->createSchedule(101, '2024-03-29', null, null, null, null, 'Libur');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)->get(route('operasional.jadwal-kerja.copy.preview', [
            'id_penempatan' => 101,
            'bulan_tujuan' => '2024-03',
            'bulan_sumber' => '2024-02',
        ]))->assertOk()
            ->assertSeeText('Konflik: jadwal sudah ada')
            ->assertSeeText('29/03/2024')
            ->assertSee('name="mode_konflik"', false);

        $items = [
            ['tanggal_sumber' => '2024-02-28', 'status' => 'Kerja', 'id_template_shift' => $templateId, 'jam_masuk' => '08:00', 'jam_pulang' => '16:00'],
            ['tanggal_sumber' => '2024-02-29', 'status' => 'Kerja', 'id_template_shift' => $templateId, 'jam_masuk' => '08:00', 'jam_pulang' => '16:00'],
        ];

        $this->post(route('operasional.jadwal-kerja.copy'), [
            'id_penempatan' => 101,
            'bulan_tujuan' => '2024-03',
            'bulan_sumber' => '2024-02',
            'item' => $items,
        ])->assertSessionHasErrors('mode_konflik');
        $this->assertDatabaseCount('tbl_jadwal_kerja', 3);

        $this->post(route('operasional.jadwal-kerja.copy'), [
            'id_penempatan' => 101,
            'bulan_tujuan' => '2024-03',
            'bulan_sumber' => '2024-02',
            'mode_konflik' => 'lewati',
            'item' => $items,
        ])->assertRedirect(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2024-03']))
            ->assertSessionHas('success', 'Salinan selesai: 1 jadwal ditambahkan, 0 jadwal diganti, dan 1 tanggal berkonflik dilewati.');

        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2024-03-29', 'status' => 'Libur']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2024-03-28', 'status' => 'Kerja']);
        $this->assertDatabaseCount('tbl_jadwal_kerja', 4);
    }

    public function test_monthly_copy_can_replace_conflicts_from_a_chosen_source_month(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-01-01');
        $this->createSchedule(101, '2026-09-03', null, null, null, null, 'Libur');
        $this->createSchedule(101, '2026-09-04', null, '20:00', '08:00', null);
        $targetId = $this->createSchedule(101, '2026-11-04', null, '08:00', '16:00', null);
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)->post(route('operasional.jadwal-kerja.copy'), [
            'id_penempatan' => 101,
            'bulan_tujuan' => '2026-11',
            'bulan_sumber' => '2026-09',
            'mode_konflik' => 'ganti',
            'item' => [
                ['tanggal_sumber' => '2026-09-03', 'status' => 'Libur'],
                ['tanggal_sumber' => '2026-09-04', 'status' => 'Kerja', 'jam_masuk' => '20:00', 'jam_pulang' => '08:00'],
            ],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Salinan selesai: 1 jadwal ditambahkan, 1 jadwal diganti, dan 0 tanggal berkonflik dilewati.');

        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-11-03', 'status' => 'Libur', 'jam_masuk' => null]);
        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'id' => $targetId,
            'tanggal_kerja' => '2026-11-04',
            'jam_masuk' => '20:00:00',
            'jam_pulang' => '08:00:00',
            'durasi_menit' => 720,
        ]);
        $this->assertDatabaseHas('tbl_jadwal_kerja_riwayat', ['id_jadwal_kerja' => $targetId, 'aksi' => 'Disalin']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2026-09-04', 'jam_masuk' => '20:00:00']);
    }

    public function test_monthly_copy_handles_leap_february(): void
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2024-01-01');
        $this->createSchedule(101, '2024-01-29', null, '08:00', '16:00', null);
        $this->createSchedule(101, '2024-01-30', null, '08:00', '16:00', null);
        $this->createSchedule(101, '2024-01-31', null, null, null, null, 'Libur');
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)->post(route('operasional.jadwal-kerja.copy'), [
            'id_penempatan' => 101,
            'bulan_tujuan' => '2024-02',
            'bulan_sumber' => '2024-01',
            'item' => [
                ['tanggal_sumber' => '2024-01-29', 'status' => 'Kerja', 'jam_masuk' => '08:00', 'jam_pulang' => '16:00'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_jadwal_kerja', ['tanggal_kerja' => '2024-02-29', 'status' => 'Kerja']);
        $this->assertSame(1, DB::table('tbl_jadwal_kerja')->where('tanggal_kerja', 'like', '2024-02-%')->count());
    }

    public function test_monthly_copy_rejects_the_same_source_and_destination_month(): void
    {
        [, $operasional] = $this->employeeWithActivePlacement();

        $this->actingAs($operasional)
            ->get(route('operasional.jadwal-kerja.copy.preview', [
                'id_penempatan' => 101,
                'bulan_tujuan' => '2026-11',
                'bulan_sumber' => '2026-11',
            ]))
            ->assertSessionHasErrors(['bulan_sumber' => 'Bulan sumber dan bulan tujuan tidak boleh sama.']);
    }

    /**
     * @return array{0: int, 1: User, 2: int}
     */
    private function employeeWithActivePlacement(): array
    {
        $this->createOperationalCalendarTables();
        $clientId = $this->createClient('KL001', 'Klien Aktif', 'Aktif');
        $employeeId = $this->createEmployee('SJI001', 'Karyawan Aktif');
        $this->createPlacement(101, $employeeId, $clientId, '2026-01-01');

        return [$employeeId, User::factory()->withRole(Role::Operasional)->create(), $clientId];
    }

    /**
     * @param  list<string>  $dates
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function panelPayload(int $employeeId, array $dates, string $action, array $extra = []): array
    {
        return [
            'id_karyawan' => $employeeId,
            'bulan' => substr($dates[0], 0, 7),
            'tanggal' => $dates,
            'aksi' => $action,
            ...$extra,
        ];
    }

    private function createOperationalCalendarTables(): void
    {
        Schema::create('tbl_klien', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_klien');
            $table->string('nama_perusahaan');
            $table->string('status');
        });

        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip');
            $table->string('nama');
        });

        Schema::create('tbl_penempatan', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->unsignedInteger('id_klien');
            $table->date('tgl_mulai');
            $table->date('tgl_selesai')->nullable();
            $table->string('status');
        });

        Schema::create('tbl_template_shift', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_klien');
            $table->string('nama_shift');
            $table->time('jam_masuk');
            $table->time('jam_pulang');
            $table->unsignedSmallInteger('durasi_menit');
            $table->string('status');
            $table->timestamps();
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
    }

    private function createPlacement(
        int $id,
        int $employeeId,
        int $clientId,
        string $startDate,
        ?string $endDate = null,
        ?string $status = null,
    ): void {
        DB::table('tbl_penempatan')->insert([
            'id' => $id,
            'id_karyawan' => $employeeId,
            'id_klien' => $clientId,
            'tgl_mulai' => $startDate,
            'tgl_selesai' => $endDate,
            'status' => $status ?? ($endDate === null ? 'Aktif' : 'Selesai'),
        ]);
    }

    private function createShiftTemplate(int $clientId, string $name, string $start, string $end): int
    {
        return (int) DB::table('tbl_template_shift')->insertGetId([
            'id_klien' => $clientId,
            'nama_shift' => $name,
            'jam_masuk' => $start,
            'jam_pulang' => $end,
            'durasi_menit' => $this->durationMinutes($start, $end),
            'status' => 'Aktif',
        ]);
    }

    private function createSchedule(
        int $placementId,
        string $date,
        ?int $templateId,
        ?string $start,
        ?string $end,
        ?string $shiftName,
        string $status = 'Kerja',
    ): int {
        $duration = $status === 'Libur' ? null : $this->durationMinutes((string) $start, (string) $end);

        return (int) DB::table('tbl_jadwal_kerja')->insertGetId([
            'id_penempatan' => $placementId,
            'tanggal_kerja' => $date,
            'status' => $status,
            'id_template_shift' => $templateId,
            'nama_shift_snapshot' => $shiftName,
            'jam_masuk' => $start === null ? null : $start.':00',
            'jam_pulang' => $end === null ? null : $end.':00',
            'durasi_menit' => $duration,
        ]);
    }

    private function createClient(string $code, string $name, string $status): int
    {
        return (int) DB::table('tbl_klien')->insertGetId([
            'kode_klien' => $code,
            'nama_perusahaan' => $name,
            'status' => $status,
        ]);
    }

    private function durationMinutes(string $start, string $end): int
    {
        $toMinutes = static fn (string $time): int => ((int) substr($time, 0, 2) * 60) + (int) substr($time, 3, 2);

        return ($toMinutes($end) - $toMinutes($start) + 1440) % 1440;
    }

    private function createEmployee(string $nip, string $name): int
    {
        return (int) DB::table('tbl_karyawan')->insertGetId([
            'nip' => $nip,
            'nama' => $name,
        ]);
    }
}

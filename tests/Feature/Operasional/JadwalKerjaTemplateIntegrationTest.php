<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\Concerns\CreatesTemplateJadwalTables;
use Tests\TestCase;

/**
 * Integrasi Template Jadwal Bulanan dengan kalender Jadwal Kerja dan halaman Preset Jam.
 */
class JadwalKerjaTemplateIntegrationTest extends TestCase
{
    use CreatesSjiAuthTables;
    use CreatesTemplateJadwalTables;

    public function test_calendar_links_to_monthly_templates_and_hour_presets(): void
    {
        $employeeId = $this->employeeWithSchedule();

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2026-11']));

        $response->assertOk()
            ->assertSee('href="'.route('operasional.jadwal-kerja.template-jadwal.index').'" class="btn-secondary">Pola Jadwal Tersimpan</a>', false)
            ->assertSee('href="'.route('operasional.jadwal-kerja.template-shift.index').'" class="text-sji-biru hover:underline">Kelola jam tersimpan</a>', false)
            ->assertSee('<details id="lainnya"', false)
            ->assertSeeTextInOrder(['Lainnya: Salin Jadwal Bulanan & Simpan Pola Jadwal', 'Salin Jadwal Bulanan', 'Simpan Pola Jadwal'])
            ->assertSee('id="simpan-template"', false)
            ->assertSee('id="salin-jadwal"', false)
            ->assertSeeText('Gunakan Jam Tersimpan (opsional)')
            ->assertSeeTextInOrder(['Atur Jam Kerja', 'Tandai Hari Libur', 'Jadikan Hari Kerja', 'Batalkan Pilihan Tanggal'])
            ->assertDontSeeText('Template Shift')
            ->assertDontSeeText('Preset');
    }

    public function test_client_filter_narrows_the_employee_list_and_is_kept_in_month_navigation(): void
    {
        $andiId = $this->employeeWithSchedule();
        $budiId = $this->createEmployee('SJI002', 'Budi');
        $this->createPlacement(102, $budiId, $this->createClient('KL002'), '2026-01-01');

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja', ['id_klien' => 2, 'bulan' => '2026-11']));

        $response->assertOk()
            ->assertSee('<option value="2" selected>Klien KL002</option>', false)
            ->assertSeeText('SJI002 — Budi')
            ->assertDontSeeText('SJI001 — Andi');

        $this->get(route('operasional.jadwal-kerja', ['id_klien' => 2, 'id_karyawan' => $andiId, 'bulan' => '2026-11']))
            ->assertOk()
            ->assertSeeText('SJI001 — Andi')
            ->assertSee('id="panel-tanggal"', false)
            ->assertSee('href="'.e(route('operasional.jadwal-kerja', ['id_klien' => 2, 'id_karyawan' => $andiId, 'bulan' => '2026-12'])).'"', false);
    }

    public function test_pattern_and_copy_pages_use_plain_conflict_labels(): void
    {
        $employeeId = $this->employeeWithSchedule();
        $this->createSchedule(101, '2026-12-03', 'Libur');
        DB::table('tbl_template_jadwal')->insert(['id' => 1, 'nama_template' => 'Pola Andi', 'jenis' => 'Pribadi', 'id_karyawan' => $employeeId, 'status' => 'Aktif']);
        DB::table('tbl_template_jadwal_detail')->insert(['id_template_jadwal' => 1, 'hari_ke' => 3, 'status' => 'Libur']);
        $this->actingAs($this->operasional());

        $this->get(route('operasional.jadwal-kerja.template-jadwal.apply.preview', [1, 'bulan' => '2026-12']))
            ->assertOk()
            ->assertSeeText('Pertahankan jadwal lama — tanggal yang sudah berjadwal tidak diubah.')
            ->assertSeeText('Ganti jadwal lama — tanggal yang sudah berjadwal diganti dengan pola ini')
            ->assertDontSeeText('Timpa')
            ->assertDontSeeText('Terapkan');
        $this->get(route('operasional.jadwal-kerja.copy.preview', ['id_penempatan' => 101, 'bulan_tujuan' => '2026-12', 'bulan_sumber' => '2026-11']))
            ->assertOk()
            ->assertSeeText('Pertahankan jadwal lama')
            ->assertSeeText('Ganti jadwal lama');
    }

    public function test_calendar_uses_short_labels_without_tutorial_text(): void
    {
        $employeeId = $this->employeeWithSchedule();

        $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2026-11']))
            ->assertOk()
            ->assertSeeTextInOrder(['Pilih Klien', 'Pilih Karyawan', 'Pilih Bulan', 'Tampilkan Jadwal'])
            ->assertSeeTextInOrder(['Salin Jadwal Bulanan', 'Salin jadwal dari bulan lain.', 'Simpan Pola Jadwal', 'Simpan jadwal bulan ini sebagai pola.'])
            ->assertDontSeeText('Mana yang perlu dipakai?')
            ->assertDontSeeText('hanya mengubah tanggal Libur di antara pilihan');
    }

    public function test_save_form_posts_the_selected_employee_and_month(): void
    {
        $employeeId = $this->employeeWithSchedule();

        $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2026-11']))
            ->assertOk()
            ->assertSee('action="'.route('operasional.jadwal-kerja.template-jadwal.store-from-schedule').'"', false)
            ->assertSee('name="id_karyawan" value="'.$employeeId.'"', false)
            ->assertSee('name="bulan" value="2026-11"', false)
            ->assertSee('name="jenis" value="Pribadi"', false)
            ->assertSee('name="jenis" value="Bersama"', false)
            ->assertSee('value="Pola Andi November 2026"', false)
            ->assertSee('<option value="1" selected>Klien KL001</option>', false);
    }

    public function test_save_form_is_replaced_by_a_hint_when_the_month_has_no_schedule(): void
    {
        $employeeId = $this->employeeWithSchedule();

        $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2026-10']))
            ->assertOk()
            ->assertSeeText('Belum ada jadwal pada bulan ini untuk dijadikan pola.')
            ->assertDontSee('id="form-simpan-template"', false);
    }

    public function test_update_option_lists_only_active_templates_for_this_employee(): void
    {
        $employeeId = $this->employeeWithSchedule();
        $otherEmployeeId = $this->createEmployee('SJI002', 'Budi');
        $otherClientId = $this->createClient('KL002');
        DB::table('tbl_template_jadwal')->insert([
            ['nama_template' => 'Pola Milik Andi', 'jenis' => 'Pribadi', 'id_karyawan' => $employeeId, 'id_klien' => null, 'status' => 'Aktif'],
            ['nama_template' => 'Pola Klien Andi', 'jenis' => 'Bersama', 'id_karyawan' => null, 'id_klien' => 1, 'status' => 'Aktif'],
            ['nama_template' => 'Pola Milik Budi', 'jenis' => 'Pribadi', 'id_karyawan' => $otherEmployeeId, 'id_klien' => null, 'status' => 'Aktif'],
            ['nama_template' => 'Pola Klien Lain', 'jenis' => 'Bersama', 'id_karyawan' => null, 'id_klien' => $otherClientId, 'status' => 'Aktif'],
            ['nama_template' => 'Pola Andi Lama', 'jenis' => 'Pribadi', 'id_karyawan' => $employeeId, 'id_klien' => null, 'status' => 'Nonaktif'],
        ]);

        $response = $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2026-11']));

        $response->assertOk()
            ->assertSeeText('Pola Milik Andi (Pribadi)')
            ->assertSeeText('Pola Klien Andi (Bersama)')
            ->assertDontSeeText('Pola Milik Budi')
            ->assertDontSeeText('Pola Klien Lain')
            ->assertDontSeeText('Pola Andi Lama');
    }

    public function test_saving_the_calendar_month_as_a_shared_template_keeps_the_source_schedule(): void
    {
        $employeeId = $this->employeeWithSchedule();
        $scheduleBefore = DB::table('tbl_jadwal_kerja')->orderBy('id')->get()->toArray();

        $this->actingAs($this->operasional())
            ->from(route('operasional.jadwal-kerja', ['id_karyawan' => $employeeId, 'bulan' => '2026-11']))
            ->post(route('operasional.jadwal-kerja.template-jadwal.store-from-schedule'), [
                'id_karyawan' => $employeeId,
                'bulan' => '2026-11',
                'tindakan' => 'buat_baru',
                'nama_template' => 'Pola Security KL001',
                'jenis' => 'Bersama',
                'id_klien' => 1,
            ])
            ->assertRedirect(route('operasional.jadwal-kerja.template-jadwal.show', 1))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_template_jadwal', ['nama_template' => 'Pola Security KL001', 'jenis' => 'Bersama', 'id_klien' => 1, 'id_karyawan' => null]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['hari_ke' => 3, 'status' => 'Kerja', 'jam_masuk' => '20:00:00', 'durasi_menit' => 720]);
        $this->assertDatabaseHas('tbl_template_jadwal_detail', ['hari_ke' => 4, 'status' => 'Libur']);
        $this->assertEquals($scheduleBefore, DB::table('tbl_jadwal_kerja')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('tbl_jadwal_kerja_riwayat', 0);
    }

    public function test_hour_preset_page_keeps_its_functions_under_the_new_label(): void
    {
        $this->createTables();
        $clientId = $this->createClient('KL001');
        DB::table('tbl_template_shift')->insert(['id_klien' => $clientId, 'nama_shift' => 'Shift Pagi', 'jam_masuk' => '08:00', 'jam_pulang' => '16:00', 'durasi_menit' => 480, 'status' => 'Aktif']);

        $response = $this->actingAs($this->operasional())->get(route('operasional.jadwal-kerja.template-shift.index'));

        $response->assertOk()
            ->assertSeeText('Jam Kerja Tersimpan')
            ->assertSee('href="'.route('operasional.jadwal-kerja.template-jadwal.index').'"', false)
            ->assertSee('action="'.route('operasional.jadwal-kerja.template-shift.store').'"', false)
            ->assertSee('action="'.route('operasional.jadwal-kerja.template-shift.standar').'"', false)
            ->assertSee('action="'.route('operasional.jadwal-kerja.template-shift.update', 1).'"', false)
            ->assertSee('action="'.route('operasional.jadwal-kerja.template-shift.deactivate', 1).'"', false)
            ->assertSee('value="Shift Pagi"', false)
            ->assertDontSeeText('Template Shift');
    }

    public function test_template_list_explains_the_difference_with_copy(): void
    {
        $this->createTables();

        $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja.template-jadwal.index'))
            ->assertOk()
            ->assertSeeText('Berbeda dengan Salin Jadwal Bulanan yang menyalin jadwal aktual dari satu bulan sumber.')
            ->assertSee('href="'.route('operasional.jadwal-kerja').'"', false);
    }

    public function test_personal_template_lifecycle_from_calendar_to_applied_schedule(): void
    {
        $andiId = $this->employeeWithSchedule();
        $budiId = $this->createEmployee('SJI002', 'Budi');
        $this->createPlacement(102, $budiId, 1, '2026-01-01');
        $this->actingAs($this->operasional());

        $this->from(route('operasional.jadwal-kerja', ['id_karyawan' => $andiId, 'bulan' => '2026-11']))
            ->post(route('operasional.jadwal-kerja.template-jadwal.store-from-schedule'), [
                'id_karyawan' => $andiId, 'bulan' => '2026-11', 'tindakan' => 'buat_baru', 'nama_template' => 'Pola Andi', 'jenis' => 'Pribadi',
            ])
            ->assertRedirect(route('operasional.jadwal-kerja.template-jadwal.show', 1));
        $this->assertDatabaseHas('tbl_template_jadwal', ['id' => 1, 'jenis' => 'Pribadi', 'id_karyawan' => $andiId, 'id_klien' => null]);

        $this->put(route('operasional.jadwal-kerja.template-jadwal.update', 1), [
            'nama_template' => 'Pola Andi', 'id_karyawan' => $budiId, 'pola' => [3 => ['status' => 'Libur']],
        ])->assertSessionHasErrors('jenis');
        $this->put(route('operasional.jadwal-kerja.template-jadwal.update', 1), [
            'nama_template' => 'Pola Andi Malam',
            'keterangan' => 'Shift malam 12 jam',
            'pola' => [3 => ['status' => 'Kerja', 'jam_masuk' => '20:00', 'jam_pulang' => '08:00'], 4 => ['status' => 'Libur'], 31 => ['status' => 'Libur']],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tbl_template_jadwal', ['id' => 1, 'nama_template' => 'Pola Andi Malam', 'keterangan' => 'Shift malam 12 jam', 'id_karyawan' => $andiId]);

        $this->post(route('operasional.jadwal-kerja.template-jadwal.apply', 1), ['bulan' => '2026-12', 'id_karyawan' => [$budiId], 'mode_konflik' => 'Lewati'])
            ->assertSessionHasErrors(['id_karyawan' => 'Budi: Pola Pribadi hanya dapat dipakai untuk karyawan yang bersangkutan.']);
        $this->post(route('operasional.jadwal-kerja.template-jadwal.apply', 1), ['bulan' => '2026-12', 'mode_konflik' => 'Lewati'])
            ->assertSessionHasNoErrors();
        $appliedDecember = DB::table('tbl_jadwal_kerja')->where('tanggal_kerja', 'like', '2026-12-%')->orderBy('tanggal_kerja')->get(['id_penempatan', 'tanggal_kerja', 'status', 'jam_masuk', 'jam_pulang', 'durasi_menit']);
        $this->assertSame([
            ['id_penempatan' => 101, 'tanggal_kerja' => '2026-12-03', 'status' => 'Kerja', 'jam_masuk' => '20:00:00', 'jam_pulang' => '08:00:00', 'durasi_menit' => 720],
            ['id_penempatan' => 101, 'tanggal_kerja' => '2026-12-04', 'status' => 'Libur', 'jam_masuk' => null, 'jam_pulang' => null, 'durasi_menit' => null],
            ['id_penempatan' => 101, 'tanggal_kerja' => '2026-12-31', 'status' => 'Libur', 'jam_masuk' => null, 'jam_pulang' => null, 'durasi_menit' => null],
        ], $appliedDecember->map(fn (object $row): array => (array) $row)->all());

        $this->put(route('operasional.jadwal-kerja.template-jadwal.update', 1), [
            'nama_template' => 'Pola Andi Pagi', 'pola' => [3 => ['status' => 'Kerja', 'jam_masuk' => '08:00', 'jam_pulang' => '16:00']],
        ])->assertSessionHasNoErrors();
        $this->patch(route('operasional.jadwal-kerja.template-jadwal.deactivate', 1));

        $this->assertEquals($appliedDecember, DB::table('tbl_jadwal_kerja')->where('tanggal_kerja', 'like', '2026-12-%')->orderBy('tanggal_kerja')->get(['id_penempatan', 'tanggal_kerja', 'status', 'jam_masuk', 'jam_pulang', 'durasi_menit']));
        $this->assertSame(2, DB::table('tbl_jadwal_kerja')->where('tanggal_kerja', 'like', '2026-11-%')->count());
        $this->post(route('operasional.jadwal-kerja.template-jadwal.apply', 1), ['bulan' => '2027-01', 'mode_konflik' => 'Lewati'])
            ->assertSessionHasErrors(['id_template_jadwal' => 'Pola yang dinonaktifkan tidak dapat dipakai.']);
        $this->get(route('operasional.jadwal-kerja', ['id_karyawan' => $andiId, 'bulan' => '2026-12']))
            ->assertOk()
            ->assertSee('aria-label="03/12/2026 Kerja"', false)
            ->assertSeeText('20:00–08:00 (+1 hari)');
        $this->get(route('operasional.jadwal-kerja.template-jadwal.show', 1))
            ->assertOk()
            ->assertSeeText('Desember 2026')
            ->assertSeeText('3 tanggal baru, 0 diganti, 0 dilewati');
    }

    /**
     * Karyawan "Andi" dengan penempatan Aktif di klien id 1 dan dua jadwal pada November 2026.
     */
    private function employeeWithSchedule(): int
    {
        $this->createTables();
        $employeeId = $this->createEmployee('SJI001', 'Andi');
        $this->createPlacement(101, $employeeId, $this->createClient('KL001'), '2026-01-01');
        $this->createSchedule(101, '2026-11-03', 'Kerja', '20:00', '08:00');
        $this->createSchedule(101, '2026-11-04', 'Libur');

        return $employeeId;
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}

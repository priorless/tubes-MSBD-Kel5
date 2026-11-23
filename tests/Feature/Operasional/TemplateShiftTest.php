<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class TemplateShiftTest extends TestCase
{
    use CreatesSjiAuthTables;

    public function test_operasional_can_create_an_eight_hour_overnight_shift(): void
    {
        $clientId = $this->createShiftTables();
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->actingAs($operasional)->post(route('operasional.jadwal-kerja.template-shift.store'), [
            'id_klien' => $clientId,
            'nama_shift' => 'Malam',
            'jam_masuk' => '20:00',
            'jam_pulang' => '04:00',
        ]);

        $response->assertRedirect(route('operasional.jadwal-kerja.template-shift.index'));
        $this->assertDatabaseHas('tbl_template_shift', [
            'id_klien' => $clientId,
            'nama_shift' => 'Malam',
            'jam_masuk' => '20:00',
            'jam_pulang' => '04:00',
            'durasi_menit' => 480,
            'status' => 'Aktif',
        ]);
    }

    public function test_operasional_can_create_a_twelve_hour_shift(): void
    {
        $clientId = $this->createShiftTables();
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->actingAs($operasional)->post(route('operasional.jadwal-kerja.template-shift.store'), [
            'id_klien' => $clientId,
            'nama_shift' => 'Dua Belas Jam',
            'jam_masuk' => '20:00',
            'jam_pulang' => '08:00',
        ]);

        $response->assertRedirect(route('operasional.jadwal-kerja.template-shift.index'));
        $this->assertDatabaseHas('tbl_template_shift', [
            'id_klien' => $clientId,
            'nama_shift' => 'Dua Belas Jam',
            'durasi_menit' => 720,
        ]);
    }

    public function test_operasional_cannot_create_a_shift_with_equal_start_and_end(): void
    {
        $clientId = $this->createShiftTables();
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->from(route('operasional.jadwal-kerja.template-shift.index'))
            ->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.template-shift.store'), [
                'id_klien' => $clientId,
                'nama_shift' => 'Salah',
                'jam_masuk' => '08:00',
                'jam_pulang' => '08:00',
            ]);

        $response->assertRedirect(route('operasional.jadwal-kerja.template-shift.index'))
            ->assertSessionHasErrors([
                'jam_pulang' => 'Jam pulang tidak boleh sama dengan jam masuk.',
            ]);
        $this->assertDatabaseCount('tbl_template_shift', 0);
    }

    public function test_operasional_can_create_the_standard_templates_once(): void
    {
        $clientId = $this->createShiftTables();
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)
            ->post(route('operasional.jadwal-kerja.template-shift.standar'), ['id_klien' => $clientId])
            ->assertRedirect(route('operasional.jadwal-kerja.template-shift.index'))
            ->assertSessionHas('success', '4 jam standar berhasil ditambahkan.');
        $this->post(route('operasional.jadwal-kerja.template-shift.standar'), ['id_klien' => $clientId])
            ->assertSessionHas('success', 'Semua jam standar sudah tersedia untuk klien ini.');

        $this->assertDatabaseCount('tbl_template_shift', 4);
        $this->assertDatabaseHas('tbl_template_shift', ['nama_shift' => 'Shift Pagi', 'durasi_menit' => 480]);
        $this->assertDatabaseHas('tbl_template_shift', ['nama_shift' => 'Shift Malam', 'jam_pulang' => '04:00', 'durasi_menit' => 480]);
        $this->assertDatabaseHas('tbl_template_shift', ['nama_shift' => 'Shift Siang', 'durasi_menit' => 720]);
        $this->assertDatabaseHas('tbl_template_shift', ['nama_shift' => 'Shift Malam 12 Jam', 'jam_pulang' => '08:00', 'durasi_menit' => 720]);
    }

    public function test_editing_or_deactivating_a_template_keeps_existing_schedules(): void
    {
        $clientId = $this->createShiftTables();
        $templateId = (int) DB::table('tbl_template_shift')->insertGetId([
            'id_klien' => $clientId,
            'nama_shift' => 'Pagi',
            'jam_masuk' => '08:00',
            'jam_pulang' => '16:00',
            'durasi_menit' => 480,
            'status' => 'Aktif',
        ]);
        $scheduleId = (int) DB::table('tbl_jadwal_kerja')->insertGetId([
            'id_penempatan' => 1,
            'tanggal_kerja' => '2026-11-10',
            'status' => 'Kerja',
            'id_template_shift' => $templateId,
            'nama_shift_snapshot' => 'Pagi',
            'jam_masuk' => '08:00',
            'jam_pulang' => '16:00',
            'durasi_menit' => 480,
        ]);
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($operasional)->put(route('operasional.jadwal-kerja.template-shift.update', $templateId), [
            'nama_shift' => 'Pagi Baru',
            'jam_masuk' => '07:00',
            'jam_pulang' => '15:00',
        ])->assertSessionHasNoErrors();
        $this->patch(route('operasional.jadwal-kerja.template-shift.deactivate', $templateId));

        $this->assertDatabaseHas('tbl_jadwal_kerja', [
            'id' => $scheduleId,
            'nama_shift_snapshot' => 'Pagi',
            'jam_masuk' => '08:00',
            'jam_pulang' => '16:00',
        ]);
    }

    public function test_operasional_can_edit_a_template_shift(): void
    {
        $clientId = $this->createShiftTables();
        $templateId = (int) DB::table('tbl_template_shift')->insertGetId([
            'id_klien' => $clientId,
            'nama_shift' => 'Pagi',
            'jam_masuk' => '08:00',
            'jam_pulang' => '16:00',
            'durasi_menit' => 480,
            'status' => 'Aktif',
        ]);
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->actingAs($operasional)->put(
            route('operasional.jadwal-kerja.template-shift.update', $templateId),
            [
                'nama_shift' => 'Pagi Panjang',
                'jam_masuk' => '08:00',
                'jam_pulang' => '20:00',
            ],
        );

        $response->assertRedirect(route('operasional.jadwal-kerja.template-shift.index'));
        $this->assertDatabaseHas('tbl_template_shift', [
            'id' => $templateId,
            'nama_shift' => 'Pagi Panjang',
            'durasi_menit' => 720,
            'status' => 'Aktif',
        ]);
    }

    public function test_operasional_can_deactivate_a_template_without_removing_it(): void
    {
        $clientId = $this->createShiftTables();
        $templateId = (int) DB::table('tbl_template_shift')->insertGetId([
            'id_klien' => $clientId,
            'nama_shift' => 'Pagi',
            'jam_masuk' => '08:00',
            'jam_pulang' => '16:00',
            'durasi_menit' => 480,
            'status' => 'Aktif',
        ]);
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->actingAs($operasional)->patch(
            route('operasional.jadwal-kerja.template-shift.deactivate', $templateId),
        );

        $response->assertRedirect(route('operasional.jadwal-kerja.template-shift.index'));
        $this->assertDatabaseHas('tbl_template_shift', [
            'id' => $templateId,
            'status' => 'Nonaktif',
        ]);
        $this->assertDatabaseCount('tbl_template_shift', 1);
    }

    public function test_template_page_renders_duration_and_next_day_label(): void
    {
        $clientId = $this->createShiftTables();
        DB::table('tbl_template_shift')->insert([
            ['id_klien' => $clientId, 'nama_shift' => 'Pagi', 'jam_masuk' => '08:00', 'jam_pulang' => '16:00', 'durasi_menit' => 480, 'status' => 'Aktif'],
            ['id_klien' => $clientId, 'nama_shift' => 'Malam', 'jam_masuk' => '20:00', 'jam_pulang' => '04:00', 'durasi_menit' => 480, 'status' => 'Aktif'],
            ['id_klien' => $clientId, 'nama_shift' => 'Sore', 'jam_masuk' => '09:00', 'jam_pulang' => '17:30', 'durasi_menit' => 510, 'status' => 'Nonaktif'],
        ]);
        $operasional = User::factory()->withRole(Role::Operasional)->create();

        $response = $this->actingAs($operasional)->get(route('operasional.jadwal-kerja.template-shift.index'));

        $response->assertOk()
            ->assertSeeText('8 jam')
            ->assertSeeText('8 jam 30 menit')
            ->assertSee('Simpan Perubahan')
            ->assertSee('Nonaktifkan');
        $this->assertSame(1, substr_count($response->getContent(), '(pulang hari berikutnya)'));
    }

    public function test_template_shift_routes_are_limited_to_operasional(): void
    {
        $hrd = User::factory()->withRole(Role::Hrd)->create();

        $this->actingAs($hrd)
            ->get(route('operasional.jadwal-kerja.template-shift.index'))
            ->assertForbidden();
    }

    private function createShiftTables(): int
    {
        Schema::create('tbl_klien', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_klien')->unique();
            $table->string('nama_perusahaan');
            $table->string('status');
        });

        Schema::create('tbl_template_shift', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_klien');
            $table->string('nama_shift', 100);
            $table->time('jam_masuk');
            $table->time('jam_pulang');
            $table->unsignedSmallInteger('durasi_menit');
            $table->string('status');
            $table->integer('dibuat_oleh')->nullable();
            $table->integer('diubah_oleh')->nullable();
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

        return (int) DB::table('tbl_klien')->insertGetId([
            'kode_klien' => 'KL001',
            'nama_perusahaan' => 'Klien Satu',
            'status' => 'Aktif',
        ]);
    }
}

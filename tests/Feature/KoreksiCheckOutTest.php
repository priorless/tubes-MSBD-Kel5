<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class KoreksiCheckOutTest extends TestCase
{
    use CreatesSjiAuthTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-26 09:00:00');
        $this->createTables();
        DB::table('tbl_karyawan')->insert([['id' => 7, 'nip' => 'SJI007', 'nama' => 'Andi'], ['id' => 8, 'nip' => 'SJI008', 'nama' => 'Budi']]);
        DB::table('tbl_absensi')->insert([
            ['id' => 1, 'id_karyawan' => 7, 'tgl' => '2026-11-25', 'jam_masuk' => '08:00:00', 'jam_keluar' => null],
            ['id' => 2, 'id_karyawan' => 7, 'tgl' => '2026-11-24', 'jam_masuk' => '08:00:00', 'jam_keluar' => '17:00:00'],
            ['id' => 3, 'id_karyawan' => 7, 'tgl' => '2026-11-26', 'jam_masuk' => '08:00:00', 'jam_keluar' => null],
            ['id' => 4, 'id_karyawan' => 8, 'tgl' => '2026-11-25', 'jam_masuk' => '08:00:00', 'jam_keluar' => null],
        ]);
    }

    public function test_old_correction_page_redirects_to_attendance_page(): void
    {
        $this->actingAs($this->karyawan())
            ->get(route('tenaga-kerja.koreksi-check-out.index'))
            ->assertRedirect(route('tenaga-kerja.absensi').'#lupa-check-out');
    }

    public function test_employee_submits_correction_once(): void
    {
        $karyawan = $this->karyawan();
        $data = ['id_absensi' => 1, 'jam_keluar' => '17:15', 'alasan' => 'Baterai HP habis'];

        $this->actingAs($karyawan)->post(route('tenaga-kerja.koreksi-check-out.store'), $data)
            ->assertRedirect(route('tenaga-kerja.absensi'));

        $this->assertDatabaseHas('tbl_koreksi_check_out', ['id_absensi' => 1, 'jam_keluar' => '17:15:00', 'status' => 'Menunggu', 'diajukan_oleh' => $karyawan->id]);

        $this->actingAs($karyawan)->post(route('tenaga-kerja.koreksi-check-out.store'), $data)
            ->assertSessionHasErrors('id_absensi');
        $this->assertSame(1, DB::table('tbl_koreksi_check_out')->count());
    }

    public function test_employee_cannot_submit_for_someone_elses_attendance(): void
    {
        $this->actingAs($this->karyawan())
            ->post(route('tenaga-kerja.koreksi-check-out.store'), ['id_absensi' => 4, 'jam_keluar' => '17:00', 'alasan' => 'x'])
            ->assertSessionHasErrors('id_absensi');

        $this->assertSame(0, DB::table('tbl_koreksi_check_out')->count());
    }

    public function test_hrd_approval_fills_check_out_time(): void
    {
        $idKoreksi = $this->pengajuan();
        $hrd = User::factory()->withRole(Role::Hrd)->create();

        $this->actingAs($hrd)->get(route('hrd.koreksi-check-out.index'))->assertOk()->assertSee('Andi')->assertSee('Lupa absen');

        $this->actingAs($hrd)->patch(route('hrd.koreksi-check-out.update', $idKoreksi), ['keputusan' => 'Disetujui'])
            ->assertRedirect(route('hrd.koreksi-check-out.index'));

        $this->assertSame('17:15:00', DB::table('tbl_absensi')->where('id', 1)->value('jam_keluar'));
        $this->assertDatabaseHas('tbl_koreksi_check_out', ['id' => $idKoreksi, 'status' => 'Disetujui', 'diproses_oleh' => $hrd->id]);
        $this->assertDatabaseHas('tbl_log_aktivitas', ['nama_tabel' => 'tbl_absensi', 'id_data' => 1]);
    }

    public function test_hrd_rejection_requires_reason_and_keeps_attendance(): void
    {
        $idKoreksi = $this->pengajuan();
        $hrd = User::factory()->withRole(Role::Hrd)->create();

        $this->actingAs($hrd)->patch(route('hrd.koreksi-check-out.update', $idKoreksi), ['keputusan' => 'Ditolak'])
            ->assertSessionHasErrors('catatan_keputusan');

        $this->actingAs($hrd)->patch(route('hrd.koreksi-check-out.update', $idKoreksi), ['keputusan' => 'Ditolak', 'catatan_keputusan' => 'Tidak sesuai jadwal']);

        $this->assertNull(DB::table('tbl_absensi')->where('id', 1)->value('jam_keluar'));
        $this->assertDatabaseHas('tbl_koreksi_check_out', ['id' => $idKoreksi, 'status' => 'Ditolak', 'catatan_keputusan' => 'Tidak sesuai jadwal']);
    }

    public function test_approval_is_blocked_after_payroll_is_generated(): void
    {
        $idKoreksi = $this->pengajuan();
        DB::table('tbl_payroll')->insert(['id_karyawan' => 7, 'periode_bulan' => 11, 'periode_tahun' => 2026]);

        $this->actingAs(User::factory()->withRole(Role::Hrd)->create())
            ->patch(route('hrd.koreksi-check-out.update', $idKoreksi), ['keputusan' => 'Disetujui'])
            ->assertSessionHasErrors('keputusan');

        $this->assertNull(DB::table('tbl_absensi')->where('id', 1)->value('jam_keluar'));
        $this->assertDatabaseHas('tbl_koreksi_check_out', ['id' => $idKoreksi, 'status' => 'Menunggu']);
    }

    public function test_other_roles_cannot_process_corrections(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Operasional)->create())
            ->patch(route('hrd.koreksi-check-out.update', $this->pengajuan()), ['keputusan' => 'Disetujui'])
            ->assertForbidden();
    }

    private function karyawan(): User
    {
        return User::factory()->withRole(Role::KaryawanLapangan)->create(['id_karyawan' => 7]);
    }

    private function pengajuan(): int
    {
        return (int) DB::table('tbl_koreksi_check_out')->insertGetId([
            'id_absensi' => 1, 'jam_keluar' => '17:15:00', 'alasan' => 'Lupa absen', 'status' => 'Menunggu',
            'diajukan_oleh' => $this->karyawan()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createTables(): void
    {
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip');
            $table->string('nama');
            $table->string('status')->default('Ditempatkan');
        });
        Schema::create('tbl_absensi', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->date('tgl');
            $table->time('jam_masuk')->nullable();
            $table->time('jam_keluar')->nullable();
        });
        Schema::create('tbl_payroll', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->unsignedTinyInteger('periode_bulan');
            $table->unsignedSmallInteger('periode_tahun');
        });
        Schema::create('tbl_log_aktivitas', function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('waktu');
            $table->unsignedInteger('id_user')->nullable();
            $table->string('nama_tabel');
            $table->string('aksi');
            $table->unsignedInteger('id_data')->nullable();
            $table->string('keterangan')->nullable();
        });
        Schema::create('tbl_koreksi_check_out', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_absensi');
            $table->time('jam_keluar');
            $table->string('alasan', 500);
            $table->string('status')->default('Menunggu');
            $table->string('catatan_keputusan', 500)->nullable();
            $table->unsignedInteger('diajukan_oleh');
            $table->unsignedInteger('diproses_oleh')->nullable();
            $table->timestamp('diproses_pada')->nullable();
            $table->timestamps();
        });
    }
}

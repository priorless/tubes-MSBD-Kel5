<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class CatatanDriverTest extends TestCase
{
    use CreatesSjiAuthTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        DB::table('tbl_klien')->insert(['id' => 1, 'nama_perusahaan' => 'PT Demo']);
        DB::table('tbl_karyawan')->insert([['id' => 1, 'nip' => 'SJI001', 'nama' => 'Dodi Driver'], ['id' => 2, 'nip' => 'SJI002', 'nama' => 'Eko Driver']]);
        DB::table('tbl_penempatan')->insert(['id' => 101, 'id_karyawan' => 1, 'id_klien' => 1]);
        DB::table('tbl_absensi')->insert(['id' => 11, 'id_penempatan' => 101]);
        DB::table('tbl_laporan_aktivitas')->insert([
            ['id_absensi' => 11, 'id_karyawan' => 1, 'jenis' => 'Perjalanan', 'keterangan' => 'Macet di tol', 'waktu_lapor' => '2026-11-25 08:00:00', 'waktu_koreksi' => null],
            ['id_absensi' => 11, 'id_karyawan' => 2, 'jenis' => 'Berkala', 'keterangan' => 'Patroli satpam', 'waktu_lapor' => '2026-11-25 08:30:00', 'waktu_koreksi' => null],
        ]);
        DB::table('tbl_p2h')->insert(['id' => 5, 'id_absensi' => 11, 'id_karyawan' => 1, 'waktu_pemeriksaan' => '2026-11-25 07:00:00',
            'kondisi_akhir' => 'Rusak', 'catatan_akhir' => 'Spion kiri patah', 'waktu_selesai' => '2026-11-25 17:00:00']);
        DB::table('tbl_p2h_item')->insert(['id_p2h' => 5, 'komponen' => 'ban', 'kondisi' => 'Perlu Perhatian', 'keterangan' => 'Ban depan tipis']);
    }

    #[TestWith([Role::Hrd, 'hrd.catatan-driver'])]
    #[TestWith([Role::Operasional, 'operasional.catatan-driver'])]
    public function test_hrd_and_operasional_see_all_driver_notes(string $role, string $routeName): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route($routeName))
            ->assertOk()
            ->assertSeeInOrder(['Spion kiri patah', 'Macet di tol', 'Ban depan tipis'])
            ->assertSee('Tekanan dan kondisi fisik ban')
            ->assertSee('PT Demo')
            ->assertDontSee('Patroli satpam');
    }

    public function test_notes_can_be_searched_and_filtered_by_type(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Operasional)->create())
            ->get(route('operasional.catatan-driver', ['q' => 'spion', 'sumber' => 'akhir']))
            ->assertOk()
            ->assertSee('Spion kiri patah')
            ->assertDontSee('Macet di tol')
            ->assertDontSee('Ban depan tipis');
    }

    public function test_other_roles_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->withRole(Role::Keuangan)->create())
            ->get(route('hrd.catatan-driver'))
            ->assertForbidden();
    }

    private function createTables(): void
    {
        Schema::create('tbl_klien', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_perusahaan');
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
        });
        Schema::create('tbl_absensi', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_penempatan')->nullable();
        });
        Schema::create('tbl_laporan_aktivitas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_absensi');
            $table->unsignedInteger('id_karyawan');
            $table->string('jenis');
            $table->string('keterangan', 500)->nullable();
            $table->dateTime('waktu_lapor');
            $table->dateTime('waktu_koreksi')->nullable();
        });
        Schema::create('tbl_p2h', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_absensi');
            $table->unsignedInteger('id_karyawan');
            $table->dateTime('waktu_pemeriksaan')->nullable();
            $table->string('kondisi_akhir')->nullable();
            $table->string('catatan_akhir', 500)->nullable();
            $table->dateTime('waktu_selesai')->nullable();
        });
        Schema::create('tbl_p2h_item', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_p2h');
            $table->string('komponen');
            $table->string('kondisi');
            $table->string('keterangan')->nullable();
        });
    }
}

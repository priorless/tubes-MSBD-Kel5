<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Tambah Karyawan HRD hanya untuk kategori Lapangan; karyawan Internal lama tetap tampil dan dapat diedit.
 * Skema minimal SQLite tanpa trigger: status awal & akun login Lapangan (trigger MySQL) diuji HrdModulTest.
 */
class TambahKaryawanLapanganTest extends TestCase
{
    use CreatesSjiAuthTables;

    private int $idSatpam;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tbl_jenis_layanan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_layanan');
        });
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip')->unique();
            $table->string('nama');
            $table->string('kategori');
            $table->unsignedInteger('id_jenis_layanan')->nullable();
            $table->decimal('gaji_pokok', 12, 2)->nullable();
            $table->string('no_ktp')->unique();
            $table->string('tempat_lahir')->nullable();
            $table->date('tgl_lahir');
            $table->string('jenis_kelamin');
            $table->string('status_pernikahan')->nullable();
            $table->string('nama_ibu_kandung')->nullable();
            $table->string('jenis_jaminan_kesehatan')->nullable();
            $table->text('alamat')->nullable();
            $table->string('no_telp');
            $table->string('email')->nullable()->unique();
            $table->string('foto')->nullable();
            $table->date('tgl_masuk');
            $table->string('status')->default('Tersedia');
        });
        Schema::create('tbl_penempatan', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->string('status');
        });

        $this->idSatpam = (int) DB::table('tbl_jenis_layanan')->insertGetId(['nama_layanan' => 'Satpam']);
        $this->actingAs(User::factory()->withRole(Role::Hrd)->create());
    }

    public function test_add_form_has_no_category_section_or_internal_staff_options(): void
    {
        $this->get(route('hrd.karyawan.create'))
            ->assertOk()
            ->assertSee('Akun karyawan Lapangan', false)
            ->assertDontSee('<span class="form-label">Kategori</span>', false)
            ->assertDontSeeText('Lapangan (satpam, driver, cleaning service)')
            ->assertDontSeeText('Internal (staf kantor)')
            ->assertDontSee('name="kategori"', false)
            ->assertDontSee('name="buat_akun"', false)
            ->assertDontSeeText('Akun Login Staf');
    }

    public function test_new_employee_is_saved_as_field_staff_and_ignores_internal_only_inputs(): void
    {
        $this->post(route('hrd.karyawan.store'), [
            ...$this->dataKaryawan(),
            'gaji_pokok' => 6000000,
            'buat_akun' => '1',
            'username' => 'staf.tidak.dibuat',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])
            ->assertRedirect(route('hrd.karyawan.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Karyawan Lapangan berhasil ditambahkan. Akun login dibuat otomatis oleh sistem (username = NIP).');

        $this->assertDatabaseHas('tbl_karyawan', [
            'nip' => 'TST-001', 'kategori' => 'Lapangan', 'id_jenis_layanan' => $this->idSatpam, 'gaji_pokok' => null,
        ]);
        $this->assertDatabaseMissing('tbl_users', ['username' => 'staf.tidak.dibuat']);
    }

    #[TestWith(['Internal'])]
    #[TestWith(['Kontrak'])]
    public function test_any_category_other_than_field_staff_is_rejected_without_saving(string $kategori): void
    {
        $this->from(route('hrd.karyawan.create'))
            ->post(route('hrd.karyawan.store'), [...$this->dataKaryawan(), 'kategori' => $kategori, 'gaji_pokok' => 6000000])
            ->assertRedirect(route('hrd.karyawan.create'))
            ->assertSessionHasErrors(['kategori' => 'Karyawan baru hanya dapat didaftarkan dengan kategori Lapangan.']);

        $this->assertDatabaseCount('tbl_karyawan', 0);
    }

    public function test_existing_internal_employee_still_opens_with_salary_field_on_edit(): void
    {
        $idInternal = (int) DB::table('tbl_karyawan')->insertGetId([
            ...$this->dataKaryawan(), 'nip' => 'TST-INT', 'no_ktp' => '2222222222222222', 'kategori' => 'Internal',
            'id_jenis_layanan' => null, 'gaji_pokok' => 5000000, 'status' => 'Aktif',
        ]);

        $this->get(route('hrd.karyawan.edit', $idInternal))
            ->assertOk()
            ->assertSeeInOrder(['<span class="form-label">Kategori</span>', '<span class="badge badge-dark">Internal</span>'], false)
            ->assertSee('name="gaji_pokok"', false)
            ->assertSee('value="5000000"', false)
            ->assertDontSee('name="kategori"', false);

        $this->assertDatabaseHas('tbl_karyawan', ['id' => $idInternal, 'kategori' => 'Internal', 'gaji_pokok' => 5000000]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataKaryawan(): array
    {
        return [
            'nip' => 'TST-001', 'nama' => 'Karyawan Uji', 'id_jenis_layanan' => $this->idSatpam,
            'no_ktp' => '1111111111111111', 'tgl_lahir' => '1995-05-05', 'jenis_kelamin' => 'L',
            'no_telp' => '081234567890', 'tgl_masuk' => '2026-11-01',
        ];
    }
}

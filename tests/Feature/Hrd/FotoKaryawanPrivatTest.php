<?php

namespace Tests\Feature\Hrd;

use App\Http\Controllers\Hrd\KaryawanController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Foto profil karyawan di disk privat: disimpan & diganti di disk local, ditampilkan lewat route karyawan.foto
 * (HRD atau pemilik), dan foto lama di disk public dipindahkan oleh migration. Skema minimal SQLite.
 */
class FotoKaryawanPrivatTest extends TestCase
{
    use CreatesSjiAuthTables;

    private int $idSatpam;

    private User $hrd;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(KaryawanController::DISK_FOTO);
        Storage::fake('public');
        $this->createTables();
        $this->idSatpam = (int) DB::table('tbl_jenis_layanan')->insertGetId(['nama_layanan' => 'Satpam']);
        $this->hrd = User::factory()->withRole(Role::Hrd)->create();
    }

    public function test_uploaded_photo_is_stored_on_the_private_disk_and_shown_through_the_protected_route(): void
    {
        $this->actingAs($this->hrd)
            ->post(route('hrd.karyawan.store'), [...$this->dataKaryawan(), 'foto' => UploadedFile::fake()->image('andi.jpg')])
            ->assertRedirect(route('hrd.karyawan.index'));

        $karyawan = DB::table('tbl_karyawan')->where('nip', 'TST-001')->first(['id', 'foto']);
        $this->assertStringStartsWith(KaryawanController::FOLDER_FOTO.'/', $karyawan->foto);
        Storage::disk(KaryawanController::DISK_FOTO)->assertExists($karyawan->foto);
        Storage::disk('public')->assertMissing($karyawan->foto);

        $this->actingAs($this->hrd)->get(route('hrd.karyawan.index'))
            ->assertOk()
            ->assertSee('data-foto-url="'.route('karyawan.foto', $karyawan->id).'"', false)
            ->assertSee('<img src="'.route('karyawan.foto', $karyawan->id).'"', false)
            ->assertDontSee('storage/'.$karyawan->foto, false);
        $this->actingAs($this->hrd)->get(route('hrd.karyawan.edit', $karyawan->id))
            ->assertOk()
            ->assertSee('src="'.route('karyawan.foto', $karyawan->id).'"', false);

        $this->actingAs($this->hrd)->get(route('karyawan.foto', $karyawan->id))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_replacing_the_photo_keeps_the_new_one_and_removes_the_old_one(): void
    {
        $idKaryawan = $this->karyawanDenganFoto('foto-karyawan/lama.jpg');

        $this->actingAs($this->hrd)
            ->put(route('hrd.karyawan.update', $idKaryawan), [...$this->dataKaryawan(), 'foto' => UploadedFile::fake()->image('baru.jpg')])
            ->assertRedirect(route('hrd.karyawan.index'));

        $pathBaru = DB::table('tbl_karyawan')->where('id', $idKaryawan)->value('foto');
        $this->assertNotSame('foto-karyawan/lama.jpg', $pathBaru);
        Storage::disk(KaryawanController::DISK_FOTO)->assertExists($pathBaru);
        Storage::disk(KaryawanController::DISK_FOTO)->assertMissing('foto-karyawan/lama.jpg');
    }

    public function test_update_without_a_new_photo_keeps_the_existing_one(): void
    {
        $idKaryawan = $this->karyawanDenganFoto('foto-karyawan/lama.jpg');

        $this->actingAs($this->hrd)
            ->put(route('hrd.karyawan.update', $idKaryawan), [...$this->dataKaryawan(), 'nama' => 'Nama Baru'])
            ->assertRedirect(route('hrd.karyawan.index'));

        $this->assertDatabaseHas('tbl_karyawan', ['id' => $idKaryawan, 'foto' => 'foto-karyawan/lama.jpg', 'nama' => 'Nama Baru']);
        Storage::disk(KaryawanController::DISK_FOTO)->assertExists('foto-karyawan/lama.jpg');
    }

    public function test_owner_can_open_their_own_photo(): void
    {
        $idKaryawan = $this->karyawanDenganFoto('foto-karyawan/lama.jpg');

        $this->actingAs(User::factory()->withRole(Role::KaryawanLapangan)->create(['id_karyawan' => $idKaryawan]))
            ->get(route('karyawan.foto', $idKaryawan))
            ->assertOk();
    }

    #[TestWith([Role::Operasional])]
    #[TestWith([Role::Keuangan])]
    #[TestWith([Role::KaryawanLapangan])]
    public function test_other_users_cannot_open_someone_elses_photo(string $role): void
    {
        $idKaryawan = $this->karyawanDenganFoto('foto-karyawan/lama.jpg');
        $idLain = (int) DB::table('tbl_karyawan')->insertGetId([...$this->dataKaryawan(), 'nip' => 'TST-002', 'no_ktp' => '2222222222222222', 'kategori' => 'Lapangan']);

        $this->actingAs(User::factory()->withRole($role)->create(['id_karyawan' => $idLain]))
            ->get(route('karyawan.foto', $idKaryawan))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_and_missing_photos_return_not_found(): void
    {
        $idKaryawan = $this->karyawanDenganFoto('foto-karyawan/lama.jpg');
        $idTanpaFoto = (int) DB::table('tbl_karyawan')->insertGetId([...$this->dataKaryawan(), 'nip' => 'TST-002', 'no_ktp' => '2222222222222222', 'kategori' => 'Lapangan']);

        $this->get(route('karyawan.foto', $idKaryawan))->assertRedirect(route('login'));
        $this->actingAs($this->hrd)->get(route('karyawan.foto', $idTanpaFoto))->assertNotFound();
        $this->actingAs($this->hrd)->get(route('karyawan.foto', 999))->assertNotFound();
    }

    public function test_migration_moves_existing_public_photos_to_the_private_disk_and_back(): void
    {
        $idKaryawan = (int) DB::table('tbl_karyawan')->insertGetId([
            ...$this->dataKaryawan(), 'kategori' => 'Lapangan', 'foto' => 'foto-karyawan/warisan.png',
        ]);
        Storage::disk('public')->put('foto-karyawan/warisan.png', 'FOTO-LAMA');
        $migration = require database_path('migrations/2026_11_27_235157_pindahkan_foto_karyawan_ke_disk_privat.php');

        $migration->up();

        Storage::disk('public')->assertMissing('foto-karyawan/warisan.png');
        $this->assertSame('FOTO-LAMA', Storage::disk(KaryawanController::DISK_FOTO)->get('foto-karyawan/warisan.png'));
        $this->assertDatabaseHas('tbl_karyawan', ['id' => $idKaryawan, 'foto' => 'foto-karyawan/warisan.png']);
        $this->assertSame('FOTO-LAMA', $this->actingAs($this->hrd)->get(route('karyawan.foto', $idKaryawan))->assertOk()->streamedContent());

        $migration->down();

        Storage::disk(KaryawanController::DISK_FOTO)->assertMissing('foto-karyawan/warisan.png');
        $this->assertSame('FOTO-LAMA', Storage::disk('public')->get('foto-karyawan/warisan.png'));
    }

    private function karyawanDenganFoto(string $path): int
    {
        Storage::disk(KaryawanController::DISK_FOTO)->put($path, 'FOTO');

        return (int) DB::table('tbl_karyawan')->insertGetId([...$this->dataKaryawan(), 'kategori' => 'Lapangan', 'foto' => $path]);
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

    private function createTables(): void
    {
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
    }
}

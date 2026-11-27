<?php

namespace Tests\Feature\Hrd;

use App\Http\Controllers\Hrd\SertifikatController;
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
 * Edit sertifikat oleh HRD: baris yang sama diperbarui (tanggal & berkas opsional), berkas tetap di disk privat.
 * Skema minimal SQLite.
 */
class EditSertifikatTest extends TestCase
{
    use CreatesSjiAuthTables;

    private const string BERKAS_LAMA = 'sertifikat/berkas-lama.pdf';

    private int $idKaryawan;

    private int $idSertifikat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(SertifikatController::DISK);
        Storage::fake('public');
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip');
            $table->string('nama');
        });
        Schema::create('tbl_sertifikat', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->string('nama_dokumen');
            $table->string('nomor_dokumen')->nullable();
            $table->date('tgl_terbit');
            $table->date('tgl_kadaluarsa');
            $table->string('file_upload')->nullable();
        });

        $this->idKaryawan = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI001', 'nama' => 'Andi']);
        $this->idSertifikat = (int) DB::table('tbl_sertifikat')->insertGetId([
            'id_karyawan' => $this->idKaryawan, 'nama_dokumen' => 'Gada Pratama', 'nomor_dokumen' => 'GP-01',
            'tgl_terbit' => '2024-01-01', 'tgl_kadaluarsa' => '2026-01-01', 'file_upload' => self::BERKAS_LAMA,
        ]);
        Storage::disk(SertifikatController::DISK)->put(self::BERKAS_LAMA, 'PDF-LAMA');
    }

    public function test_list_shows_an_edit_button_for_each_certificate(): void
    {
        DB::statement("CREATE VIEW v_sertifikat_karyawan AS SELECT s.id, s.id_karyawan, k.nip, k.nama, s.nama_dokumen,
            s.nomor_dokumen, s.tgl_terbit, s.tgl_kadaluarsa, s.file_upload, 'Aktif' AS status, 400 AS sisa_hari
            FROM tbl_sertifikat s JOIN tbl_karyawan k ON k.id = s.id_karyawan");

        $this->actingAs($this->hrd())
            ->get(route('hrd.sertifikat.index'))
            ->assertOk()
            ->assertSee('href="'.route('hrd.sertifikat.edit', $this->idSertifikat).'" class="btn-secondary btn-sm">Edit</a>', false);
    }

    public function test_edit_form_shows_the_certificate_with_its_current_dates_and_file(): void
    {
        $this->actingAs($this->hrd())
            ->get(route('hrd.sertifikat.edit', $this->idSertifikat))
            ->assertOk()
            ->assertSeeText('Andi')
            ->assertSeeText('Gada Pratama')
            ->assertSee('action="'.route('hrd.sertifikat.update', $this->idSertifikat).'"', false)
            ->assertSee('value="2024-01-01"', false)
            ->assertSee('value="2026-01-01"', false)
            ->assertSee('href="'.route('sertifikat.berkas', $this->idSertifikat).'"', false)
            ->assertDontSee('name="id_karyawan"', false)
            ->assertDontSee('name="nama_dokumen"', false);
    }

    public function test_dates_are_updated_on_the_same_record_and_the_old_file_is_kept(): void
    {
        $this->actingAs($this->hrd())
            ->put(route('hrd.sertifikat.update', $this->idSertifikat), [
                'tgl_terbit' => '2026-02-01', 'tgl_kadaluarsa' => '2029-02-01',
                'id_karyawan' => 999, 'nama_dokumen' => 'Diganti',
            ])
            ->assertRedirect(route('hrd.sertifikat.index'))
            ->assertSessionHas('success', 'Dokumen sertifikat berhasil diperbarui.');

        $this->assertDatabaseCount('tbl_sertifikat', 1);
        $this->assertDatabaseHas('tbl_sertifikat', [
            'id' => $this->idSertifikat, 'id_karyawan' => $this->idKaryawan, 'nama_dokumen' => 'Gada Pratama',
            'tgl_terbit' => '2026-02-01', 'tgl_kadaluarsa' => '2029-02-01', 'file_upload' => self::BERKAS_LAMA,
        ]);
        Storage::disk(SertifikatController::DISK)->assertExists(self::BERKAS_LAMA);
    }

    public function test_new_file_replaces_the_old_one_on_the_private_disk(): void
    {
        $this->actingAs($this->hrd())
            ->put(route('hrd.sertifikat.update', $this->idSertifikat), [
                'tgl_terbit' => '2024-01-01', 'tgl_kadaluarsa' => '2028-01-01',
                'file_upload' => UploadedFile::fake()->create('baru.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('hrd.sertifikat.index'));

        $pathBaru = DB::table('tbl_sertifikat')->where('id', $this->idSertifikat)->value('file_upload');
        $this->assertNotSame(self::BERKAS_LAMA, $pathBaru);
        $this->assertStringStartsWith('sertifikat/', $pathBaru);
        Storage::disk(SertifikatController::DISK)->assertExists($pathBaru);
        Storage::disk(SertifikatController::DISK)->assertMissing(self::BERKAS_LAMA);
        Storage::disk('public')->assertMissing($pathBaru);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[TestWith([['tgl_terbit' => '2026-01-01', 'tgl_kadaluarsa' => '2025-12-31'], 'tgl_kadaluarsa', 'Tanggal kedaluwarsa harus setelah tanggal terbit.'])]
    #[TestWith([['tgl_terbit' => '', 'tgl_kadaluarsa' => '2028-01-01'], 'tgl_terbit', 'Tanggal terbit wajib diisi.'])]
    #[TestWith([['tgl_terbit' => '2024-01-01', 'tgl_kadaluarsa' => '2028-01-01', 'file' => 'exe'], 'file_upload', 'Berkas dokumen harus berformat pdf, jpg, jpeg, png.'])]
    public function test_invalid_input_follows_upload_rules_and_changes_nothing(array $input, string $kolom, string $pesan): void
    {
        if (($input['file'] ?? null) === 'exe') {
            unset($input['file']);
            $input['file_upload'] = UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream');
        }

        $this->actingAs($this->hrd())
            ->from(route('hrd.sertifikat.edit', $this->idSertifikat))
            ->put(route('hrd.sertifikat.update', $this->idSertifikat), $input)
            ->assertRedirect(route('hrd.sertifikat.edit', $this->idSertifikat))
            ->assertSessionHasErrors([$kolom => $pesan]);

        $this->assertDatabaseHas('tbl_sertifikat', [
            'id' => $this->idSertifikat, 'tgl_terbit' => '2024-01-01', 'tgl_kadaluarsa' => '2026-01-01', 'file_upload' => self::BERKAS_LAMA,
        ]);
        $this->assertSame([self::BERKAS_LAMA], Storage::disk(SertifikatController::DISK)->allFiles());
    }

    public function test_unknown_certificate_returns_not_found(): void
    {
        $this->actingAs($this->hrd())->get(route('hrd.sertifikat.edit', 999))->assertNotFound();
        $this->actingAs($this->hrd())
            ->put(route('hrd.sertifikat.update', 999), ['tgl_terbit' => '2024-01-01', 'tgl_kadaluarsa' => '2028-01-01'])
            ->assertNotFound();
    }

    #[TestWith([Role::Operasional])]
    #[TestWith([Role::Keuangan])]
    #[TestWith([Role::KaryawanLapangan])]
    public function test_roles_other_than_hrd_cannot_edit(string $role): void
    {
        $akun = User::factory()->withRole($role)->create(['id_karyawan' => $this->idKaryawan]);

        $this->actingAs($akun)->get(route('hrd.sertifikat.edit', $this->idSertifikat))->assertForbidden();
        $this->actingAs($akun)
            ->put(route('hrd.sertifikat.update', $this->idSertifikat), ['tgl_terbit' => '2024-01-01', 'tgl_kadaluarsa' => '2030-01-01'])
            ->assertForbidden();

        $this->assertDatabaseHas('tbl_sertifikat', ['id' => $this->idSertifikat, 'tgl_kadaluarsa' => '2026-01-01']);
    }

    private function hrd(): User
    {
        return User::factory()->withRole(Role::Hrd)->create();
    }
}

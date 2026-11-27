<?php

namespace Tests\Feature\Hrd;

use App\Http\Controllers\Hrd\SertifikatController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Berkas sertifikat ada di disk privat dan hanya dibuka lewat route sertifikat.berkas (login, HRD atau pemilik),
 * baik ditampilkan maupun diunduh. Skema minimal SQLite.
 */
class BerkasSertifikatTest extends TestCase
{
    use CreatesSjiAuthTables;

    private const string PATH = 'sertifikat/gada-pratama.pdf';

    private int $idPemilik;

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
            $table->string('file_upload')->nullable();
        });

        $this->idPemilik = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI001', 'nama' => 'Andi']);
        $this->idSertifikat = (int) DB::table('tbl_sertifikat')->insertGetId([
            'id_karyawan' => $this->idPemilik, 'nama_dokumen' => 'Gada Pratama', 'file_upload' => self::PATH,
        ]);
        Storage::disk(SertifikatController::DISK)->put(self::PATH, '%PDF-SERTIFIKAT-ANDI');
    }

    public function test_hrd_views_the_private_file_inline(): void
    {
        $respons = $this->actingAs($this->akun(Role::Hrd))
            ->get(route('sertifikat.berkas', $this->idSertifikat))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame('%PDF-SERTIFIKAT-ANDI', $respons->streamedContent());
        $this->assertStringStartsWith('inline', (string) $respons->headers->get('Content-Disposition'));
    }

    public function test_hrd_downloads_the_file_with_a_readable_name(): void
    {
        $respons = $this->actingAs($this->akun(Role::Hrd))
            ->get(route('sertifikat.berkas', ['sertifikat' => $this->idSertifikat, 'unduh' => 1]))
            ->assertOk()
            ->assertDownload('sertifikat-sji001-gada-pratama.pdf');

        $this->assertSame('%PDF-SERTIFIKAT-ANDI', $respons->streamedContent());
    }

    public function test_hrd_list_links_to_view_and_download_through_the_private_route(): void
    {
        // Kolom sama dengan v_sertifikat_karyawan di db_sji; status & sisa hari dibuat tetap.
        DB::statement("CREATE VIEW v_sertifikat_karyawan AS SELECT s.id, s.id_karyawan, k.nip, k.nama, s.nama_dokumen,
            NULL AS nomor_dokumen, '2026-01-01' AS tgl_terbit, '2028-01-01' AS tgl_kadaluarsa, s.file_upload,
            'Aktif' AS status, 400 AS sisa_hari
            FROM tbl_sertifikat s JOIN tbl_karyawan k ON k.id = s.id_karyawan");

        $this->actingAs($this->akun(Role::Hrd))
            ->get(route('hrd.sertifikat.index'))
            ->assertOk()
            ->assertSee('href="'.route('sertifikat.berkas', $this->idSertifikat).'"', false)
            ->assertSee('href="'.route('sertifikat.berkas', ['sertifikat' => $this->idSertifikat, 'unduh' => 1]).'"', false)
            ->assertDontSee('storage/'.self::PATH, false);
    }

    public function test_owner_can_open_their_own_file(): void
    {
        $this->actingAs($this->akun(Role::KaryawanLapangan, $this->idPemilik))
            ->get(route('sertifikat.berkas', ['sertifikat' => $this->idSertifikat, 'unduh' => 1]))
            ->assertOk();
    }

    #[TestWith([Role::Operasional])]
    #[TestWith([Role::Keuangan])]
    #[TestWith([Role::KaryawanLapangan])]
    public function test_other_users_are_forbidden(string $role): void
    {
        $idLain = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI002', 'nama' => 'Budi']);

        $this->actingAs($this->akun($role, $idLain))
            ->get(route('sertifikat.berkas', ['sertifikat' => $this->idSertifikat, 'unduh' => 1]))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('sertifikat.berkas', $this->idSertifikat))->assertRedirect(route('login'));
    }

    public function test_file_is_not_on_the_public_disk_and_missing_files_return_not_found(): void
    {
        Storage::disk('public')->assertMissing(self::PATH);
        Storage::disk(SertifikatController::DISK)->delete(self::PATH);

        $this->actingAs($this->akun(Role::Hrd))->get(route('sertifikat.berkas', $this->idSertifikat))->assertNotFound();
        $this->actingAs($this->akun(Role::Hrd))->get(route('sertifikat.berkas', 999))->assertNotFound();
    }

    private function akun(string $role, ?int $idKaryawan = null): User
    {
        return User::factory()->withRole($role)->create(['id_karyawan' => $idKaryawan]);
    }
}

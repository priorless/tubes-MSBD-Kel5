<?php

namespace Tests\Feature\Hrd;

use App\Http\Controllers\Hrd\SertifikatController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Status sertifikat dihitung view v_sertifikat_karyawan dari tgl_kadaluarsa (tidak disimpan, tanpa event),
 * CHECK tanggal, berkas di disk privat, dan halaman "Sertifikat Saya" (karyawan hanya melihat miliknya).
 */
class StatusSertifikatTest extends TestCase
{
    use MemakaiDatabaseSji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    private function buatSertifikat(int $idKaryawan, string $nama, string $tglKadaluarsa, ?string $berkas = null): int
    {
        return DB::table('tbl_sertifikat')->insertGetId([
            'id_karyawan' => $idKaryawan,
            'nama_dokumen' => $nama,
            'tgl_terbit' => '2025-01-01',
            'tgl_kadaluarsa' => $tglKadaluarsa,
            'file_upload' => $berkas,
        ]);
    }

    public function test_status_tidak_disimpan_dan_event_harian_sudah_tidak_ada(): void
    {
        $this->assertFalse(Schema::hasColumn('tbl_sertifikat', 'status'));
        $this->assertFalse(DB::table('information_schema.EVENTS')
            ->where('EVENT_SCHEMA', DB::getDatabaseName())
            ->where('EVENT_NAME', 'ev_update_status_sertifikat')
            ->exists());
    }

    public function test_view_menghitung_status_dari_tanggal_kedaluwarsa(): void
    {
        $idKaryawan = $this->buatKaryawan('Lapangan');
        $lewat = $this->buatSertifikat($idKaryawan, 'Dokumen Lewat TST', $this->hariIniDb(-1));
        $hariIni = $this->buatSertifikat($idKaryawan, 'Dokumen Hari Ini TST', $this->hariIniDb());

        $status = DB::table('v_sertifikat_karyawan')->whereIn('id', [$lewat, $hariIni])->pluck('status', 'id');

        $this->assertSame('Kadaluarsa', $status[$lewat]);
        $this->assertSame('Aktif', $status[$hariIni], 'Kedaluwarsa hari ini belum lewat');
    }

    public function test_check_menolak_tanggal_kedaluwarsa_tidak_setelah_terbit(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/chk_sertifikat_tanggal/');

        DB::table('tbl_sertifikat')->insert([
            'id_karyawan' => $this->buatKaryawan('Lapangan'),
            'nama_dokumen' => 'Dokumen Terbalik TST',
            'tgl_terbit' => '2026-05-01',
            'tgl_kadaluarsa' => '2026-05-01',
        ]);
    }

    public function test_halaman_hrd_menampilkan_status_dari_view(): void
    {
        $this->loginSebagai(Role::Hrd);
        $this->buatSertifikat($this->buatKaryawan('Lapangan'), 'Dokumen Lewat TST', $this->hariIniDb(-3));

        $this->get(route('hrd.sertifikat.index', ['q' => 'Dokumen Lewat TST']))
            ->assertOk()
            ->assertSee('<span class="badge badge-danger">Kedaluwarsa</span>', false);
    }

    public function test_berkas_disimpan_di_disk_privat_dan_hanya_dibuka_pemilik_atau_hrd(): void
    {
        Storage::fake(SertifikatController::DISK);
        Storage::fake('public');
        $this->loginSebagai(Role::Hrd);
        $idPemilik = $this->buatKaryawan('Lapangan');

        $this->post(route('hrd.sertifikat.store'), [
            'id_karyawan' => $idPemilik,
            'nama_dokumen' => 'Gada Pratama TST',
            'tgl_terbit' => '2026-01-01',
            'tgl_kadaluarsa' => '2028-01-01',
            'file_upload' => UploadedFile::fake()->create('gada.pdf', 100, 'application/pdf'),
        ])->assertRedirect(route('hrd.sertifikat.index'));

        $sertifikat = DB::table('tbl_sertifikat')->where('id_karyawan', $idPemilik)->sole();
        Storage::disk(SertifikatController::DISK)->assertExists($sertifikat->file_upload);
        Storage::disk('public')->assertMissing($sertifikat->file_upload);

        $this->get(route('sertifikat.berkas', $sertifikat->id))->assertOk();
        $this->actingAs($this->akunKaryawan($idPemilik))->get(route('sertifikat.berkas', $sertifikat->id))->assertOk();
        $this->actingAs($this->akunKaryawan($this->buatKaryawan('Lapangan')))
            ->get(route('sertifikat.berkas', $sertifikat->id))->assertForbidden();
    }

    public function test_karyawan_hanya_melihat_sertifikat_miliknya_tanpa_tombol_tambah(): void
    {
        $idPemilik = $this->buatKaryawan('Lapangan');
        $this->buatSertifikat($idPemilik, 'Sertifikat Milik Saya TST', $this->hariIniDb(400));
        $this->buatSertifikat($this->buatKaryawan('Lapangan'), 'Sertifikat Orang Lain TST', $this->hariIniDb(400));

        $this->actingAs($this->akunKaryawan($idPemilik))
            ->get(route('tenaga-kerja.sertifikat'))
            ->assertOk()
            ->assertSee('Sertifikat Milik Saya TST')
            ->assertDontSee('Sertifikat Orang Lain TST')
            ->assertDontSee(route('hrd.sertifikat.create'));
    }

    public function test_karyawan_tidak_bisa_menambah_sertifikat(): void
    {
        $this->actingAs($this->akunKaryawan($this->buatKaryawan('Lapangan')))
            ->get(route('hrd.sertifikat.create'))
            ->assertForbidden();
    }

    /** Akun login karyawan lapangan (dibuat trigger trg_auto_akun_karyawan) yang sudah ganti password. */
    private function akunKaryawan(int $idKaryawan): User
    {
        $user = User::query()->where('id_karyawan', $idKaryawan)->sole();
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }
}

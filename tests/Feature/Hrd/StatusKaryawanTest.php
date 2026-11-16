<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Aturan status karyawan (trigger trg_karyawan_validasi_status) dan sinkron status akun login
 * (trigger trg_karyawan_sinkron_akun), lewat form HRD maupun UPDATE langsung ke database.
 */
class StatusKaryawanTest extends TestCase
{
    use MemakaiDatabaseSji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
        $this->loginSebagai(Role::Hrd);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    private function ubahLewatForm(int $idKaryawan, string $status): TestResponse
    {
        $data = (array) DB::table('tbl_karyawan')->where('id', $idKaryawan)->first();

        return $this->from(route('hrd.karyawan.edit', $idKaryawan))->put(route('hrd.karyawan.update', $idKaryawan), [
            'nama' => $data['nama'],
            'id_jenis_layanan' => $data['id_jenis_layanan'],
            'gaji_pokok' => $data['gaji_pokok'],
            'no_ktp' => $data['no_ktp'],
            'tgl_lahir' => $data['tgl_lahir'],
            'jenis_kelamin' => $data['jenis_kelamin'],
            'no_telp' => $data['no_telp'],
            'tgl_masuk' => $data['tgl_masuk'],
            'status' => $status,
        ]);
    }

    private function statusAkun(int $idKaryawan): ?string
    {
        return DB::table('tbl_users')->where('id_karyawan', $idKaryawan)->value('status');
    }

    private function signalDariUpdateLangsung(int $idKaryawan, string $status): string
    {
        try {
            DB::table('tbl_karyawan')->where('id', $idKaryawan)->update(['status' => $status]);
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);

            return $exception->errorInfo[2];
        }

        $this->fail("UPDATE status ke {$status} seharusnya ditolak trigger.");
    }

    public function test_form_edit_menampilkan_pilihan_status_sesuai_kategori(): void
    {
        $this->get(route('hrd.karyawan.edit', $this->buatKaryawan('Internal')))
            ->assertOk()
            ->assertSee('<option value="Cuti"', false)
            ->assertSee('<option value="Aktif"', false)
            ->assertDontSee('<option value="Tersedia"', false);

        $this->get(route('hrd.karyawan.edit', $this->buatKaryawan('Lapangan')))
            ->assertOk()
            ->assertSee('<option value="Tersedia"', false)
            ->assertDontSee('<option value="Ditempatkan"', false);
    }

    public function test_lapangan_nonaktif_menonaktifkan_akun_dan_aktif_kembali(): void
    {
        $idKaryawan = $this->buatKaryawan('Lapangan');
        $this->assertSame('Aktif', $this->statusAkun($idKaryawan), 'Akun dibuat trigger trg_auto_akun_karyawan.');

        $this->ubahLewatForm($idKaryawan, 'Nonaktif')->assertRedirect(route('hrd.karyawan.index'));
        $this->assertSame('Nonaktif', DB::table('tbl_karyawan')->where('id', $idKaryawan)->value('status'));
        $this->assertSame('Nonaktif', $this->statusAkun($idKaryawan));

        $this->ubahLewatForm($idKaryawan, 'Tersedia')->assertRedirect(route('hrd.karyawan.index'));
        $this->assertSame('Aktif', $this->statusAkun($idKaryawan));
    }

    public function test_cuti_tidak_mengubah_akun_dan_tidak_mengaktifkan_akun_yang_dinonaktifkan_manual(): void
    {
        $idKaryawan = $this->buatKaryawan('Lapangan');
        DB::table('tbl_users')->where('id_karyawan', $idKaryawan)->update(['status' => 'Nonaktif']);

        $this->ubahLewatForm($idKaryawan, 'Cuti')->assertRedirect(route('hrd.karyawan.index'));
        $this->ubahLewatForm($idKaryawan, 'Tersedia')->assertRedirect(route('hrd.karyawan.index'));

        $this->assertSame('Nonaktif', $this->statusAkun($idKaryawan), 'Hanya kembali dari Nonaktif yang mengaktifkan akun.');
    }

    public function test_internal_hanya_boleh_aktif_cuti_nonaktif(): void
    {
        $idKaryawan = $this->buatKaryawan('Internal');

        $this->ubahLewatForm($idKaryawan, 'Cuti')->assertRedirect(route('hrd.karyawan.index'));
        $this->ubahLewatForm($idKaryawan, 'Tersedia')->assertSessionHasErrors('status');

        $this->assertSame(
            'Status karyawan Internal hanya boleh Aktif, Cuti, atau Nonaktif',
            $this->signalDariUpdateLangsung($idKaryawan, 'Ditempatkan'),
        );
    }

    public function test_lapangan_tidak_boleh_ditempatkan_tanpa_procedure(): void
    {
        $this->assertSame(
            'Status Ditempatkan hanya diatur otomatis saat penempatan dibuat oleh Operasional',
            $this->signalDariUpdateLangsung($this->buatKaryawan('Lapangan'), 'Ditempatkan'),
        );
    }

    public function test_status_lapangan_terkunci_selama_ada_penempatan_aktif(): void
    {
        $idKaryawan = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($idKaryawan, $this->buatKlien(), $this->hariIniDb(-5));

        $this->assertSame(
            'Status tidak dapat diubah karena karyawan masih memiliki penempatan Aktif. Akhiri penempatan terlebih dahulu',
            $this->signalDariUpdateLangsung($idKaryawan, 'Nonaktif'),
        );

        // Form tidak mengirim status (dikunci); perubahan data lain tetap bisa disimpan.
        $this->get(route('hrd.karyawan.edit', $idKaryawan))->assertSee('masih memiliki penempatan Aktif');
        $this->ubahLewatForm($idKaryawan, 'Nonaktif')->assertRedirect(route('hrd.karyawan.index'));
        $this->assertSame('Tersedia', DB::table('tbl_karyawan')->where('id', $idKaryawan)->value('status'));
    }
}

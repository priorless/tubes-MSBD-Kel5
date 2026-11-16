<?php

namespace Tests\Feature\Operasional;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Tombol "Akhiri Penempatan" di Monitoring Operasional (sp_selesai_penempatan).
 * Procedure punya transaksi sendiri; data uji dibersihkan oleh MemakaiDatabaseSji.
 */
class AkhiriPenempatanTest extends TestCase
{
    use MemakaiDatabaseSji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
        $this->loginSebagai(Role::Operasional);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_monitoring_menampilkan_tombol_akhiri(): void
    {
        $idPenempatan = $this->buatPenempatan($this->buatKaryawan('Lapangan'), $this->buatKlien(), $this->hariIniDb(-10));

        $this->get(route('operasional.monitoring'))
            ->assertOk()
            ->assertSee(route('operasional.penempatan.akhiri', $idPenempatan), false)
            ->assertSee('Akhiri');
    }

    public function test_akhiri_penempatan_menutup_penempatan_kontrak_dan_karyawan_tersedia(): void
    {
        $idKaryawan = $this->buatKaryawan('Lapangan');
        $idPenempatan = $this->buatPenempatan($idKaryawan, $this->buatKlien(), $this->hariIniDb(-10));
        DB::table('tbl_karyawan')->where('id', $idKaryawan)->update(['status' => 'Ditempatkan']);
        $tglSelesai = $this->hariIniDb();

        $this->from(route('operasional.monitoring'))
            ->post(route('operasional.penempatan.akhiri', $idPenempatan), ['tgl_selesai' => $tglSelesai])
            ->assertRedirect(route('operasional.monitoring'))
            ->assertSessionHas('success');

        $penempatan = DB::table('tbl_penempatan')->where('id', $idPenempatan)->first();
        $this->assertSame('Selesai', $penempatan->status);
        $this->assertSame($tglSelesai, $penempatan->tgl_selesai);
        $this->assertSame('Selesai', DB::table('tbl_kontrak')->where('id_penempatan', $idPenempatan)->value('status'));
        $this->assertSame('Tersedia', DB::table('tbl_karyawan')->where('id', $idKaryawan)->value('status'));
    }

    public function test_tanggal_sebelum_mulai_ditolak_dengan_pesan_database(): void
    {
        $idPenempatan = $this->buatPenempatan($this->buatKaryawan('Lapangan'), $this->buatKlien(), $this->hariIniDb(-10));

        $this->from(route('operasional.monitoring'))
            ->post(route('operasional.penempatan.akhiri', $idPenempatan), ['tgl_selesai' => $this->hariIniDb(-20)])
            ->assertRedirect(route('operasional.monitoring'))
            ->assertSessionHas('error', 'Operasi ditolak oleh aturan database: Tanggal selesai tidak boleh sebelum tanggal mulai penempatan');

        $this->assertSame('Aktif', DB::table('tbl_penempatan')->where('id', $idPenempatan)->value('status'));
    }

    public function test_penempatan_yang_sudah_selesai_ditolak(): void
    {
        $idPenempatan = $this->buatPenempatan($this->buatKaryawan('Lapangan'), $this->buatKlien(), $this->hariIniDb(-10), $this->hariIniDb(-1), ['status' => 'Selesai']);

        $this->from(route('operasional.monitoring'))
            ->post(route('operasional.penempatan.akhiri', $idPenempatan), ['tgl_selesai' => $this->hariIniDb()])
            ->assertSessionHas('error', 'Operasi ditolak oleh aturan database: Penempatan sudah tidak berstatus Aktif');
    }

    public function test_role_lain_tidak_boleh_mengakhiri_penempatan(): void
    {
        $idPenempatan = $this->buatPenempatan($this->buatKaryawan('Lapangan'), $this->buatKlien(), $this->hariIniDb(-10));
        $this->loginSebagai(Role::Hrd);

        $this->post(route('operasional.penempatan.akhiri', $idPenempatan), ['tgl_selesai' => $this->hariIniDb()])
            ->assertForbidden();
    }
}

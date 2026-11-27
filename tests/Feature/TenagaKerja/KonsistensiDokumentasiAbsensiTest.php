<?php

namespace Tests\Feature\TenagaKerja;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * tbl_laporan_aktivitas.id_karyawan dan tbl_p2h.id_karyawan adalah salinan tbl_absensi.id_karyawan (dipakai indeks
 * riwayat per karyawan). FOREIGN KEY komposit (id_absensi, id_karyawan) menjamin salinan itu selalu sama.
 */
class KonsistensiDokumentasiAbsensiTest extends TestCase
{
    use MemakaiDatabaseSji;

    private int $idPemilik;

    private int $idAbsensi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();

        $this->idPemilik = $this->buatKaryawan('Lapangan');
        $this->idAbsensi = $this->buatAbsensi($this->idPemilik, $this->hariIniDb(-1), 'Hadir', ['jam_masuk' => '08:00:00']);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_laporan_aktivitas_dengan_karyawan_yang_sama_dengan_absensi_diterima(): void
    {
        $this->laporan($this->idPemilik);

        $this->assertSame(1, DB::table('tbl_laporan_aktivitas')->where('id_absensi', $this->idAbsensi)->count());
    }

    public function test_laporan_aktivitas_dengan_karyawan_lain_ditolak(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fk_laporan_absensi_karyawan/');

        $this->laporan($this->buatKaryawan('Lapangan'));
    }

    public function test_p2h_dengan_karyawan_lain_ditolak(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fk_p2h_absensi_karyawan/');

        DB::table('tbl_p2h')->insert(['id_absensi' => $this->idAbsensi, 'id_karyawan' => $this->buatKaryawan('Lapangan')]);
    }

    public function test_pemilik_absensi_tidak_bisa_dipindah_bila_sudah_ada_dokumentasi(): void
    {
        DB::table('tbl_p2h')->insert(['id_absensi' => $this->idAbsensi, 'id_karyawan' => $this->idPemilik]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fk_p2h_absensi_karyawan/');

        DB::table('tbl_absensi')->where('id', $this->idAbsensi)->update(['id_karyawan' => $this->buatKaryawan('Lapangan')]);
    }

    private function laporan(int $idKaryawan): void
    {
        DB::table('tbl_laporan_aktivitas')->insert([
            'id_absensi' => $this->idAbsensi,
            'id_karyawan' => $idKaryawan,
            'jenis' => 'Perjalanan',
            'keterangan' => 'Antar barang',
            'foto_utama' => 'laporan/uji.jpg',
            'waktu_lapor' => now(),
        ]);
    }
}

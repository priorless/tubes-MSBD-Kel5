<?php

namespace Tests\Feature\Operasional;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Trigger trg_cek_jadwal_kerja_insert/_update (Finalisasi Tahap 3) pada db_sji dalam transaksi yang di-rollback.
 */
class AturanJadwalDatabaseTest extends TestCase
{
    use MemakaiDatabaseSji;

    private int $penempatanA;

    private int $penempatanB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();

        $idKaryawan = $this->buatKaryawan('Lapangan');
        $this->penempatanA = $this->buatPenempatan($idKaryawan, $this->buatKlien(), '2099-03-01', '2099-03-31');
        $this->penempatanB = $this->buatPenempatan($idKaryawan, $this->buatKlien(), '2099-03-01');
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    private function jadwal(int $idPenempatan, string $tanggal, string $status = 'Kerja', ?string $masuk = '08:00:00', ?string $pulang = '16:00:00'): int
    {
        return (int) DB::table('tbl_jadwal_kerja')->insertGetId([
            'id_penempatan' => $idPenempatan, 'tanggal_kerja' => $tanggal, 'status' => $status,
            'jam_masuk' => $status === 'Kerja' ? $masuk : null, 'jam_pulang' => $status === 'Kerja' ? $pulang : null,
        ]);
    }

    private function assertDitolak(string $pesan, callable $aksi): void
    {
        try {
            $aksi();
            $this->fail('Seharusnya ditolak: '.$pesan);
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
            $this->assertStringContainsString($pesan, $exception->getMessage());
        }
    }

    public function test_tanggal_di_luar_masa_penempatan_ditolak_saat_insert_dan_update(): void
    {
        $this->assertDitolak('Tanggal jadwal harus berada dalam masa penempatan.', fn () => $this->jadwal($this->penempatanA, '2099-04-01'));

        $id = $this->jadwal($this->penempatanA, '2099-03-10');
        $this->assertDitolak('Tanggal jadwal harus berada dalam masa penempatan.',
            fn () => DB::table('tbl_jadwal_kerja')->where('id', $id)->update(['tanggal_kerja' => '2099-02-28']));
    }

    public function test_jadwal_kerja_tanpa_jam_ditolak_tetapi_libur_boleh_tanpa_jam(): void
    {
        $this->assertDitolak('isi jam masuk dan jam pulang', fn () => $this->jadwal($this->penempatanA, '2099-03-10', 'Kerja', null, null));

        $this->jadwal($this->penempatanA, '2099-03-11', 'Libur');
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id_penempatan' => $this->penempatanA, 'tanggal_kerja' => '2099-03-11', 'status' => 'Libur']);
    }

    public function test_jam_bertabrakan_dengan_penempatan_lain_ditolak(): void
    {
        $this->jadwal($this->penempatanA, '2099-03-10', 'Kerja', '08:00:00', '16:00:00');

        $this->assertDitolak('bertabrakan dengan jadwal Kerja karyawan ini di penempatan lain pada 10-03-2099',
            fn () => $this->jadwal($this->penempatanB, '2099-03-10', 'Kerja', '15:00:00', '23:00:00'));

        $this->jadwal($this->penempatanB, '2099-03-10', 'Kerja', '16:00:00', '23:00:00');
        $this->assertSame(2, DB::table('tbl_jadwal_kerja')->where('tanggal_kerja', '2099-03-10')->whereIn('id_penempatan', [$this->penempatanA, $this->penempatanB])->count(), 'Shift yang bersambung tanpa tumpang tindih boleh');
    }

    public function test_shift_lewat_tengah_malam_diperiksa_dengan_hari_berikutnya(): void
    {
        $this->jadwal($this->penempatanA, '2099-03-10', 'Kerja', '22:00:00', '06:00:00');

        $this->assertDitolak('bertabrakan', fn () => $this->jadwal($this->penempatanB, '2099-03-11', 'Kerja', '05:00:00', '12:00:00'));

        $idPagi = $this->jadwal($this->penempatanB, '2099-03-11', 'Kerja', '07:00:00', '12:00:00');
        $this->assertDitolak('bertabrakan',
            fn () => DB::table('tbl_jadwal_kerja')->where('id', $idPagi)->update(['jam_masuk' => '05:30:00']));
    }

    public function test_mengubah_jadwal_sendiri_di_penempatan_yang_sama_tidak_dianggap_bentrok(): void
    {
        $id = $this->jadwal($this->penempatanA, '2099-03-10', 'Kerja', '08:00:00', '16:00:00');

        DB::table('tbl_jadwal_kerja')->where('id', $id)->update(['jam_masuk' => '09:00:00', 'jam_pulang' => '17:00:00']);

        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $id, 'jam_masuk' => '09:00:00']);
    }
}

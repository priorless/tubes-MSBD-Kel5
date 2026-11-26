<?php

namespace Tests\Feature\Keuangan;

use App\Models\Role;
use App\Services\DatabaseService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Gaji pokok karyawan Lapangan diinput Keuangan di Komponen Gaji (sql/Komponen/19_gaji_pokok_komponen.sql):
 * berlaku sampai diubah, dan dipakai Generate Payroll; tanpa input tetap gaji kontrak penempatan.
 * Periode uji Oktober-November 2099; data uji dihapus di tearDown.
 */
class GajiPokokKomponenTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const int TAHUN = 2099;

    /** @var list<int> */
    private array $karyawanUji = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();

        foreach ([10, 11] as $bulan) {
            $this->catatPeriodeUji($bulan, self::TAHUN);
        }
    }

    protected function tearDown(): void
    {
        // Procedure payroll meng-commit transaksi test, sehingga komponen gaji uji ikut tersimpan.
        if ($this->databaseSjiSiap()) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::table('tbl_komponen_gaji')->whereIn('id_karyawan', $this->karyawanUji)->delete();
        }

        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_gaji_pokok_diinput_keuangan_berlaku_sampai_diubah_dan_dipakai_payroll(): void
    {
        [$klien, $andi, $budi] = $this->duaKaryawanKontrak3200000();
        $this->loginSebagai(Role::Keuangan);
        $filter = ['bulan' => 10, 'tahun' => self::TAHUN, 'penempatan' => 'klien:'.$klien];

        $this->get(route('keuangan.komponen-gaji.index', $filter))->assertOk()
            ->assertSee('name="komponen['.$andi.'][gaji_pokok]"', false)
            ->assertSee('value="3200000"', false)
            ->assertSee('Belum diinput · sementara gaji kontrak');

        // Andi mendapat gaji 4.750.000 dari sekretaris; Budi tetap sama dengan kontrak.
        $this->put(route('keuangan.komponen-gaji.store'), [...$filter, 'komponen' => [
            $andi => ['gaji_pokok' => 4750000, 'tunjangan' => 0, 'potongan_pinjaman' => 0],
            $budi => ['gaji_pokok' => 3200000, 'tunjangan' => 0, 'potongan_pinjaman' => 0],
        ]])->assertSessionHasNoErrors();

        $this->assertEquals(4750000, (float) $this->kolomGaji($andi, 10));
        $this->assertNull($this->kolomGaji($budi, 10), 'Sama dengan gaji yang berlaku: tidak dicatat sebagai perubahan.');

        // November tanpa input baru: gaji Oktober tetap berlaku.
        $this->get(route('keuangan.komponen-gaji.index', [...$filter, 'bulan' => 11]))->assertOk()
            ->assertSee('value="4750000"', false)
            ->assertSee('Berlaku sejak Oktober 2099');

        DatabaseService::callGeneratePayrollBulanan(11, self::TAHUN);
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->assertEquals(4750000, (float) $this->payroll($andi, 11)->gaji_pokok);
        $this->assertEquals(3200000, (float) $this->payroll($budi, 11)->gaji_pokok, 'Tanpa input tetap gaji kontrak.');
    }

    public function test_gaji_pokok_harus_lebih_dari_nol(): void
    {
        [$klien, $andi] = $this->duaKaryawanKontrak3200000();
        $this->loginSebagai(Role::Keuangan);

        $this->put(route('keuangan.komponen-gaji.store'), [
            'bulan' => 10, 'tahun' => self::TAHUN, 'penempatan' => 'klien:'.$klien,
            'komponen' => [$andi => ['gaji_pokok' => 0, 'tunjangan' => 0, 'potongan_pinjaman' => 0]],
        ])->assertSessionHasErrors(['komponen.'.$andi.'.gaji_pokok' => 'Gaji pokok harus lebih dari Rp0.']);

        $this->assertNull($this->kolomGaji($andi, 10));
    }

    /**
     * @return array{int, int, int} [klien, karyawan A, karyawan B], keduanya kontrak 3.200.000 sebulan penuh.
     */
    private function duaKaryawanKontrak3200000(): array
    {
        $klien = $this->buatKlien();
        $andi = $this->buatKaryawan('Lapangan', ['nama' => 'Andi Gaji Uji']);
        $budi = $this->buatKaryawan('Lapangan', ['nama' => 'Budi Gaji Uji']);
        $this->buatPenempatan($andi, $klien, '2099-10-01', null, [], 3200000, 25);
        $this->buatPenempatan($budi, $klien, '2099-10-01', null, [], 3200000, 25);
        $this->karyawanUji = [$andi, $budi];

        return [$klien, $andi, $budi];
    }

    private function kolomGaji(int $idKaryawan, int $bulan): mixed
    {
        return DB::table('tbl_komponen_gaji')->where('id_karyawan', $idKaryawan)
            ->where('periode_bulan', $bulan)->where('periode_tahun', self::TAHUN)->value('gaji_pokok');
    }

    private function payroll(int $idKaryawan, int $bulan): object
    {
        return DB::table('tbl_payroll')->where('id_karyawan', $idKaryawan)
            ->where('periode_bulan', $bulan)->where('periode_tahun', self::TAHUN)->sole();
    }
}

<?php

namespace Tests\Feature\Keuangan;

use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * BPJS default klien (sql/Komponen/14_bpjs_klien.sql) pada db_sji. Periode uji Maret 2099 (31 hari)
 * agar tidak bersinggungan dengan data asli; payroll periode itu dihapus lagi di tearDown.
 *
 * Kontrak uji: gaji 2.500.000 / 25 hari kerja, penempatan sebulan penuh -> hari wajib 25, tarif harian
 * 100.000. Hadir 23 hari -> absen 2 hari -> potongan absen 200.000. BPJS = persentase klien x gaji pokok
 * (2.500.000); klien dengan metode selain persentase tidak dipotong (aturan Komponen Gaji).
 */
class BpjsKlienTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const int BULAN = 3;

    private const int TAHUN = 2099;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
        $this->catatPeriodeUji(self::BULAN, self::TAHUN);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_payroll_memotong_bpjs_persentase_dari_gaji_pokok(): void
    {
        $klien = $this->buatKlien(['bpjs_metode' => 'Persentase', 'bpjs_nilai' => 5]);
        $karyawan = $this->karyawanSebulanPenuh($klien, 23);

        $this->generatePayroll();

        $payroll = $this->payroll($karyawan);
        $this->assertEquals(200000, (float) $payroll->potongan_absen);
        $this->assertEquals(125000, (float) $payroll->potongan_bpjs, '5% x gaji pokok 2.500.000');
        $this->assertEquals(2175000, (float) $payroll->total_gaji);
        $this->assertSame([$klien, 'Persentase', 5.0], [(int) $payroll->id_klien_bpjs, $payroll->bpjs_metode, (float) $payroll->bpjs_nilai]);
    }

    public function test_klien_bpjs_nominal_tidak_dipotong_karena_aturan_hanya_persentase(): void
    {
        $klien = $this->buatKlien(['bpjs_metode' => 'Nominal', 'bpjs_nilai' => 100000]);
        $karyawan = $this->karyawanSebulanPenuh($klien, 23);

        $this->generatePayroll();

        $payroll = $this->payroll($karyawan);
        $this->assertEquals(0, (float) $payroll->potongan_bpjs);
        $this->assertEquals(2300000, (float) $payroll->total_gaji);
        $this->assertSame([$klien, null, null], [(int) $payroll->id_klien_bpjs, $payroll->bpjs_metode, $payroll->bpjs_nilai]);
    }

    public function test_klien_tanpa_bpjs_dan_karyawan_internal_tidak_dipotong(): void
    {
        $klien = $this->buatKlien();
        $lapangan = $this->karyawanSebulanPenuh($klien, 23);
        $internal = $this->buatKaryawan('Internal');

        $this->generatePayroll();

        $payrollLapangan = $this->payroll($lapangan);
        $this->assertEquals(0, (float) $payrollLapangan->potongan_bpjs);
        $this->assertEquals(2300000, (float) $payrollLapangan->total_gaji);
        $this->assertSame([$klien, null], [(int) $payrollLapangan->id_klien_bpjs, $payrollLapangan->bpjs_metode]);

        $payrollInternal = $this->payroll($internal);
        $this->assertEquals(0, (float) $payrollInternal->potongan_bpjs);
        $this->assertNull($payrollInternal->id_klien_bpjs);
    }

    public function test_invoice_tidak_menagih_bpjs_lagi_karena_sudah_termasuk_sub_total_payroll(): void
    {
        $klien = $this->buatKlien(['bpjs_metode' => 'Persentase', 'bpjs_nilai' => 5, 'management_fee' => 10]);
        $this->karyawanSebulanPenuh($klien, 23);
        $this->karyawanSebulanPenuh($klien, 25);
        $this->generatePayroll();

        DatabaseService::callGenerateInvoiceKlien($klien, self::BULAN, self::TAHUN);

        // Sub Total = gaji kotor payroll (2.500.000 - 200.000) + 2.500.000; potongan BPJS ada di dalamnya.
        $invoice = DB::table('tbl_invoice')->where('id_klien', $klien)->sole();
        $this->assertEquals(4800000, (float) $invoice->sub_total);
        $this->assertEquals(0, (float) $invoice->biaya_bpjs, 'BPJS tidak ditagihkan ulang');
        $this->assertEquals(250000, (float) DB::table('tbl_invoice_detail')->where('id_invoice', $invoice->id)->sum('potongan_bpjs'));
        $this->assertEquals(4800000 + 480000 + 52800, (float) $invoice->total_tagihan);
        $this->assertSame(['Persentase', 5.0], [$invoice->bpjs_metode, (float) $invoice->bpjs_nilai]);

        $this->loginSebagai(Role::Keuangan);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('keuangan.invoice.struk', $invoice->id))
            ->assertOk()
            ->assertSeeInOrder(['Sub Total', 'Rp 4.800.000', 'Management Fee (10%)', 'Rp 480.000', 'PPN 11%', 'Rp 52.800', 'Grand Total', 'Rp 5.332.800'], false)
            ->assertDontSee('Biaya BPJS');
    }

    public function test_invoice_ditolak_sebelum_payroll_periode_digenerate(): void
    {
        $klien = $this->buatKlien(['bpjs_metode' => 'Persentase', 'bpjs_nilai' => 5, 'management_fee' => 10]);
        $this->karyawanSebulanPenuh($klien, 23);

        try {
            DatabaseService::callGenerateInvoiceKlien($klien, self::BULAN, self::TAHUN);
            $this->fail('Invoice tanpa payroll seharusnya ditolak.');
        } catch (QueryException $exception) {
            $this->assertSame('Generate payroll periode ini terlebih dahulu: invoice dihitung dari data payroll karyawan klien', $exception->errorInfo[2]);
        }

        $this->assertFalse(DB::table('tbl_invoice')->where('id_klien', $klien)->exists());
    }

    public function test_perubahan_pengaturan_klien_tidak_mengubah_payroll_dan_invoice_yang_sudah_dibuat(): void
    {
        $klien = $this->buatKlien(['bpjs_metode' => 'Persentase', 'bpjs_nilai' => 5, 'management_fee' => 10]);
        $karyawan = $this->karyawanSebulanPenuh($klien, 23);
        $this->generatePayroll();
        DatabaseService::callGenerateInvoiceKlien($klien, self::BULAN, self::TAHUN);
        $idPayroll = $this->payroll($karyawan)->id;

        DB::table('tbl_klien')->where('id', $klien)->update(['bpjs_metode' => 'Nominal', 'bpjs_nilai' => 300000, 'management_fee' => 25]);

        $this->assertDatabaseHas('tbl_payroll', ['id' => $idPayroll, 'bpjs_metode' => 'Persentase', 'potongan_bpjs' => 125000, 'total_gaji' => 2175000]);
        $this->assertDatabaseHas('tbl_invoice', [
            'id_klien' => $klien, 'bpjs_metode' => 'Persentase', 'sub_total' => 2300000, 'persen_fee' => 10,
            'management_fee' => 230000, 'ppn' => 25300, 'total_tagihan' => 2555300,
        ]);

        $this->loginSebagai(Role::Keuangan);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('keuangan.payroll.struk', $idPayroll))
            ->assertOk()
            ->assertSeeInOrder(['Potongan Absensi', 'Potongan BPJS', '5% dari gaji', 'Rp 125.000', 'Total Gaji', 'Rp 2.175.000'], false)
            ->assertDontSee('Rp 300.000');
    }

    public function test_slip_karyawan_menampilkan_potongan_bpjs_dan_aturannya_bukan_persen_sebagai_rupiah(): void
    {
        $klien = $this->buatKlien(['bpjs_metode' => 'Persentase', 'bpjs_nilai' => 5]);
        $karyawan = $this->karyawanSebulanPenuh($klien, 23);
        $this->generatePayroll();
        $akun = User::query()->where('id_karyawan', $karyawan)->firstOrFail();
        DB::table('tbl_users')->where('id', $akun->id)->update(['must_change_password' => false]);

        $this->actingAs($akun->refresh())
            ->get(route('tenaga-kerja.slip-gaji'))
            ->assertOk()
            ->assertSeeText('Potongan BPJS')
            ->assertSeeText('Rp 125.000')
            ->assertSeeText('5% dari gaji')
            ->assertDontSeeText('Rp 5');
    }

    public function test_halaman_invoice_menampilkan_management_fee_klien_saat_dipilih(): void
    {
        $this->buatKlien(['nama_perusahaan' => 'Klien Fee', 'management_fee' => 4.5]);
        $this->buatKlien(['nama_perusahaan' => 'Klien Fee Nol', 'management_fee' => 0]);
        $this->buatKlien(['nama_perusahaan' => 'Klien Tanpa Fee']);
        $this->loginSebagai(Role::Keuangan);

        $this->get(route('keuangan.invoice.index'))
            ->assertOk()
            ->assertSee('data-fee="4,5%"', false)
            ->assertSee('data-fee="0%"', false)
            ->assertSee('data-fee=""', false)
            ->assertSee('id="info_fee_klien"', false);
    }

    public function test_database_menolak_pengaturan_bpjs_klien_yang_tidak_konsisten(): void
    {
        $klien = $this->buatKlien();

        foreach ([
            ['bpjs_metode' => 'Persentase', 'bpjs_nilai' => 150],
            ['bpjs_metode' => 'Nominal', 'bpjs_nilai' => null],
            ['bpjs_metode' => null, 'bpjs_nilai' => 5],
        ] as $pengaturan) {
            try {
                DB::transaction(fn () => DB::table('tbl_klien')->where('id', $klien)->update($pengaturan));
                $this->fail('Pengaturan BPJS tidak konsisten seharusnya ditolak: '.json_encode($pengaturan));
            } catch (QueryException $exception) {
                $this->assertStringContainsString('chk_klien_bpjs', $exception->getMessage());
            }
        }
    }

    /**
     * Karyawan Lapangan dengan penempatan sebulan penuh dan absensi Hadir sebanyak $hariHadir.
     */
    private function karyawanSebulanPenuh(int $idKlien, int $hariHadir): int
    {
        $id = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($id, $idKlien, $this->tgl(1), null, [], 2500000, 25);

        foreach (range(1, $hariHadir) as $hari) {
            $this->buatAbsensi($id, $this->tgl($hari));
        }

        return $id;
    }

    private function tgl(int $hari): string
    {
        return sprintf('%04d-%02d-%02d', self::TAHUN, self::BULAN, $hari);
    }

    private function generatePayroll(): void
    {
        DatabaseService::callGeneratePayrollBulanan(self::BULAN, self::TAHUN);
    }

    private function payroll(int $idKaryawan): object
    {
        return DB::table('tbl_payroll')
            ->where('id_karyawan', $idKaryawan)
            ->where('periode_bulan', self::BULAN)
            ->where('periode_tahun', self::TAHUN)
            ->sole();
    }
}

<?php

namespace Tests\Feature\Keuangan;

use App\Models\Role;
use App\Services\DatabaseService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Payroll & invoice prorata (Tahap 5) pada db_sji. Periode uji Februari 2099 (28 hari) agar tidak
 * bersinggungan dengan data asli; payroll periode itu dihapus lagi di tearDown.
 */
class PayrollInvoiceProrataTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const int BULAN = 2;

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

    private function tgl(int $hari): string
    {
        return sprintf('%04d-%02d-%02d', self::TAHUN, self::BULAN, $hari);
    }

    private function generatePayroll(): void
    {
        DatabaseService::callGeneratePayrollBulanan(self::BULAN, self::TAHUN);
    }

    private function payroll(int $idKaryawan): ?object
    {
        return DB::table('tbl_payroll')
            ->where('id_karyawan', $idKaryawan)
            ->where('periode_bulan', self::BULAN)
            ->where('periode_tahun', self::TAHUN)
            ->first();
    }

    /**
     * @param  list<int>  $hari
     */
    private function absensiBanyak(int $idKaryawan, array $hari, string $status, array $timpa = []): void
    {
        foreach ($hari as $h) {
            $this->buatAbsensi($idKaryawan, $this->tgl($h), $status, $timpa);
        }
    }

    public function test_lapangan_mulai_tengah_bulan_hari_kosong_dan_sakit(): void
    {
        $id = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($id, $this->buatKlien(), $this->tgl(15), null, [], 2500000, 25);

        $this->absensiBanyak($id, range(15, 22), 'Hadir');
        $this->absensiBanyak($id, [23], 'Terlambat');
        $this->absensiBanyak($id, [24, 25], 'Sakit');
        $this->absensiBanyak($id, [26], 'Izin');
        // Tanggal 27 & 28 tanpa absensi sama sekali.

        $this->generatePayroll();
        $p = $this->payroll($id);

        // 14 hari penempatan dari 28 -> hari_wajib ROUND(25 * 14 / 28) = 13, tarif harian 100.000.
        $this->assertSame(13, (int) $p->hari_wajib);
        $this->assertSame(11, (int) $p->hari_masuk, 'Hadir 8 + Terlambat 1 + Sakit 2');
        $this->assertSame(2, (int) $p->hari_absen);
        $this->assertEquals(1300000, (float) $p->gaji_pokok);
        $this->assertEquals(200000, (float) $p->potongan_absen);
        $this->assertEquals(1100000, (float) $p->total_gaji);
    }

    public function test_lembur_payroll_mengikuti_aturan_komponen_gaji(): void
    {
        $id = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($id, $this->buatKlien(), $this->tgl(1), null, [], 2500000, 25);
        $this->absensiBanyak($id, [1, 2, ...range(6, 25)], 'Hadir');
        $checkInOut = function (int $hari, ?string $keluar) use ($id): void {
            $idAbsen = $this->buatAbsensi($id, $this->tgl($hari), 'Hadir', ['jam_masuk' => '08:00:00']);
            if ($keluar !== null) {
                DB::table('tbl_absensi')->where('id', $idAbsen)->update(['jam_keluar' => $keluar]);
            }
        };
        $checkInOut(3, '18:10:00'); // jadwal pulang 16:00 -> 2 jam penuh (1,5x + 2x)
        $checkInOut(4, '16:59:00'); // lewat 59 menit -> belum lembur
        $checkInOut(5, null);       // belum check-out -> tidak dihitung walau jam_lembur terisi
        DB::table('tbl_absensi')->where('id_karyawan', $id)->where('tgl', $this->tgl(5))->update(['jam_lembur' => 4]);

        $this->generatePayroll();
        $p = $this->payroll($id);

        $this->assertSame(25, (int) $p->hari_wajib);
        $this->assertSame(0, (int) $p->hari_absen);
        // 2.500.000 x (1,5 + 2) / 173 = 50.578,03
        $this->assertEquals(50578.03, (float) $p->total_lembur);
        $this->assertEquals(2550578.03, (float) $p->total_gaji);
    }

    public function test_rotasi_tengah_bulan_memakai_kontrak_terbaru_dan_profit_dibagi_per_klien(): void
    {
        $id = $this->buatKaryawan('Lapangan');
        $klienA = $this->buatKlien();
        $klienB = $this->buatKlien();
        $this->buatPenempatan($id, $klienA, $this->tgl(1), $this->tgl(14), ['status' => 'Rotasi'], 2500000, 25);
        $this->buatPenempatan($id, $klienB, $this->tgl(14), null, [], 3000000, 25);
        $this->absensiBanyak($id, range(1, 20), 'Hadir');

        $this->generatePayroll();
        $p = $this->payroll($id);

        // Hari penempatan tidak dihitung ganda: 28 hari -> wajib 25. Tarif dari kontrak terbaru (klien B) 120.000.
        $this->assertSame(25, (int) $p->hari_wajib);
        $this->assertSame(20, (int) $p->hari_masuk);
        $this->assertSame(5, (int) $p->hari_absen);
        $this->assertEquals(3000000, (float) $p->gaji_pokok);
        $this->assertEquals(600000, (float) $p->potongan_absen);
        $this->assertEquals(2400000, (float) $p->total_gaji);

        // Klien A 14 hari (1-14), klien B 15 hari (14-28): gaji dibagi 14/29 dan 15/29, totalnya tepat 100%.
        $profitA = (float) DB::selectOne('SELECT fn_profit_klien(?, ?, ?) AS p', [$klienA, self::BULAN, self::TAHUN])->p;
        $profitB = (float) DB::selectOne('SELECT fn_profit_klien(?, ?, ?) AS p', [$klienB, self::BULAN, self::TAHUN])->p;
        $this->assertEquals(-round(2400000 * 14 / 29, 2), $profitA);
        $this->assertEquals(-round(2400000 * 15 / 29, 2), $profitB);
        $this->assertEquals(-2400000, round($profitA + $profitB, 2));
    }

    public function test_internal_gaji_penuh_dengan_potongan_izin_alpha(): void
    {
        $id = $this->buatKaryawan('Internal', ['gaji_pokok' => 5000000]);
        $this->absensiBanyak($id, [3], 'Izin');
        $this->absensiBanyak($id, [4], 'Alpha');
        $this->absensiBanyak($id, [5], 'Sakit');

        $this->generatePayroll();
        $p = $this->payroll($id);

        $this->assertSame(25, (int) $p->hari_wajib);
        $this->assertNull($p->hari_masuk, 'Staf internal tidak check-in');
        $this->assertSame(2, (int) $p->hari_absen);
        $this->assertEquals(5000000, (float) $p->gaji_pokok);
        $this->assertEquals(400000, (float) $p->potongan_absen);
        $this->assertEquals(4600000, (float) $p->total_gaji);
    }

    public function test_tanpa_kontrak_dilewati_dan_nonaktif_tetap_digaji(): void
    {
        $tanpaKontrak = $this->buatKaryawan('Lapangan');
        DB::table('tbl_penempatan')->insert([
            'id_karyawan' => $tanpaKontrak, 'id_klien' => $this->buatKlien(), 'lokasi_kerja' => 'Tanpa Kontrak',
            'tgl_mulai' => $this->tgl(1), 'status' => 'Aktif',
        ]);

        $nonaktif = $this->buatKaryawan('Lapangan', ['status' => 'Nonaktif']);
        $this->buatPenempatan($nonaktif, $this->buatKlien(), $this->tgl(1), $this->tgl(10), ['status' => 'Selesai'], 2500000, 25);
        $this->absensiBanyak($nonaktif, range(1, 9), 'Hadir');

        $this->generatePayroll();

        $this->assertNull($this->payroll($tanpaKontrak));
        $p = $this->payroll($nonaktif);
        $this->assertNotNull($p, 'Penempatan selesai di tengah bulan tetap digaji walau karyawan Nonaktif');
        $this->assertSame(9, (int) $p->hari_wajib, 'ROUND(25 * 10 / 28) = 9');
        $this->assertSame(0, (int) $p->hari_absen);
        $this->assertEquals(900000, (float) $p->total_gaji);
    }

    public function test_invoice_dari_payroll_prorata_karyawan_masuk_tengah_bulan_dan_rotasi_antar_klien(): void
    {
        $klienA = $this->buatKlien(['management_fee' => 10]);
        $klienB = $this->buatKlien(['management_fee' => 0]);
        $rotasi = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($rotasi, $klienA, $this->tgl(1), $this->tgl(14), ['status' => 'Rotasi'], 2500000, 25);
        $this->buatPenempatan($rotasi, $klienB, $this->tgl(14), null, [], 3000000, 25);
        $this->absensiBanyak($rotasi, range(1, 28), 'Hadir');
        $masukTengahBulan = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($masukTengahBulan, $klienA, $this->tgl(15), null, [], 2500000, 25);
        $this->absensiBanyak($masukTengahBulan, range(15, 28), 'Hadir');

        $this->generatePayroll();
        $payrollSebelum = DB::table('tbl_payroll')->whereIn('id_karyawan', [$rotasi, $masukTengahBulan])->orderBy('id')->get()->toJson();

        DatabaseService::callGenerateInvoiceKlien($klienA, self::BULAN, self::TAHUN);
        DatabaseService::callGenerateInvoiceKlien($klienB, self::BULAN, self::TAHUN);

        $invoiceA = DB::table('tbl_invoice')->where('id_klien', $klienA)->sole();
        $invoiceB = DB::table('tbl_invoice')->where('id_klien', $klienB)->sole();
        $detail = fn (object $invoice, int $karyawan): object => DB::table('tbl_invoice_detail')
            ->where('id_invoice', $invoice->id)->where('id_karyawan', $karyawan)->sole();

        // Masuk tanggal 15: payroll sudah prorata (13 hari wajib x 100.000), seluruhnya ditagihkan ke klien A.
        $this->assertSame([14, 14], [(int) $detail($invoiceA, $masukTengahBulan)->hari_klien, (int) $detail($invoiceA, $masukTengahBulan)->hari_total]);
        $this->assertEquals(1300000, (float) $detail($invoiceA, $masukTengahBulan)->biaya_tenaga_kerja);
        // Rotasi: gaji 3.000.000 dibagi 14/29 (klien A) dan 15/29 (klien B), totalnya tepat gaji payroll.
        $this->assertEquals(1448276, (float) $detail($invoiceA, $rotasi)->biaya_tenaga_kerja);
        $this->assertEquals(1551724, (float) $detail($invoiceB, $rotasi)->biaya_tenaga_kerja);
        $this->assertEquals(3000000, (float) $detail($invoiceA, $rotasi)->biaya_tenaga_kerja + (float) $detail($invoiceB, $rotasi)->biaya_tenaga_kerja);

        $this->assertSame([2, 1], [(int) $invoiceA->jumlah_tenaga, (int) $invoiceB->jumlah_tenaga]);
        $this->assertEquals(2748276, (float) $invoiceA->sub_total);
        $this->assertSame([274828.0, 30231.0, 3053335.0], [(float) $invoiceA->management_fee, (float) $invoiceA->ppn, (float) $invoiceA->total_tagihan]);
        $this->assertSame([0.0, 0.0, 1551724.0], [(float) $invoiceB->management_fee, (float) $invoiceB->ppn, (float) $invoiceB->total_tagihan], 'Fee 0% tetap dipakai');

        $this->assertSame($payrollSebelum, DB::table('tbl_payroll')->whereIn('id_karyawan', [$rotasi, $masukTengahBulan])->orderBy('id')->get()->toJson(), 'Payroll tidak berubah');
    }

    public function test_invoice_tanpa_penempatan_ditolak_dengan_pesan(): void
    {
        try {
            DatabaseService::callGenerateInvoiceKlien($this->buatKlien(['management_fee' => 10]), self::BULAN, self::TAHUN);
            $this->fail('Invoice tanpa penempatan seharusnya ditolak.');
        } catch (QueryException $exception) {
            $this->assertSame('Tidak ada tenaga yang ditempatkan di klien ini pada periode tersebut', $exception->errorInfo[2]);
        }
    }

    public function test_halaman_payroll_menampilkan_rincian_hari(): void
    {
        $id = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($id, $this->buatKlien(), $this->tgl(15), null, [], 2500000, 25);
        $this->absensiBanyak($id, range(15, 25), 'Hadir');
        $this->generatePayroll();
        $idPayroll = $this->payroll($id)->id;

        $this->loginSebagai(Role::Keuangan);

        $this->get(route('keuangan.payroll.index', ['bulan' => self::BULAN, 'tahun' => self::TAHUN]))
            ->assertOk()
            ->assertSee('Hari (Wajib / Hadir / Absen)')
            ->assertSee('13 / 11 / 2');

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('keuangan.payroll.struk', $idPayroll))
            ->assertOk()
            ->assertSee('13 / 11 / 2 hari');
    }
}

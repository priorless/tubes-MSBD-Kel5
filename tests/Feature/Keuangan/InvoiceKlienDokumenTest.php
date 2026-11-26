<?php

namespace Tests\Feature\Keuangan;

use App\Models\Role;
use App\Services\DatabaseService;
use App\Services\InvoiceKlienService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Dokumen tagihan klien (sql/Komponen/17 + InvoiceKlienService + keuangan.invoice.pdf) pada db_sji.
 * Periode uji Mei 2099; payroll periode itu dihapus lagi di tearDown. Payroll dibuat langsung agar
 * nominal uji pasti (perhitungan payroll sendiri diuji di PayrollPph21PencairanTest).
 */
class InvoiceKlienDokumenTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const int BULAN = 5;

    private const int TAHUN = 2099;

    /** @var list<int> */
    private array $karyawanBerjadwal = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
        $this->catatPeriodeUji(self::BULAN, self::TAHUN);
    }

    protected function tearDown(): void
    {
        if ($this->karyawanBerjadwal !== []) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $penempatan = DB::table('tbl_penempatan')->whereIn('id_karyawan', $this->karyawanBerjadwal)->pluck('id');
            DB::table('tbl_pertukaran_shift')->whereIn('id_karyawan_pengaju', $this->karyawanBerjadwal)->delete();
            DB::table('tbl_jadwal_kerja')->whereIn('id_penempatan', $penempatan)->delete();
        }

        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_invoice_ditolak_bila_management_fee_klien_belum_diatur(): void
    {
        $klien = $this->buatKlien();
        $this->karyawanDenganPayroll($klien, 4000000);

        try {
            DatabaseService::callGenerateInvoiceKlien($klien, self::BULAN, self::TAHUN);
            $this->fail('Invoice tanpa management fee seharusnya ditolak.');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Management fee klien belum diatur', $exception->errorInfo[2]);
        }

        $this->assertFalse(DB::table('tbl_invoice')->where('id_klien', $klien)->exists());
    }

    public function test_pdf_tagihan_tiga_halaman_dengan_angka_surat_invoice_dan_lampiran_yang_sama(): void
    {
        $klien = $this->buatKlien(['nama_perusahaan' => 'PT Klien Dokumen', 'management_fee' => 6]);
        $this->karyawanDenganPayroll($klien, 4335198, ['total_lembur' => 173407, 'potongan_pinjaman' => 1000, 'potongan_bpjs' => 173408]);
        $this->karyawanDenganPayroll($klien, 4335198, ['potongan_pinjaman' => 188857, 'potongan_bpjs' => 173408]);
        DatabaseService::callGenerateInvoiceKlien($klien, self::BULAN, self::TAHUN);
        $invoice = DB::table('tbl_invoice')->where('id_klien', $klien)->sole();

        // Sub Total 8.843.803; fee 6% = 530.628; PPN 11% x fee = 58.369
        $this->assertSame([8843803.0, 530628.0, 58369.0, 9432800.0],
            [(float) $invoice->sub_total, (float) $invoice->management_fee, (float) $invoice->ppn, (float) $invoice->total_tagihan]);

        $dokumen = app(InvoiceKlienService::class)->dokumen($invoice->id);
        $this->assertEquals((float) $invoice->sub_total, array_sum(array_column($dokumen['ringkasan'], 'nominal')));
        $this->assertEquals((float) $invoice->sub_total, $dokumen['totalLampiran']['biaya_tenaga_kerja']);
        $this->assertSame('Sembilan Juta Empat Ratus Tiga Puluh Dua Ribu Delapan Ratus Rupiah', $dokumen['terbilang']);

        $html = view('keuangan.invoice.pdf', $dokumen)->render();
        foreach (['Tagihan Jasa Tenaga Kerja Bulan Mei 2099', $dokumen['nomorSurat'], 'SUB TOTAL', '8.843.803', 'MANAGEMENT FEE 6%',
            '530.628', '58.369', '9.432.800', 'INVOICE', 'DAFTAR GAJI', 'DI PT KLIEN DOKUMEN', 'PERIODE: 01 MEI 2099 S/D 31 MEI 2099',
            'BPJS', '10112235428', 'Djoned Djubaidi, S.T., M.H.', 'Gg. Aman No.26', 'Sitirejo II, Kec. Medan Amplas, Kota Medan, Sumatera Utara 20217', 'Tidak ada pertukaran shift pada periode ini.'] as $teks) {
            $this->assertStringContainsString($teks, $html);
        }
        $this->assertSame(3, substr_count($html, 'class="halaman'), 'Tiga bagian dokumen');

        $pdf = Pdf::loadView('keuangan.invoice.pdf', $dokumen)->setPaper('a4');
        $pdf->render();
        $this->assertSame(3, $pdf->getDomPDF()->getCanvas()->get_page_count());

        $this->loginSebagai(Role::Keuangan);
        $respons = $this->get(route('keuangan.invoice.struk', $invoice->id).'?format=pdf');
        $respons->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $respons->getContent());
    }

    public function test_lampiran_banyak_karyawan_berlanjut_ke_halaman_berikut_tanpa_memotong_dokumen(): void
    {
        $klien = $this->buatKlien(['management_fee' => 5]);
        foreach (range(1, 45) as $_) {
            $this->karyawanDenganPayroll($klien, 4000000);
        }
        DatabaseService::callGenerateInvoiceKlien($klien, self::BULAN, self::TAHUN);
        $invoice = DB::table('tbl_invoice')->where('id_klien', $klien)->sole();
        $dokumen = app(InvoiceKlienService::class)->dokumen($invoice->id);

        $pdf = Pdf::loadView('keuangan.invoice.pdf', $dokumen)->setPaper('a4');
        $pdf->render();

        $this->assertSame(45, (int) $invoice->jumlah_tenaga);
        $this->assertGreaterThan(3, $pdf->getDomPDF()->getCanvas()->get_page_count(), 'Lampiran berlanjut, bukan dipotong');
        $this->assertEquals(180000000, $dokumen['totalLampiran']['biaya_tenaga_kerja']);
    }

    public function test_catatan_backup_shift_menampilkan_siapa_menggantikan_siapa_tanpa_biaya_tambahan(): void
    {
        $klien = $this->buatKlien(['management_fee' => 0]);
        [$andi, $penempatanAndi] = $this->karyawanDenganPayroll($klien, 4000000, [], ['nama' => 'Andi Uji']);
        [$budi] = $this->karyawanDenganPayroll($klien, 4000000, [], ['nama' => 'Budi Uji']);
        $this->karyawanBerjadwal = [$andi, $budi];
        $jadwalAndi = $this->jadwal($penempatanAndi, '2099-05-12', '08:00:00', '16:00:00', 'Pagi');
        $idPengajuan = DB::table('tbl_pertukaran_shift')->insertGetId([
            'id_karyawan_pengaju' => $andi, 'id_karyawan_rekan' => $budi, 'id_jadwal_pengaju' => $jadwalAndi,
            'tanggal_kerja' => '2099-05-12', 'alasan' => 'Uji catatan',
            'snapshot_pengaju' => json_encode(['status' => 'Kerja', 'jam_masuk' => '08:00:00', 'jam_pulang' => '16:00:00', 'nama_shift_snapshot' => 'Pagi']),
        ]);
        DB::table('tbl_pertukaran_shift')->where('id', $idPengajuan)->update([
            'status' => 'Disetujui', 'status_rekan' => 'Diterima', 'ditanggapi_rekan_pada' => now(),
            'diproses_oleh' => $this->loginSebagai(Role::Operasional)->id, 'diproses_pada' => now(),
        ]);

        DatabaseService::callGenerateInvoiceKlien($klien, self::BULAN, self::TAHUN);
        $invoice = DB::table('tbl_invoice')->where('id_klien', $klien)->sole();
        $dokumen = app(InvoiceKlienService::class)->dokumen($invoice->id);

        $this->assertSame([
            ['tanggal' => '12 Mei 2099', 'teks' => 'Budi Uji menggantikan Andi Uji (Pagi · 08:00–16:00)'],
        ], $dokumen['catatanTukarShift']);
        $this->assertEquals(8000000, (float) $invoice->total_tagihan, 'Backup shift tidak menambah tagihan');
        $this->assertStringContainsString('Budi Uji menggantikan Andi Uji', view('keuangan.invoice.pdf', $dokumen)->render());
    }

    public function test_invoice_lama_tanpa_rincian_tidak_bisa_diunduh_sebagai_pdf(): void
    {
        $klien = $this->buatKlien();
        $id = DB::table('tbl_invoice')->insertGetId([
            'nomor_invoice' => 'INV-TSTX-LAMA', 'id_klien' => $klien, 'periode_bulan' => self::BULAN,
            'periode_tahun' => self::TAHUN, 'jumlah_tenaga' => 1, 'total_tagihan' => 2000000,
        ]);
        $this->loginSebagai(Role::Keuangan);

        $this->get(route('keuangan.invoice.struk', $id).'?format=pdf')
            ->assertRedirect(route('keuangan.invoice.index'))
            ->assertSessionHas('error');
    }

    /**
     * Karyawan Lapangan dengan penempatan sebulan penuh di klien dan payroll periode uji (dibuat langsung).
     *
     * @param  array<string, mixed>  $payroll
     * @param  array<string, mixed>  $karyawan
     * @return array{int, int}
     */
    private function karyawanDenganPayroll(int $idKlien, float $gaji, array $payroll = [], array $karyawan = []): array
    {
        $id = $this->buatKaryawan('Lapangan', $karyawan);
        $idPenempatan = $this->buatPenempatan($id, $idKlien, '2099-05-01', null, [], $gaji, 25);
        $data = ['gaji_pokok' => $gaji, 'total_lembur' => 0, 'potongan_absen' => 0, ...$payroll];
        $data['total_gaji'] = $data['gaji_pokok'] + $data['total_lembur'] - $data['potongan_absen']
            - ($data['potongan_pinjaman'] ?? 0) - ($data['potongan_bpjs'] ?? 0);
        DB::table('tbl_payroll')->insert(['id_karyawan' => $id, 'periode_bulan' => self::BULAN, 'periode_tahun' => self::TAHUN, ...$data]);

        return [$id, $idPenempatan];
    }

    private function jadwal(int $idPenempatan, string $tanggal, string $masuk, string $pulang, string $shift): int
    {
        return DB::table('tbl_jadwal_kerja')->insertGetId([
            'id_penempatan' => $idPenempatan, 'tanggal_kerja' => $tanggal, 'status' => 'Kerja',
            'jam_masuk' => $masuk, 'jam_pulang' => $pulang, 'durasi_menit' => 480, 'nama_shift_snapshot' => $shift,
        ]);
    }
}

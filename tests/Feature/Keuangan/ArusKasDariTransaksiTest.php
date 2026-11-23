<?php

namespace Tests\Feature\Keuangan;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * v_arus_kas_bulanan dan profit_bulan_berjalan (v_dashboard_eksekutif) dari sql/Komponen/21_arus_kas_dari_transaksi.sql:
 * pemasukan = invoice Lunas (tgl_pembayaran), pengeluaran = payroll Sudah Lunas (total_gaji, tgl_bayar),
 * ditambah baris tbl_mutasi_keuangan. Data uji di dalam transaksi MemakaiDatabaseSji sehingga di-rollback.
 */
class ArusKasDariTransaksiTest extends TestCase
{
    use MemakaiDatabaseSji;

    private int $idKlien;

    private int $idKaryawan;

    /** Periode tagihan/gaji berbeda untuk tiap baris uji (unik per klien/karyawan per periode). */
    private int $periodeBerikut = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
        $this->idKlien = $this->buatKlien();
        $this->idKaryawan = $this->buatKaryawan('Lapangan');
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    /**
     * @return array{int, int} [bulan, tahun] periode uji yang belum dipakai (tahun 2097, sudah dicatat untuk dibersihkan)
     */
    private function periodeUji(): array
    {
        $bulan = ++$this->periodeBerikut;
        $this->catatPeriodeUji($bulan, 2097);

        return [$bulan, 2097];
    }

    private function buatInvoice(string $status, ?string $tglPembayaran, float $total): void
    {
        [$bulan, $tahun] = $this->periodeUji();

        DB::table('tbl_invoice')->insert([
            'nomor_invoice' => 'UJI-'.Str::upper(Str::random(10)),
            'id_klien' => $this->idKlien,
            'periode_bulan' => $bulan,
            'periode_tahun' => $tahun,
            'jumlah_tenaga' => 1,
            'total_tagihan' => $total,
            'status' => $status,
            'tgl_pembayaran' => $tglPembayaran,
        ]);
    }

    private function buatPayroll(string $statusBayar, ?string $tglBayar, float $totalGaji): void
    {
        [$bulan, $tahun] = $this->periodeUji();

        DB::table('tbl_payroll')->insert([
            'id_karyawan' => $this->idKaryawan,
            'periode_bulan' => $bulan,
            'periode_tahun' => $tahun,
            'gaji_pokok' => $totalGaji,
            'total_gaji' => $totalGaji,
            'status_bayar' => $statusBayar,
            'tgl_bayar' => $tglBayar,
        ]);
    }

    private function buatMutasi(string $jenis, string $tgl, float $nominal): void
    {
        DB::table('tbl_mutasi_keuangan')->insert([
            'tgl_mutasi' => $tgl,
            'jenis' => $jenis,
            'referensi' => 'UJI',
            'keterangan' => 'Mutasi lama uji arus kas',
            'nominal' => $nominal,
        ]);
    }

    public function test_arus_kas_bulanan_dari_invoice_lunas_payroll_lunas_dan_mutasi_lama(): void
    {
        $this->buatInvoice('Lunas', '2099-03-10', 10000000);
        $this->buatInvoice('Belum Lunas', null, 7000000);
        $this->buatPayroll('Sudah Lunas', '2099-03-05', 3000000);
        $this->buatPayroll('Belum Dibayar', null, 2500000);
        $this->buatMutasi('Pemasukan', '2099-03-20', 500000);
        $this->buatMutasi('Pengeluaran', '2099-03-21', 200000);

        $kas = DB::table('v_arus_kas_bulanan')->where('tahun', 2099)->where('bulan', 3)->sole();

        $this->assertEqualsWithDelta(10500000, (float) $kas->total_masuk, 0.001);
        $this->assertEqualsWithDelta(3200000, (float) $kas->total_keluar, 0.001);
        $this->assertEqualsWithDelta(7300000, (float) $kas->selisih, 0.001);
        $this->assertSame(0, DB::table('v_arus_kas_bulanan')->where('tahun', 2099)->where('bulan', 2)->count());
    }

    public function test_profit_bulan_berjalan_ikut_invoice_dan_payroll_yang_dibayar_bulan_ini(): void
    {
        $hariIni = $this->hariIniDb();
        $profitAwal = (float) DB::table('v_dashboard_eksekutif')->value('profit_bulan_berjalan');

        $this->buatInvoice('Lunas', $hariIni, 8000000);
        $this->buatInvoice('Lunas', '2000-01-15', 9999999);
        $this->buatPayroll('Sudah Lunas', $hariIni, 2500000);
        $this->buatPayroll('Belum Dibayar', null, 1111111);
        $this->buatMutasi('Pengeluaran', $hariIni, 300000);

        $profit = (float) DB::table('v_dashboard_eksekutif')->value('profit_bulan_berjalan');

        $this->assertEqualsWithDelta($profitAwal + 8000000 - 2500000 - 300000, $profit, 0.001);
    }
}

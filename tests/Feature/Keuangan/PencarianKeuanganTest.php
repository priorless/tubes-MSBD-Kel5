<?php

namespace Tests\Feature\Keuangan;

use App\Models\Role;
use App\Services\DatabaseService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Pencarian di halaman Keuangan: Payroll & Komponen Gaji (NIP / nama karyawan), Invoice Klien (kode / nama klien).
 * Periode uji tahun 2099; data uji dihapus di tearDown.
 */
class PencarianKeuanganTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const int TAHUN = 2099;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();

        foreach ([9, 10] as $bulan) {
            $this->catatPeriodeUji($bulan, self::TAHUN);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_payroll_dicari_berdasarkan_nip_atau_nama_karyawan(): void
    {
        $siti = $this->buatKaryawan('Internal', ['nama' => 'Siti Pencarian Uji']);
        $budi = $this->buatKaryawan('Internal', ['nama' => 'Budi Pencarian Uji']);
        foreach ([$siti, $budi] as $id) {
            DB::table('tbl_payroll')->insert([
                'id_karyawan' => $id, 'periode_bulan' => 9, 'periode_tahun' => self::TAHUN,
                'gaji_pokok' => 5000000, 'total_lembur' => 0, 'potongan_absen' => 0, 'total_gaji' => 5000000,
            ]);
        }
        $nipBudi = DB::table('tbl_karyawan')->where('id', $budi)->value('nip');
        $this->loginSebagai(Role::Keuangan);
        $halaman = fn (string $cari) => $this->get(route('keuangan.payroll.index', ['bulan' => 9, 'tahun' => self::TAHUN, 'q' => $cari]));

        $halaman('siti pencarian')->assertOk()->assertSee('Siti Pencarian Uji')->assertDontSee('Budi Pencarian Uji')
            ->assertSee('cocok dengan');
        $halaman($nipBudi)->assertOk()->assertSee('Budi Pencarian Uji')->assertDontSee('Siti Pencarian Uji');
        // Karakter khusus LIKE dicari apa adanya, bukan sebagai wildcard.
        $halaman('%')->assertOk()->assertDontSee('Siti Pencarian Uji')->assertSee('Tidak ada karyawan dengan NIP atau nama');
    }

    public function test_komponen_gaji_dicari_berdasarkan_nip_atau_nama_karyawan(): void
    {
        $klien = $this->buatKlien();
        $rina = $this->buatKaryawan('Lapangan', ['nama' => 'Rina Komponen Uji']);
        $joko = $this->buatKaryawan('Lapangan', ['nama' => 'Joko Komponen Uji']);
        $this->buatPenempatan($rina, $klien, '2099-10-01');
        $this->buatPenempatan($joko, $klien, '2099-10-01');
        $this->loginSebagai(Role::Keuangan);
        $filter = ['bulan' => 10, 'tahun' => self::TAHUN, 'penempatan' => 'klien:'.$klien];

        $this->get(route('keuangan.komponen-gaji.index', [...$filter, 'q' => 'JOKO']))
            ->assertOk()->assertSee('Joko Komponen Uji')->assertDontSee('Rina Komponen Uji')
            ->assertSee('komponen['.$joko.'][tunjangan]', false)
            ->assertDontSee('komponen['.$rina.'][tunjangan]', false);

        // Simpan hasil pencarian hanya mengubah karyawan yang tampil dan kembali ke pencarian yang sama.
        $this->put(route('keuangan.komponen-gaji.store'), [...$filter, 'q' => 'JOKO',
            'komponen' => [$joko => ['tunjangan' => 75000, 'potongan_pinjaman' => 0]]])
            ->assertRedirect(route('keuangan.komponen-gaji.index', [...$filter, 'q' => 'JOKO']));
        $this->assertDatabaseHas('tbl_komponen_gaji', ['id_karyawan' => $joko, 'periode_bulan' => 10, 'tunjangan' => 75000]);
        $this->assertDatabaseMissing('tbl_komponen_gaji', ['id_karyawan' => $rina, 'periode_bulan' => 10]);
        DB::table('tbl_komponen_gaji')->where('id_karyawan', $joko)->delete();
    }

    public function test_invoice_dicari_berdasarkan_kode_atau_nama_klien(): void
    {
        $this->invoiceBaru('PT Sinar Pencarian Uji');
        $bumi = $this->invoiceBaru('PT Bumi Pencarian Uji');
        $kodeBumi = DB::table('tbl_klien')->where('id', $bumi)->value('kode_klien');
        $this->loginSebagai(Role::Keuangan);

        // Nama klien juga ada di pilihan form Generate; yang diperiksa adalah baris daftar invoice.
        $baris = fn (string $nama): string => 'data-lunas-klien="'.$nama.'"';
        $this->get(route('keuangan.invoice.index', ['q' => 'sinar pencarian']))
            ->assertOk()->assertSee($baris('PT Sinar Pencarian Uji'), false)->assertDontSee($baris('PT Bumi Pencarian Uji'), false);
        $this->get(route('keuangan.invoice.index', ['q' => $kodeBumi]))
            ->assertOk()->assertSee($baris('PT Bumi Pencarian Uji'), false)->assertDontSee($baris('PT Sinar Pencarian Uji'), false);
        $this->get(route('keuangan.invoice.index', ['q' => 'klien yang tidak ada']))
            ->assertOk()->assertSee('Tidak ada invoice untuk');
    }

    /**
     * Invoice lewat procedure aslinya untuk satu klien baru; mengembalikan id klien.
     */
    private function invoiceBaru(string $namaKlien): int
    {
        $klien = $this->buatKlien(['nama_perusahaan' => $namaKlien, 'management_fee' => 5]);
        $karyawan = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($karyawan, $klien, '2099-09-01', null, [], 4000000, 25);
        DB::table('tbl_payroll')->insert([
            'id_karyawan' => $karyawan, 'periode_bulan' => 9, 'periode_tahun' => self::TAHUN,
            'gaji_pokok' => 4000000, 'total_lembur' => 0, 'potongan_absen' => 0, 'total_gaji' => 4000000,
        ]);
        DatabaseService::callGenerateInvoiceKlien($klien, 9, self::TAHUN);

        // Procedure invoice sudah meng-commit transaksi test; samakan status transaksi Laravel dengan aplikasi.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        return $klien;
    }
}

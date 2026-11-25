<?php

namespace Tests\Feature\Keuangan;

use App\Models\Role;
use App\Services\DatabaseService;
use App\Services\InvoiceKlienService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Mode edit dokumen tagihan (teks surat & catatan manual Admin Keuangan) pada db_sji.
 * Periode uji Juni 2099; invoice & payroll uji dihapus lagi di tearDown (data dokumen ikut terhapus via FK cascade).
 */
class InvoiceDokumenEditTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const int BULAN = 6;

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

    public function test_admin_keuangan_menyimpan_teks_dan_catatan_hanya_untuk_invoice_itu_dengan_riwayat_audit(): void
    {
        $invoice = $this->invoiceBaru('PT Edit Satu');
        $lain = $this->invoiceBaru('PT Edit Lain');
        $nominalSebelum = $this->nominal($invoice->id);
        $user = $this->loginSebagai(Role::Keuangan);

        $this->put(route('keuangan.invoice.dokumen', $invoice->id), [
            ...$this->isianBawaan($invoice, 0),
            'hal' => 'Tagihan Jasa Pengamanan Bulan Juni 2099',
            'paragraf_penutup' => 'Mohon pembayaran paling lambat tanggal 10.',
            'catatan' => [['isi' => 'Budi menggantikan Andi pada 3 Juni (izin sakit).'], ['isi' => '  ']],
            // Nominal yang ikut dikirim harus diabaikan.
            'total_tagihan' => 1, 'sub_total' => 1, 'management_fee' => 1, 'ppn' => 1,
        ])->assertRedirect(route('keuangan.invoice.index', ['struk' => $invoice->id]))
            ->assertSessionHas('success', 'Perubahan dokumen invoice berhasil disimpan.');

        $this->assertSame($nominalSebelum, $this->nominal($invoice->id), 'Nominal tidak berubah');
        $this->assertDatabaseHas('tbl_invoice_dokumen', [
            'id_invoice' => $invoice->id, 'hal' => 'Tagihan Jasa Pengamanan Bulan Juni 2099', 'penerima' => null,
            'paragraf_penutup' => 'Mohon pembayaran paling lambat tanggal 10.', 'versi' => 1, 'diubah_oleh' => $user->id,
        ]);
        $this->assertSame(['Budi menggantikan Andi pada 3 Juni (izin sakit).'],
            DB::table('tbl_invoice_catatan')->where('id_invoice', $invoice->id)->pluck('isi')->all());
        $this->assertDatabaseHas('tbl_invoice_dokumen_riwayat', ['id_invoice' => $invoice->id, 'versi' => 1, 'diubah_oleh' => $user->id]);
        $this->assertFalse(DB::table('tbl_invoice_dokumen')->where('id_invoice', $lain->id)->exists(), 'Invoice lain tidak terpengaruh');

        // Preview & PDF memakai versi tersimpan; catatan manual terpisah dari catatan otomatis Operasional.
        $dokumen = app(InvoiceKlienService::class)->dokumen($invoice->id);
        $html = view('keuangan.invoice.pdf', $dokumen)->render();
        $this->assertStringContainsString('Tagihan Jasa Pengamanan Bulan Juni 2099', $html);
        $this->assertStringContainsString('Mohon pembayaran paling lambat tanggal 10.', $html);
        $this->assertStringContainsString('Catatan tambahan Keuangan:', $html);
        $this->assertStringContainsString('Tidak ada pertukaran shift pada periode ini.', $html);
        $this->assertStringContainsString('Tagihan Jasa Tenaga Kerja Bulan Juni 2099', view('keuangan.invoice.pdf', app(InvoiceKlienService::class)->dokumen($lain->id))->render());

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route('keuangan.invoice.struk', $invoice->id))
            ->assertOk()->assertSee('Edit Dokumen')->assertSee('Versi 1')->assertSee('name="versi" value="1"', false);
    }

    public function test_ubah_dan_hapus_catatan_serta_kosongkan_teks_kembali_ke_bawaan(): void
    {
        $invoice = $this->invoiceBaru('PT Edit Dua');
        $this->loginSebagai(Role::Keuangan);
        $this->put(route('keuangan.invoice.dokumen', $invoice->id), [
            ...$this->isianBawaan($invoice, 0), 'hal' => 'Hal khusus',
            'catatan' => [['isi' => 'Catatan A'], ['isi' => 'Catatan B']],
        ]);
        [$idA, $idB] = DB::table('tbl_invoice_catatan')->where('id_invoice', $invoice->id)->orderBy('id')->pluck('id')->all();

        $this->put(route('keuangan.invoice.dokumen', $invoice->id), [
            ...$this->isianBawaan($invoice, 1), 'hal' => '',
            'catatan' => [['id' => $idA, 'isi' => 'Catatan A (revisi)']],
        ])->assertSessionHas('success');

        $this->assertSame([[(int) $idA, 'Catatan A (revisi)']],
            DB::table('tbl_invoice_catatan')->where('id_invoice', $invoice->id)->get()->map(fn ($c) => [(int) $c->id, $c->isi])->all());
        $this->assertFalse(DB::table('tbl_invoice_catatan')->where('id', $idB)->exists());
        $this->assertNull(DB::table('tbl_invoice_dokumen')->where('id_invoice', $invoice->id)->value('hal'));
        $this->assertSame('Tagihan Jasa Tenaga Kerja Bulan Juni 2099', app(InvoiceKlienService::class)->dokumen($invoice->id)['teksSurat']['hal']);
        $this->assertSame(2, DB::table('tbl_invoice_dokumen_riwayat')->where('id_invoice', $invoice->id)->count());

        // Tanpa perubahan: versi & riwayat tetap.
        $this->put(route('keuangan.invoice.dokumen', $invoice->id), [
            ...$this->isianBawaan($invoice, 2), 'catatan' => [['id' => $idA, 'isi' => 'Catatan A (revisi)']],
        ])->assertSessionHas('success', 'Tidak ada perubahan pada dokumen invoice.');
        $this->assertSame(2, DB::table('tbl_invoice_dokumen_riwayat')->where('id_invoice', $invoice->id)->count());
    }

    public function test_penyimpanan_ditolak_untuk_role_lain_invoice_tertukar_versi_lama_catatan_asing_dan_invoice_lunas(): void
    {
        $invoice = $this->invoiceBaru('PT Edit Tiga');
        $lain = $this->invoiceBaru('PT Edit Empat');

        $this->loginSebagai(Role::Operasional);
        $this->put(route('keuangan.invoice.dokumen', $invoice->id), $this->isianBawaan($invoice, 0))->assertForbidden();

        $this->loginSebagai(Role::Keuangan);
        // id_invoice di form berbeda dengan URL.
        $this->put(route('keuangan.invoice.dokumen', $invoice->id), [...$this->isianBawaan($invoice, 0), 'id_invoice' => $lain->id, 'hal' => 'X'])
            ->assertSessionHasErrors('id_invoice');

        $this->put(route('keuangan.invoice.dokumen', $lain->id), [...$this->isianBawaan($lain, 0), 'catatan' => [['isi' => 'Milik invoice lain']]]);
        $idCatatanLain = (int) DB::table('tbl_invoice_catatan')->where('id_invoice', $lain->id)->value('id');

        // Catatan milik invoice lain.
        $this->put(route('keuangan.invoice.dokumen', $invoice->id), [...$this->isianBawaan($invoice, 0), 'catatan' => [['id' => $idCatatanLain, 'isi' => 'Bajak']]])
            ->assertSessionHas('error', 'Catatan yang dikirim bukan milik invoice ini. Muat ulang halaman lalu coba lagi.');
        $this->assertSame('Milik invoice lain', DB::table('tbl_invoice_catatan')->where('id', $idCatatanLain)->value('isi'));

        // Versi lama (sudah diubah pengguna lain).
        $this->put(route('keuangan.invoice.dokumen', $lain->id), [...$this->isianBawaan($lain, 0), 'hal' => 'Menimpa'])
            ->assertSessionHas('error', 'Dokumen invoice ini sudah diubah oleh pengguna lain. Muat ulang lalu ulangi perubahan Anda.');

        DatabaseService::callUpdateStatusBayar('INVOICE', $invoice->id, '2099-06-30');
        $this->put(route('keuangan.invoice.dokumen', $invoice->id), [...$this->isianBawaan($invoice, 0), 'hal' => 'Setelah lunas'])
            ->assertSessionHas('error', 'Invoice yang sudah lunas tidak dapat diedit.');
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route('keuangan.invoice.struk', $invoice->id))
            ->assertOk()->assertDontSee('Edit Dokumen')->assertSee('dokumen terkunci');

        $this->assertFalse(DB::table('tbl_invoice_dokumen')->where('id_invoice', $invoice->id)->exists());
    }

    private function invoiceBaru(string $namaKlien): object
    {
        $klien = $this->buatKlien(['nama_perusahaan' => $namaKlien, 'management_fee' => 5]);
        $id = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($id, $klien, '2099-06-01', null, [], 4000000, 25);
        DB::table('tbl_payroll')->insert([
            'id_karyawan' => $id, 'periode_bulan' => self::BULAN, 'periode_tahun' => self::TAHUN,
            'gaji_pokok' => 4000000, 'total_lembur' => 0, 'potongan_absen' => 0, 'total_gaji' => 4000000,
        ]);
        DatabaseService::callGenerateInvoiceKlien($klien, self::BULAN, self::TAHUN);

        // Procedure invoice sudah meng-commit transaksi test; samakan status transaksi Laravel dengan aplikasi
        // (tanpa transaksi luar) agar rollback transaksi simpan dokumen tidak mencari savepoint yang hilang.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        return DB::table('tbl_invoice')->where('id_klien', $klien)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function isianBawaan(object $invoice, int $versi): array
    {
        return ['id_invoice' => $invoice->id, 'versi' => $versi];
    }

    /**
     * @return array<string, string>
     */
    private function nominal(int $idInvoice): array
    {
        return (array) DB::table('tbl_invoice')->where('id', $idInvoice)
            ->first(['sub_total', 'persen_fee', 'management_fee', 'ppn', 'total_tagihan', 'jumlah_tenaga']);
    }
}

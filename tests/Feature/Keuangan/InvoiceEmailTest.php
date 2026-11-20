<?php

namespace Tests\Feature\Keuangan;

use App\Mail\InvoiceKlienMail;
use App\Models\Role;
use App\Services\DatabaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Kirim invoice klien via email (KeuanganController::kirimInvoiceEmail) pada db_sji, tanpa email sungguhan (Mail::fake).
 * Periode uji Juli 2099; data uji dihapus di tearDown.
 */
class InvoiceEmailTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const int BULAN = 7;

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

    public function test_halaman_invoice_menampilkan_tombol_kirim_email_dengan_isian_awal_dari_klien(): void
    {
        $invoice = $this->invoiceBaru();
        $this->loginSebagai(Role::Keuangan);

        $this->get(route('keuangan.invoice.index'))
            ->assertOk()
            ->assertSee('Kirim via Email')
            ->assertSee('data-email-url="'.route('keuangan.invoice.email', $invoice->id).'"', false)
            ->assertSee('data-email-tujuan="pic.uji@klien-email.test"', false)
            ->assertSee('data-email-subjek="Tagihan Invoice Jasa PT Satu Jaya Indonesia - PT Klien Email - Periode Juli 2099"', false)
            ->assertSee('Dengan hormat,')
            ->assertSee('id="emailModal"', false);
    }

    public function test_isi_email_bawaan_sesuai_data_invoice_dan_pdf_terlampir_tiga_halaman(): void
    {
        Mail::fake();
        $invoice = $this->invoiceBaru();
        $this->loginSebagai(Role::Keuangan);

        // Isi pesan bawaan memuat data invoice dari database.
        $halaman = $this->get(route('keuangan.invoice.index'))->assertOk()->getContent();
        foreach (['Yth. Bapak/Ibu PIC Uji Email', 'nomor invoice '.$invoice->nomor_invoice, 'Juli 2099', 'Rp 4.222.000',
            'Empat Juta Dua Ratus Dua Puluh Dua Ribu Rupiah', '10112235428'] as $teks) {
            $this->assertStringContainsString($teks, $halaman);
        }

        $this->post(route('keuangan.invoice.email', $invoice->id), $this->isian($invoice))->assertSessionHas('success');

        Mail::assertSent(InvoiceKlienMail::class, function (InvoiceKlienMail $mail) use ($invoice): bool {
            $isiPdf = $mail->attachments()[0]->attachWith(fn () => null, fn ($data) => $data());
            $jumlahHalaman = preg_match_all('#/Type\s*/Page[^s]#', $isiPdf);
            $html = $mail->render();

            return $jumlahHalaman === 3
                && $mail->nomorInvoice === $invoice->nomor_invoice
                && str_contains($html, 'tagihan-'.$invoice->nomor_invoice.'.pdf')
                && str_contains($html, 'Mohon cek tagihan terlampir.');
        });
    }

    public function test_invoice_tidak_ditemukan_menghasilkan_404(): void
    {
        Mail::fake();
        $this->loginSebagai(Role::Keuangan);
        $idTidakAda = (int) DB::table('tbl_invoice')->max('id') + 1000;

        $this->post(route('keuangan.invoice.email', $idTidakAda), $this->isian((object) ['id' => $idTidakAda]))->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_pengguna_belum_login_tidak_dapat_mengirim(): void
    {
        Mail::fake();
        $invoice = $this->invoiceBaru();

        $this->post(route('keuangan.invoice.email', $invoice->id), $this->isian($invoice))->assertRedirect(route('login'));
        Mail::assertNothingSent();
    }

    public function test_email_terkirim_ke_tujuan_dan_cc_dengan_lampiran_pdf_dari_database(): void
    {
        Mail::fake();
        $invoice = $this->invoiceBaru();
        $this->loginSebagai(Role::Keuangan);

        $this->post(route('keuangan.invoice.email', $invoice->id), [
            ...$this->isian($invoice),
            'cc' => 'direktur@klien-email.test; keuangan@klien-email.test',
            'total_tagihan' => 1, 'sub_total' => 1,
        ])->assertRedirect(route('keuangan.invoice.index'))
            ->assertSessionHas('success', 'Invoice '.$invoice->nomor_invoice.' berhasil dikirim ke pic.uji@klien-email.test (CC 2 alamat).');

        Mail::assertSent(InvoiceKlienMail::class, function (InvoiceKlienMail $mail) use ($invoice): bool {
            $lampiran = $mail->attachments();
            $isiPdf = $lampiran[0]->attachWith(fn () => null, fn ($data) => $data());

            return $mail->hasTo('pic.uji@klien-email.test')
                && $mail->hasCc('direktur@klien-email.test')
                && $mail->hasCc('keuangan@klien-email.test')
                && $mail->subjek === 'Tagihan Invoice Jasa PT Satu Jaya Indonesia - PT Klien Email - Periode Juli 2099'
                && count($lampiran) === 1
                && $lampiran[0]->as === 'tagihan-'.$invoice->nomor_invoice.'.pdf'
                && $lampiran[0]->mime === 'application/pdf'
                && str_starts_with($isiPdf, '%PDF')
                && str_contains($mail->render(), 'Mohon cek tagihan terlampir.');
        });
        Mail::assertSentCount(1);
        $this->assertEquals((float) $invoice->total_tagihan, (float) DB::table('tbl_invoice')->where('id', $invoice->id)->value('total_tagihan'), 'Nominal tidak berubah');
        $this->assertEquals(4222000, (float) $invoice->total_tagihan, '4.000.000 + fee 5% 200.000 + PPN 22.000');
    }

    public function test_validasi_email_dan_cc_serta_invoice_tertukar(): void
    {
        Mail::fake();
        $invoice = $this->invoiceBaru();
        $this->loginSebagai(Role::Keuangan);
        $url = route('keuangan.invoice.email', $invoice->id);

        $this->post($url, [...$this->isian($invoice), 'email_tujuan' => 'bukan-email'])->assertSessionHasErrors('email_tujuan');
        $this->post($url, [...$this->isian($invoice), 'cc' => 'benar@klien-email.test, salah@'])->assertSessionHasErrors('daftar_cc.1');
        $this->post($url, [...$this->isian($invoice), 'cc' => implode(',', array_map(fn (int $i) => "cc{$i}@klien-email.test", range(1, 11)))])
            ->assertSessionHasErrors('daftar_cc');
        $this->post($url, [...$this->isian($invoice), 'cc' => 'pic.uji@klien-email.test'])->assertSessionHasErrors('daftar_cc.0');
        $this->post($url, [...$this->isian($invoice), 'subjek' => '', 'pesan' => ''])->assertSessionHasErrors(['subjek', 'pesan']);
        $this->post($url, [...$this->isian($invoice), 'id_invoice' => $invoice->id + 1])->assertSessionHasErrors('id_invoice');

        Mail::assertNothingSent();
    }

    public function test_hanya_admin_keuangan_yang_dapat_mengirim(): void
    {
        Mail::fake();
        $invoice = $this->invoiceBaru();
        $this->loginSebagai(Role::Operasional);

        $this->post(route('keuangan.invoice.email', $invoice->id), $this->isian($invoice))->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_kegagalan_pengiriman_ditangani_tanpa_menampilkan_detail_smtp(): void
    {
        $invoice = $this->invoiceBaru();
        $this->loginSebagai(Role::Keuangan);
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection could not be established with host "smtp.rahasia.test:465"'));

        $respons = $this->post(route('keuangan.invoice.email', $invoice->id), $this->isian($invoice))
            ->assertRedirect(route('keuangan.invoice.index'))
            ->assertSessionHas('error', 'Invoice '.$invoice->nomor_invoice.' gagal dikirim via email. Silakan coba lagi atau hubungi admin sistem untuk memeriksa pengaturan email.');

        $this->assertStringNotContainsString('smtp.rahasia', (string) session('error'));
        $this->assertSame('Mohon cek tagihan terlampir.', session()->getOldInput('pesan'), 'Isian tidak hilang');
        $this->assertNotNull($respons);
    }

    public function test_invoice_lama_tanpa_rincian_tidak_dikirim(): void
    {
        Mail::fake();
        $klien = $this->buatKlien(['pic_email' => 'pic.uji@klien-email.test']);
        $id = DB::table('tbl_invoice')->insertGetId([
            'nomor_invoice' => 'INV-TSTX-MAIL', 'id_klien' => $klien, 'periode_bulan' => self::BULAN,
            'periode_tahun' => self::TAHUN, 'jumlah_tenaga' => 1, 'total_tagihan' => 2000000,
        ]);
        $this->loginSebagai(Role::Keuangan);

        $this->post(route('keuangan.invoice.email', $id), [...$this->isian((object) ['id' => $id])])
            ->assertSessionHas('error');
        Mail::assertNothingSent();
    }

    private function invoiceBaru(): object
    {
        $klien = $this->buatKlien([
            'nama_perusahaan' => 'PT Klien Email', 'management_fee' => 5, 'pic_email' => 'pic.uji@klien-email.test', 'pic_nama' => 'PIC Uji Email',
        ]);
        $id = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($id, $klien, '2099-07-01', null, [], 4000000, 25);
        DB::table('tbl_payroll')->insert([
            'id_karyawan' => $id, 'periode_bulan' => self::BULAN, 'periode_tahun' => self::TAHUN,
            'gaji_pokok' => 4000000, 'total_lembur' => 0, 'potongan_absen' => 0, 'total_gaji' => 4000000,
        ]);
        DatabaseService::callGenerateInvoiceKlien($klien, self::BULAN, self::TAHUN);

        // Procedure invoice sudah meng-commit transaksi test; samakan status transaksi Laravel dengan aplikasi.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        return DB::table('tbl_invoice')->where('id_klien', $klien)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function isian(object $invoice): array
    {
        return [
            'id_invoice' => $invoice->id,
            'email_tujuan' => 'pic.uji@klien-email.test',
            'cc' => '',
            'subjek' => 'Tagihan Invoice Jasa PT Satu Jaya Indonesia - PT Klien Email - Periode Juli 2099',
            'pesan' => 'Mohon cek tagihan terlampir.',
            'form_email' => '1',
        ];
    }
}

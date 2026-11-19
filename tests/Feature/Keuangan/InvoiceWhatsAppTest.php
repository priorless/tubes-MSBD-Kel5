<?php

namespace Tests\Feature\Keuangan;

use App\Models\Role;
use App\Services\DatabaseService;
use App\Services\InvoiceKlienService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Kirim invoice via WhatsApp (tautan wa.me + signed URL PDF publik) pada db_sji.
 * Periode uji Agustus 2099; data uji dihapus di tearDown.
 */
class InvoiceWhatsAppTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const int BULAN = 8;

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

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function nomorTelepon(): array
    {
        return [
            '08 menjadi 628' => ['081234567890', '6281234567890'],
            '62 dipertahankan' => ['6281234567890', '6281234567890'],
            '+62 dengan spasi & strip' => ['+62 812-3456-7890', '6281234567890'],
            'tanda kurung' => ['(0812) 3456 7890', '6281234567890'],
            '8 tanpa awalan' => ['81234567890', '6281234567890'],
            'kosong' => ['', null],
            'null' => [null, null],
            'terlalu pendek' => ['0812345', null],
            'bukan angka' => ['-', null],
            'telepon rumah 0' => ['0612345678', null],
            '620 tidak valid' => ['620812345678', null],
        ];
    }

    #[DataProvider('nomorTelepon')]
    public function test_normalisasi_nomor_whatsapp(?string $masukan, ?string $harapan): void
    {
        $this->assertSame($harapan, InvoiceKlienService::normalisasiNomorWhatsApp($masukan));
    }

    public function test_tombol_whatsapp_membuka_wa_me_dengan_pesan_dan_rekening_dari_konfigurasi(): void
    {
        $invoice = $this->invoiceBaru('0812-3456-7890');
        $this->loginSebagai(Role::Keuangan);

        $halaman = $this->get(route('keuangan.invoice.index'))->assertOk()->assertSee('Kirim via WhatsApp')->getContent();
        $pesan = $this->pesanUntuk($halaman, $invoice->nomor_invoice, '6281234567890');

        foreach (['Yth. Bapak/Ibu PIC PT Klien WhatsApp,', 'Nama Klien: PT Klien WhatsApp', 'Nomor Invoice: '.$invoice->nomor_invoice,
            'Periode Tagihan: Agustus 2099', 'Total Tagihan: Rp 4.222.000', 'Hormat kami,'] as $teks) {
            $this->assertStringContainsString($teks, $pesan);
        }
        $rekening = config('perusahaan.rekening');
        $this->assertStringContainsString($rekening['bank']."\n".$rekening['nomor']."\n".$rekening['nama'], $pesan);

        // Rekening selalu dibaca dari konfigurasi resmi.
        config(['perusahaan.rekening.nomor' => '9988776655', 'perusahaan.rekening.nama' => 'PT Satu Jaya Indonesia (uji)']);
        $hasil = app(InvoiceKlienService::class)->tautanWhatsApp($this->baris($invoice->id));
        $this->assertStringContainsString("9988776655\nPT Satu Jaya Indonesia (uji)", rawurldecode(explode('?text=', $hasil['url'])[1]));
    }

    public function test_tautan_pdf_publik_hanya_untuk_invoice_terkait_tanpa_login(): void
    {
        $invoice = $this->invoiceBaru('081234567890');
        $lain = $this->invoiceBaru('081298765432', 'PT Klien Lain WA');
        $tautan = app(InvoiceKlienService::class)->tautanWhatsApp($this->baris($invoice->id))['url'];
        $pesan = rawurldecode(explode('?text=', $tautan)[1]);
        preg_match('#(https?://\S+/tagihan/(\d+)/pdf\?\S+)#', $pesan, $cocok);
        $this->assertSame((string) $invoice->id, $cocok[2] ?? null, 'URL PDF mengarah ke invoice yang dipilih');
        Auth::logout();

        $pdf = $this->get($cocok[1])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('tagihan-'.$invoice->nomor_invoice.'.pdf', (string) $pdf->headers->get('Content-Disposition'));

        // Mengganti id ke invoice lain, tanpa tanda tangan, atau kedaluwarsa -> ditolak.
        $this->get(str_replace('/tagihan/'.$invoice->id.'/', '/tagihan/'.$lain->id.'/', $cocok[1]))->assertForbidden();
        $this->get(route('invoice.publik.pdf', $invoice->id))->assertForbidden();
        $this->travel(InvoiceKlienService::MASA_BERLAKU_TAUTAN_HARI + 1)->days();
        $this->get($cocok[1])->assertForbidden();
    }

    public function test_nomor_kosong_tidak_valid_atau_rekening_belum_lengkap_tidak_membuat_tautan(): void
    {
        $tanpaNomor = $this->invoiceBaru('', 'PT Tanpa Nomor');
        $nomorSalah = $this->invoiceBaru('12345', 'PT Nomor Salah');
        $layanan = app(InvoiceKlienService::class);

        $this->assertSame(['url' => null, 'galat' => 'Nomor WhatsApp PIC klien PT Tanpa Nomor belum diisi. Lengkapi nomor telepon PIC pada data klien.'],
            $layanan->tautanWhatsApp($this->baris($tanpaNomor->id)));
        $this->assertNull($layanan->tautanWhatsApp($this->baris($nomorSalah->id))['url']);
        $this->assertStringContainsString('tidak valid', $layanan->tautanWhatsApp($this->baris($nomorSalah->id))['galat']);

        $this->loginSebagai(Role::Keuangan);
        $this->get(route('keuangan.invoice.index'))->assertOk()
            ->assertSee('data-whatsapp-galat="Nomor WhatsApp PIC klien PT Tanpa Nomor belum diisi.', false)
            ->assertDontSee('https://wa.me/?', false);

        $valid = $this->invoiceBaru('081234567890', 'PT Rekening Kosong');
        config(['perusahaan.rekening.nomor' => null]);
        $hasil = $layanan->tautanWhatsApp($this->baris($valid->id));
        $this->assertNull($hasil['url']);
        $this->assertStringContainsString('rekening resmi perusahaan belum lengkap', $hasil['galat']);
    }

    public function test_pengguna_tidak_berwenang_tidak_dapat_membuat_tautan(): void
    {
        $this->invoiceBaru('081234567890');

        $this->get(route('keuangan.invoice.index'))->assertRedirect(route('login'));
        $this->loginSebagai(Role::Operasional);
        $this->get(route('keuangan.invoice.index'))->assertForbidden();
    }

    private function invoiceBaru(string $telepon, string $namaKlien = 'PT Klien WhatsApp'): object
    {
        $klien = $this->buatKlien(['nama_perusahaan' => $namaKlien, 'management_fee' => 5, 'pic_telp' => $telepon]);
        $id = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($id, $klien, '2099-08-01', null, [], 4000000, 25);
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

    /** Baris invoice seperti di halaman daftar (invoice + klien). */
    private function baris(int $idInvoice): object
    {
        return DB::table('tbl_invoice as i')->join('tbl_klien as k', 'k.id', '=', 'i.id_klien')->where('i.id', $idInvoice)
            ->first(['i.*', 'k.nama_perusahaan', 'k.pic_telp']);
    }

    /** Pesan WhatsApp (sudah di-decode) dari tautan wa.me di halaman untuk nomor invoice tertentu. */
    private function pesanUntuk(string $halaman, string $nomorInvoice, string $nomorWa): string
    {
        preg_match_all('#href="https://wa\.me/'.$nomorWa.'\?text=([^"]+)"#', $halaman, $cocok);
        foreach ($cocok[1] as $teks) {
            $pesan = rawurldecode(html_entity_decode($teks));
            if (str_contains($pesan, $nomorInvoice)) {
                return $pesan;
            }
        }
        $this->fail('Tautan WhatsApp untuk '.$nomorInvoice.' tidak ditemukan.');
    }
}

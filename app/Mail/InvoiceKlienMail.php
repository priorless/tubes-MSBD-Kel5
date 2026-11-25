<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email penagihan invoice klien dengan lampiran PDF tagihan (surat permohonan, invoice, lampiran)
 * yang dibuat dari keuangan.invoice.pdf untuk invoice yang dipilih.
 */
class InvoiceKlienMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $subjek,
        public string $pesan,
        public string $nomorInvoice,
        public string $namaFilePdf,
        private string $isiPdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjek);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice-klien');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->isiPdf, $this->namaFilePdf)->withMime('application/pdf'),
        ];
    }
}

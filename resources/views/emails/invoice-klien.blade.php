<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $subjek }}</title>
</head>
<body style="margin: 0; background: #f1f5f9; font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #f1f5f9; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%; background: #ffffff; border-radius: 6px; overflow: hidden;">
                    <tr>
                        <td style="background: #123b66; padding: 18px 28px; color: #ffffff;">
                            <p style="margin: 0; font-size: 18px; font-weight: bold; letter-spacing: 1px;">PT SATU JAYA INDONESIA</p>
                            <p style="margin: 4px 0 0; font-size: 12px; color: #bfdbfe;">Penyedia Jasa Tenaga Kerja Outsourcing</p>
                        </td>
                    </tr>
                    <tr><td style="height: 4px; background: #c49a45;"></td></tr>
                    <tr>
                        <td style="padding: 28px; font-size: 14px; line-height: 1.6;">
                            {!! nl2br(e($pesan)) !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 28px 24px;">
                            <p style="margin: 0; padding: 10px 14px; background: #f8fafc; border-left: 3px solid #123b66; font-size: 12px; color: #475569;">
                                Lampiran: {{ $namaFilePdf }} (surat permohonan, invoice {{ $nomorInvoice }}, dan lampiran rincian tagihan).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px; background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 11px; color: #64748b; text-align: center;">
                            {{ config('perusahaan.nama') }}
                            @if (filled(config('perusahaan.telepon'))) &middot; Telp. {{ config('perusahaan.telepon') }} @endif
                            @if (filled(config('perusahaan.email'))) &middot; {{ config('perusahaan.email') }} @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

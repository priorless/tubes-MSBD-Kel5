<?php

namespace App\Support;

/**
 * Label tampilan pengaturan BPJS (satu BPJS gabungan) dari tbl_klien, atau snapshotnya di
 * tbl_payroll/tbl_invoice. Perhitungan nilainya ada di database (sql/Komponen/14_bpjs_klien.sql).
 */
class LabelBpjs
{
    public const string PERSENTASE = 'Persentase';

    public const string NOMINAL = 'Nominal';

    /**
     * Contoh: "5% dari gaji", "Rp 100.000 per tenaga", atau $kosong bila tanpa BPJS.
     */
    public static function aturan(?string $metode, float|int|string|null $nilai, string $kosong = 'Tanpa BPJS'): string
    {
        return match ($metode) {
            self::PERSENTASE => self::persen($nilai).' dari gaji',
            self::NOMINAL => self::rupiah($nilai).' per tenaga',
            default => $kosong,
        };
    }

    /**
     * Persentase tanpa nol desimal yang tidak perlu: 5 -> "5%", 4.5 -> "4,5%", 2.25 -> "2,25%".
     */
    public static function persen(float|int|string|null $nilai): string
    {
        $teks = rtrim(rtrim(number_format((float) $nilai, 2, ',', '.'), '0'), ',');

        return $teks.'%';
    }

    public static function rupiah(float|int|string|null $nilai): string
    {
        return 'Rp '.number_format((float) $nilai, 0, ',', '.');
    }
}

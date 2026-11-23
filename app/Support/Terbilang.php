<?php

namespace App\Support;

/**
 * Terbilang bahasa Indonesia untuk nominal rupiah.
 * Contoh: 125009840 -> "Seratus Dua Puluh Lima Juta Sembilan Ribu Delapan Ratus Empat Puluh Rupiah".
 */
class Terbilang
{
    private const array SATUAN = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    /**
     * Rupiah penuh (pecahan sen diabaikan), huruf awal setiap kata kapital, diakhiri "Rupiah".
     */
    public static function rupiah(float|int|string $nominal): string
    {
        $angka = (int) floor(abs((float) $nominal));
        $teks = $angka === 0 ? 'nol' : self::angka($angka);

        return ucwords($teks).' Rupiah';
    }

    /**
     * Terbilang huruf kecil untuk bilangan bulat non-negatif.
     */
    public static function angka(int $angka): string
    {
        return trim((string) preg_replace('/\s+/', ' ', self::susun($angka)));
    }

    private static function susun(int $n): string
    {
        return match (true) {
            $n < 12 => self::SATUAN[$n],
            $n < 20 => self::susun($n - 10).' belas',
            $n < 100 => self::susun(intdiv($n, 10)).' puluh '.self::susun($n % 10),
            $n < 200 => 'seratus '.self::susun($n - 100),
            $n < 1000 => self::susun(intdiv($n, 100)).' ratus '.self::susun($n % 100),
            $n < 2000 => 'seribu '.self::susun($n - 1000),
            $n < 1000000 => self::susun(intdiv($n, 1000)).' ribu '.self::susun($n % 1000),
            $n < 1000000000 => self::susun(intdiv($n, 1000000)).' juta '.self::susun($n % 1000000),
            $n < 1000000000000 => self::susun(intdiv($n, 1000000000)).' miliar '.self::susun($n % 1000000000),
            default => self::susun(intdiv($n, 1000000000000)).' triliun '.self::susun($n % 1000000000000),
        };
    }
}

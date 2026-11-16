<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pembaca file sql/Komponen/*.sql untuk migrasi Laravel.
 *
 * - blokTahap(): pernyataan routine di antara penanda "-- [TAHAP n]" dan "DELIMITER ;" berikutnya,
 *   dipisah per "//" (pola file 08/09).
 * - pasangHakAkses(): menjalankan ulang sql/Komponen/10_hak_akses_database.sql. GRANT untuk objek yang
 *   belum/tidak lagi ada dilewati, sehingga up() dan down() tahap mana pun tetap berhasil.
 */
class SqlKomponen
{
    public const string FILE_HAK_AKSES = 'sql/Komponen/10_hak_akses_database.sql';

    /** 1146 tabel, 1054 kolom, 1305 routine tidak ada. */
    private const array KODE_OBJEK_TIDAK_ADA = [1146, 1054, 1305];

    /** 1044/1045/1142/1227 = user database tidak punya hak CREATE ROLE/GRANT (umum di shared hosting). */
    private const array KODE_TANPA_HAK = [1044, 1045, 1142, 1227];

    /**
     * @return list<string>
     */
    public static function blokTahap(string $file, int $tahap): array
    {
        $isi = file_get_contents(base_path($file));
        $penanda = preg_quote("-- [TAHAP {$tahap}]", '/');

        if (! preg_match('/^'.$penanda.'\s*$.*?^DELIMITER \/\/\s*$(.*?)^DELIMITER ;\s*$/ms', $isi, $cocok)) {
            throw new RuntimeException("Blok [TAHAP {$tahap}] tidak ditemukan di {$file}");
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\/\/[ \t]*(?:\r?\n|$)/', $cocok[1])),
            fn (string $sql) => preg_match('/^\s*(CREATE|DROP|ALTER|UPDATE)\b/mi', $sql) === 1
        ));
    }

    public static function jalankanBlok(string $file, int $tahap): void
    {
        foreach (self::blokTahap($file, $tahap) as $sql) {
            DB::unprepared($sql);
        }
    }

    /**
     * Pasang ulang role & GRANT dari file 10. Tidak menggagalkan migrasi bila user database tidak punya hak GRANT.
     */
    public static function pasangHakAkses(): void
    {
        $isi = preg_replace('/^\s*--.*$/m', '', file_get_contents(base_path(self::FILE_HAK_AKSES)));
        $pernyataan = array_values(array_filter(
            array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $isi)),
            fn (string $sql) => $sql !== ''
        ));

        foreach ($pernyataan as $sql) {
            try {
                DB::unprepared($sql);
            } catch (QueryException $exception) {
                $kode = (int) ($exception->errorInfo[1] ?? 0);

                if (in_array($kode, self::KODE_TANPA_HAK, true)) {
                    Log::warning('Hak akses database tidak dipasang: user database tidak punya hak CREATE ROLE/GRANT.', ['pesan' => $exception->getMessage()]);

                    return;
                }

                if (! in_array($kode, self::KODE_OBJEK_TIDAK_ADA, true) || ! str_starts_with(strtoupper($sql), 'GRANT')) {
                    throw $exception;
                }
            }
        }
    }

    public static function constraintAda(string $tabel, string $nama): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tabel)
            ->where('CONSTRAINT_NAME', $nama)
            ->exists();
    }

    public static function indeksAda(string $tabel, string $nama): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tabel)
            ->where('INDEX_NAME', $nama)
            ->exists();
    }

    public static function triggerAda(string $nama): bool
    {
        return DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', $nama)
            ->exists();
    }
}

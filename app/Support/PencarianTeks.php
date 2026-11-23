<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Pencarian nama memakai FULLTEXT (MATCH ... AGAINST) di MySQL, dengan LIKE sebagai cadangan.
 *
 * - MySQL dan setiap kata minimal 3 huruf (innodb_ft_min_token_size = 3): MATCH IN BOOLEAN MODE dengan
 *   "+kata*" per kata, artinya semua kata wajib ada dan dicocokkan dari awal kata ("pra" menemukan "Pratama").
 * - Ada kata < 3 huruf, atau bukan MySQL (SQLite saat tes): LIKE '%kata%' seperti sebelumnya.
 * Kolom kode (NIP / kode klien) tetap dicari dengan LIKE karena bukan teks bebas.
 */
class PencarianTeks
{
    private const int PANJANG_MINIMAL_FULLTEXT = 3;

    /**
     * @param  list<string>  $kolomKode
     */
    public static function terapkan(Builder $query, string $kolomNama, array $kolomKode, string $kataKunci): void
    {
        $kataKunci = trim($kataKunci);
        $pola = '%'.$kataKunci.'%';
        $booleanFulltext = self::booleanFulltext($query, $kataKunci);

        $query->where(function (Builder $cari) use ($kolomNama, $kolomKode, $pola, $booleanFulltext): void {
            if ($booleanFulltext !== null) {
                $cari->whereFullText($kolomNama, $booleanFulltext, ['mode' => 'boolean']);
            } else {
                $cari->where($kolomNama, 'like', $pola);
            }

            foreach ($kolomKode as $kolom) {
                $cari->orWhere($kolom, 'like', $pola);
            }
        });
    }

    /**
     * "+kata1* +kata2*" bila FULLTEXT dapat dipakai, selain itu null. Operator boolean dari pengguna dibuang.
     */
    private static function booleanFulltext(Builder $query, string $kataKunci): ?string
    {
        if (! in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $kata = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $kataKunci)), -1, PREG_SPLIT_NO_EMPTY);

        if ($kata === [] || collect($kata)->contains(fn (string $k): bool => mb_strlen($k) < self::PANJANG_MINIMAL_FULLTEXT)) {
            return null;
        }

        return collect($kata)->map(fn (string $k): string => '+'.$k.'*')->implode(' ');
    }
}

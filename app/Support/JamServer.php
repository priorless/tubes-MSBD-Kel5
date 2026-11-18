<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya sumber "waktu sekarang" untuk absensi: jam server DATABASE (NOW()),
 * sama dengan waktu yang dipakai trigger. Jam browser tidak pernah dipakai.
 *
 * Nilai berupa waktu dinding (tanpa zona) dari MySQL; semua perbandingan dilakukan
 * dalam zona yang sama sehingga tidak bergantung pada zona waktu aplikasi.
 * tetapkan() hanya untuk pengujian (membekukan waktu).
 */
class JamServer
{
    private ?CarbonImmutable $waktuTetap = null;

    public function sekarang(): CarbonImmutable
    {
        return $this->waktuTetap ?? CarbonImmutable::parse((string) DB::selectOne('SELECT NOW() AS sekarang')->sekarang);
    }

    public function tetapkan(?CarbonImmutable $waktu): void
    {
        $this->waktuTetap = $waktu;
    }
}

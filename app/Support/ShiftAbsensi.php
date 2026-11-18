<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Shift efektif seorang karyawan pada satu tanggal kerja (hasil JadwalAbsensiService::jadwalPada()).
 * mulai/selesai berupa waktu dinding server database; selesai bisa jatuh keesokan harinya (shift malam).
 */
final readonly class ShiftAbsensi
{
    public function __construct(
        public string $tanggal,
        public CarbonImmutable $mulai,
        public CarbonImmutable $selesai,
        public string $sumber,
        public ?string $namaShift,
        public int $idPenempatan,
    ) {}

    /** Check-in dibuka tepat N menit (config) sebelum shift dimulai. */
    public function bukaCheckIn(): CarbonImmutable
    {
        return $this->mulai->subMinutes((int) config('tenaga_kerja.absensi.buka_check_in_menit', 30));
    }

    /** Contoh: "Pagi · 07:00–15:00" atau "08:00–16:00". */
    public function label(): string
    {
        $jam = $this->mulai->format('H:i').'–'.$this->selesai->format('H:i');

        return filled($this->namaShift) ? $this->namaShift.' · '.$jam : $jam;
    }
}

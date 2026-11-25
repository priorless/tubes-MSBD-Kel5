<?php

namespace App\Console\Commands;

use App\Services\PertukaranShiftService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pertukaran-shift:kedaluwarsa')]
#[Description('Tandai Kedaluwarsa pengajuan backup shift yang melewati batas tanggapan rekan (5 jam sebelum shift) atau keputusan Operasional (shift dimulai).')]
class KedaluwarsakanBackupShift extends Command
{
    public function handle(PertukaranShiftService $pertukaranShiftService): int
    {
        $jumlah = $pertukaranShiftService->sinkronkanKedaluwarsa();
        $this->info("{$jumlah} pengajuan backup shift ditandai Kedaluwarsa.");

        return self::SUCCESS;
    }
}

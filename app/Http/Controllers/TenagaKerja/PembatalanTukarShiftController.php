<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\Controller;
use App\Services\PertukaranShiftService;
use App\Support\KaryawanAktif;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pengaju membatalkan pengajuan tukar shift miliknya yang masih Menunggu.
 * Pengajuan milik orang lain ditolak oleh service.
 */
class PembatalanTukarShiftController extends Controller
{
    public function __invoke(Request $request, int $pertukaranShift, PertukaranShiftService $pertukaranShiftService): RedirectResponse
    {
        $kembali = redirect()->route('tenaga-kerja.tukar-shift.index');

        if (! $pertukaranShiftService->tersedia()) {
            return $kembali->with('gagal', 'Fitur tukar shift belum tersedia. Hubungi bagian Operasional.');
        }

        try {
            $pertukaranShiftService->batalkan($pertukaranShift, (int) KaryawanAktif::id(), (int) $request->user()->id);
        } catch (ValidationException $exception) {
            return $kembali->with('gagal', $exception->validator->errors()->first());
        }

        return $kembali->with('sukses', 'Pengajuan tukar shift dibatalkan. Jadwal Anda tetap seperti semula.');
    }
}

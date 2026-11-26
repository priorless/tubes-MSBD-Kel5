<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\Controller;
use App\Services\PertukaranShiftService;
use App\Support\KaryawanAktif;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pengaju membatalkan permintaan backup shift miliknya yang masih Menunggu.
 * Pengajuan milik orang lain ditolak oleh service.
 */
class PembatalanTukarShiftController extends Controller
{
    public function __invoke(Request $request, int $pertukaranShift, PertukaranShiftService $pertukaranShiftService): RedirectResponse
    {
        $kembali = redirect()->route('tenaga-kerja.tukar-shift.index');

        if (! $pertukaranShiftService->tersedia()) {
            return $kembali->with('gagal', 'Fitur backup shift belum tersedia. Hubungi bagian Operasional.');
        }

        try {
            $pertukaranShiftService->batalkan($pertukaranShift, (int) KaryawanAktif::id(), (int) $request->user()->id);
        } catch (ValidationException $exception) {
            return $kembali->with('gagal', $exception->validator->errors()->first());
        }

        return $kembali->with('sukses', 'Permintaan backup shift dibatalkan. Jadwal Anda tetap seperti semula.');
    }
}

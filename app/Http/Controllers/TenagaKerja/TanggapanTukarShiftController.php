<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenagaKerja\TanggapiTukarShiftRequest;
use App\Services\PertukaranShiftService;
use App\Support\KaryawanAktif;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Rekan menerima atau menolak pengajuan tukar shift yang ditujukan kepadanya.
 * Rekan selalu karyawan yang sedang login; pengajuan milik orang lain ditolak oleh service.
 */
class TanggapanTukarShiftController extends Controller
{
    public function __invoke(TanggapiTukarShiftRequest $request, int $pertukaranShift, PertukaranShiftService $pertukaranShiftService): RedirectResponse
    {
        $kembali = redirect()->route('tenaga-kerja.tukar-shift.index');

        if (! $pertukaranShiftService->tersedia()) {
            return $kembali->with('gagal', 'Fitur tukar shift belum tersedia. Hubungi bagian Operasional.');
        }

        $terima = $request->menerima();
        $catatan = $request->validated('catatan_rekan');

        try {
            $pertukaranShiftService->tanggapiRekan(
                $pertukaranShift,
                (int) KaryawanAktif::id(),
                $terima,
                filled($catatan) ? trim($catatan) : null,
                (int) $request->user()->id,
            );
        } catch (ValidationException $exception) {
            return $kembali->with('gagal', $exception->validator->errors()->first());
        }

        return $kembali->with('sukses', $terima
            ? 'Anda menerima pengajuan tukar shift. Pengajuan diteruskan ke Operasional untuk persetujuan akhir.'
            : 'Anda menolak pengajuan tukar shift. Jadwal Anda tetap seperti semula.');
    }
}

<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenagaKerja\StorePengajuanTukarShiftRequest;
use App\Services\PertukaranShiftService;
use App\Support\KaryawanAktif;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Karyawan meminta rekan menggantikan (backup) satu jadwal Kerja miliknya. Pengaju selalu karyawan yang login.
 */
class PengajuanTukarShiftController extends Controller
{
    public function __invoke(StorePengajuanTukarShiftRequest $request, PertukaranShiftService $pertukaranShiftService): RedirectResponse
    {
        $kembali = redirect()->route('tenaga-kerja.tukar-shift.index');

        if (! $pertukaranShiftService->tersedia()) {
            return $kembali->with('gagal', 'Fitur backup shift belum tersedia. Hubungi bagian Operasional.');
        }

        $validated = $request->validated();

        try {
            $pertukaranShiftService->ajukan(
                (int) KaryawanAktif::id(),
                (int) $validated['id_jadwal'],
                (int) $validated['id_karyawan_rekan'],
                trim($validated['alasan']),
            );
        } catch (ValidationException $exception) {
            return redirect()->route('tenaga-kerja.tukar-shift.index', ['id_jadwal' => $validated['id_jadwal']])
                ->withInput()
                ->with('gagal', $exception->validator->errors()->first());
        }

        return $kembali->with('sukses', 'Permintaan backup shift terkirim. Menunggu tanggapan rekan, lalu persetujuan Operasional.');
    }
}

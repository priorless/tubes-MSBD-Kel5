<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenagaKerja\StorePengajuanTukarShiftRequest;
use App\Services\PertukaranShiftService;
use App\Support\KaryawanAktif;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Karyawan mengajukan tukar shift dari menu Absensi. Pengaju selalu karyawan yang sedang login.
 */
class PengajuanTukarShiftController extends Controller
{
    public function __invoke(StorePengajuanTukarShiftRequest $request, PertukaranShiftService $pertukaranShiftService): RedirectResponse
    {
        $kembali = redirect()->to(route('tenaga-kerja.absensi').'#tukar-shift');

        if (! $pertukaranShiftService->tersedia()) {
            return $kembali->with('gagal', 'Fitur tukar shift belum tersedia. Hubungi bagian Operasional.');
        }

        $validated = $request->validated();

        try {
            $pertukaranShiftService->ajukan(
                (int) KaryawanAktif::id(),
                (int) $validated['id_jadwal_pengaju'],
                (int) $validated['id_jadwal_rekan'],
                trim($validated['alasan']),
            );
        } catch (ValidationException $exception) {
            return $kembali->withInput()->with('gagal', $exception->validator->errors()->first());
        }

        return $kembali->with('sukses', 'Pengajuan tukar shift terkirim. Menunggu persetujuan rekan, lalu persetujuan akhir Operasional.');
    }
}

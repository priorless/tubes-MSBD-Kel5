<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\Controller;
use App\Services\PertukaranShiftService;
use App\Support\KaryawanAktif;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rekan menerima atau menolak pengajuan tukar shift yang ditujukan kepadanya.
 * Rekan selalu karyawan yang sedang login; pengajuan milik orang lain ditolak oleh service.
 */
class TanggapanTukarShiftController extends Controller
{
    public function __invoke(Request $request, int $pertukaranShift, PertukaranShiftService $pertukaranShiftService): RedirectResponse
    {
        $kembali = redirect()->to(route('tenaga-kerja.absensi').'#tukar-shift');

        if (! $pertukaranShiftService->tersedia()) {
            return $kembali->with('gagal', 'Fitur tukar shift belum tersedia. Hubungi bagian Operasional.');
        }

        $validated = $request->validate([
            'tanggapan' => ['required', Rule::in(['terima', 'tolak'])],
            'catatan_rekan' => ['nullable', 'string', 'max:500'],
        ], [
            'tanggapan.required' => 'Pilih terima atau tolak.',
            'tanggapan.in' => 'Tanggapan tidak valid.',
            'catatan_rekan.max' => 'Catatan maksimal 500 karakter.',
        ]);
        $terima = $validated['tanggapan'] === 'terima';

        try {
            $pertukaranShiftService->tanggapiRekan(
                $pertukaranShift,
                (int) KaryawanAktif::id(),
                $terima,
                filled($validated['catatan_rekan'] ?? null) ? trim($validated['catatan_rekan']) : null,
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

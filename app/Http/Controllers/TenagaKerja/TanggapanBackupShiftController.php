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
 * Rekan menerima atau menolak permintaan backup shift. Hanya rekan yang diminta (karyawan login) yang bisa menanggapi.
 */
class TanggapanBackupShiftController extends Controller
{
    public function __invoke(Request $request, int $pertukaranShift, PertukaranShiftService $pertukaranShiftService): RedirectResponse
    {
        $kembali = redirect()->route('tenaga-kerja.tukar-shift.index');

        if (! $pertukaranShiftService->tersedia()) {
            return $kembali->with('gagal', 'Fitur backup shift belum tersedia. Hubungi bagian Operasional.');
        }

        $validated = $request->validate([
            'keputusan' => ['required', Rule::in(['terima', 'tolak'])],
            'catatan_rekan' => ['nullable', 'string', 'max:500'],
        ], [
            'keputusan.required' => 'Pilih terima atau tolak.',
            'keputusan.in' => 'Pilihan tanggapan tidak valid.',
            'catatan_rekan.max' => 'Catatan maksimal 500 karakter.',
        ]);
        $terima = $validated['keputusan'] === 'terima';

        try {
            $pertukaranShiftService->tanggapiRekan(
                $pertukaranShift,
                (int) KaryawanAktif::id(),
                $terima,
                filled($validated['catatan_rekan'] ?? null) ? trim($validated['catatan_rekan']) : null,
            );
        } catch (ValidationException $exception) {
            return $kembali->with('gagal', $exception->validator->errors()->first());
        }

        return $kembali->with('sukses', $terima
            ? 'Anda menerima permintaan backup shift. Pengajuan diteruskan ke Operasional untuk disetujui.'
            : 'Anda menolak permintaan backup shift. Statusnya dapat dilihat pengaju di menu Backup Shift.');
    }
}

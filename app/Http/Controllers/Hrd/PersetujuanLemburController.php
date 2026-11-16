<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use App\Services\DatabaseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * HRD menyetujui/menolak lembur berstatus Menunggu lewat sp_putuskan_lembur.
 * Hanya lembur Disetujui yang dihitung sp_generate_payroll_bulanan.
 */
class PersetujuanLemburController extends Controller
{
    use MenanganiSignalDatabase;

    public function __invoke(Request $request, int $absensi): RedirectResponse
    {
        $validated = $request->validate([
            'keputusan' => ['required', 'in:Disetujui,Ditolak'],
        ], [
            'keputusan.required' => 'Keputusan lembur wajib dipilih.',
            'keputusan.in' => 'Keputusan lembur hanya boleh Disetujui atau Ditolak.',
        ]);

        try {
            DatabaseService::callPutuskanLembur($absensi, $validated['keputusan']);
        } catch (QueryException $exception) {
            $pesan = $this->pesanSignalDatabase($exception);

            if ($pesan === null) {
                throw $exception;
            }

            return back()->with('error', $pesan);
        }

        return back()->with('success', $validated['keputusan'] === 'Disetujui'
            ? 'Lembur disetujui dan akan dihitung pada payroll.'
            : 'Lembur ditolak dan tidak dihitung pada payroll.');
    }
}

<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use App\Services\DatabaseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Mengakhiri penempatan Aktif lewat sp_selesai_penempatan: penempatan & kontrak menjadi Selesai,
 * karyawan kembali Tersedia. Validasi (penempatan ada, masih Aktif, tanggal tidak sebelum tanggal mulai)
 * diputuskan procedure di database.
 */
class AkhiriPenempatanController extends Controller
{
    use MenanganiSignalDatabase;

    public function __invoke(Request $request, int $penempatan): RedirectResponse
    {
        $validated = $request->validate([
            'tgl_selesai' => ['required', 'date_format:Y-m-d'],
        ], [
            'tgl_selesai.required' => 'Tanggal selesai penempatan wajib diisi.',
            'tgl_selesai.date_format' => 'Tanggal selesai penempatan tidak valid.',
        ]);

        try {
            DatabaseService::callSelesaiPenempatan($penempatan, $validated['tgl_selesai']);
        } catch (QueryException $exception) {
            $pesan = $this->pesanSignalDatabase($exception);

            if ($pesan === null) {
                throw $exception;
            }

            return back()->with('error', $pesan);
        }

        return redirect()->route('operasional.monitoring')
            ->with('success', 'Penempatan berhasil diakhiri. Karyawan kembali berstatus Tersedia.');
    }
}

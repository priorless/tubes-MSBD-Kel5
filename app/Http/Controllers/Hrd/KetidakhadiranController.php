<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrd\StoreKetidakhadiranRequest;
use App\Services\DatabaseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * HRD mencatat ketidakhadiran (Izin/Sakit/Alpha) lewat procedure sp_input_ketidakhadiran.
 */
class KetidakhadiranController extends Controller
{
    use MenanganiSignalDatabase;

    public function __invoke(StoreKetidakhadiranRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $idKaryawan = $request->idKaryawan();

        try {
            DatabaseService::callInputKetidakhadiran(
                $idKaryawan,
                $data['tgl'],
                $data['status_kehadiran'],
                $data['keterangan'] ?? null,
            );
        } catch (QueryException $exception) {
            $pesan = $this->pesanSignalDatabase($exception);

            if ($pesan === null) {
                throw $exception;
            }

            return back()->withInput()->with('error', $pesan);
        }

        $nama = DB::table('tbl_karyawan')->where('id', $idKaryawan)->value('nama');

        return redirect()->route('hrd.absensi', ['tanggal' => $data['tgl']])
            ->with('success', "Ketidakhadiran {$nama} ({$data['status_kehadiran']}) berhasil dicatat.");
    }
}

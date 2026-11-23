<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use App\Services\DatabaseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mengakhiri penempatan Aktif lewat sp_selesai_penempatan: penempatan & kontrak menjadi Selesai,
 * karyawan kembali Tersedia. Validasi (penempatan ada, masih Aktif, tanggal tidak sebelum tanggal mulai)
 * diputuskan procedure di database.
 *
 * Tanggal selesai paling lambat hari ini (atau tanggal mulai, untuk membatalkan penempatan yang belum dimulai):
 * status langsung menjadi Selesai, sehingga tanggal di masa depan membuat payroll/invoice prorata tetap
 * menghitung hari yang tidak lagi dikerjakan dan karyawan bisa ditempatkan ganda pada hari yang sama.
 */
class AkhiriPenempatanController extends Controller
{
    use MenanganiSignalDatabase;

    public const string ZONA_WAKTU = 'Asia/Jakarta';

    public function __invoke(Request $request, int $penempatan): RedirectResponse
    {
        $tglMulai = DB::table('tbl_penempatan')->where('id', $penempatan)->value('tgl_mulai');
        abort_if($tglMulai === null, 404);

        $batasAkhir = self::batasTanggalSelesai((string) $tglMulai);

        $validated = $request->validate([
            'tgl_selesai' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$batasAkhir],
        ], [
            'tgl_selesai.required' => 'Tanggal selesai penempatan wajib diisi.',
            'tgl_selesai.date_format' => 'Tanggal selesai penempatan tidak valid.',
            'tgl_selesai.before_or_equal' => 'Tanggal selesai tidak boleh melewati hari ini. Penempatan langsung berakhir saat diproses.',
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

    /**
     * Tanggal selesai terakhir yang boleh dipilih: hari ini, atau tanggal mulai bila penempatan belum dimulai.
     */
    public static function batasTanggalSelesai(string $tglMulai): string
    {
        return max(now(self::ZONA_WAKTU)->toDateString(), substr($tglMulai, 0, 10));
    }
}

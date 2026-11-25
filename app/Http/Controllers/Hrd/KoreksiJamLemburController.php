<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Koreksi jam lembur pada absensi yang sudah memiliki check-in dan check-out. */
class KoreksiJamLemburController extends Controller
{
    public function __invoke(Request $request, int $absensi): RedirectResponse
    {
        $valid = $request->validate([
            'id_absensi_lembur' => ['required', 'integer', 'in:'.$absensi],
            'jam_lembur' => ['required', 'integer', 'min:0'],
        ]);

        $jamBaru = (int) $valid['jam_lembur'];

        DB::transaction(function () use ($request, $absensi, $jamBaru): void {
            $baris = DB::table('tbl_absensi')->where('id', $absensi)->lockForUpdate()->first([
                'id', 'id_karyawan', 'tgl', 'jam_masuk', 'jam_keluar', 'jam_lembur',
            ]);

            abort_if($baris === null, 404);

            if (blank($baris->jam_masuk) || blank($baris->jam_keluar)) {
                throw ValidationException::withMessages([
                    'jam_lembur' => 'Jam lembur hanya dapat dikoreksi setelah check-in dan check-out tercatat.',
                ]);
            }

            $periode = Carbon::parse($baris->tgl);
            $payrollSudahDibuat = DB::table('tbl_payroll')
                ->where('id_karyawan', $baris->id_karyawan)
                ->where('periode_bulan', $periode->month)
                ->where('periode_tahun', $periode->year)
                ->exists();

            if ($payrollSudahDibuat) {
                throw ValidationException::withMessages([
                    'jam_lembur' => 'Koreksi ditutup karena payroll periode absensi ini sudah dibuat.',
                ]);
            }

            $jamLama = (int) $baris->jam_lembur;

            if ($jamLama === $jamBaru) {
                return;
            }

            DB::table('tbl_absensi')->where('id', $absensi)->update(['jam_lembur' => $jamBaru]);

            // Audit disimpan bersama koreksi. Log bersifat append-only; waktu dan pelaku tetap dapat ditelusuri.
            DB::table('tbl_log_aktivitas')->insert([
                'waktu' => now(),
                'id_user' => $request->user()->getAuthIdentifier(),
                'nama_tabel' => 'tbl_absensi',
                'aksi' => 'UPDATE',
                'id_data' => $absensi,
                'keterangan' => sprintf('Koreksi jam lembur absensi %s: %d -> %d jam.', $baris->tgl, $jamLama, $jamBaru),
            ]);
        });

        $query = array_intersect_key($request->query(), array_flip(['tanggal', 'q', 'layanan', 'tanda', 'page']));
        $query = array_filter($query, static fn (mixed $nilai): bool => is_scalar($nilai) && (string) $nilai !== '');

        return redirect()->route('hrd.absensi.detail', ['absensi' => $absensi] + $query)
            ->with('success', 'Jam lembur absensi berhasil diperbarui.');
    }
}
<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * HRD memproses pengajuan koreksi lupa check-out. Disetujui = jam_keluar absensi diisi dari pengajuan
 * (jam lembur dihitung trigger trg_auto_hitung_lembur); Ditolak = absensi tidak berubah.
 */
class KoreksiCheckOutController extends Controller
{
    use MenanganiSignalDatabase;

    /** @var list<string> */
    public const array STATUS = ['Menunggu', 'Disetujui', 'Ditolak'];

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::STATUS)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $validated['status'] ?? 'Menunggu';

        $pengajuan = DB::table('tbl_koreksi_check_out as kc')
            ->join('tbl_absensi as a', 'a.id', '=', 'kc.id_absensi')
            ->join('tbl_karyawan as k', 'k.id', '=', 'a.id_karyawan')
            ->leftJoin('tbl_users as pemroses', 'pemroses.id', '=', 'kc.diproses_oleh')
            ->where('kc.status', $status)
            ->when($validated['q'] ?? null, function ($query, string $kataKunci): void {
                $pola = '%'.$kataKunci.'%';
                $query->where(fn ($sub) => $sub->where('k.nama', 'like', $pola)->orWhere('k.nip', 'like', $pola));
            })
            ->orderBy($status === 'Menunggu' ? 'kc.created_at' : 'kc.diproses_pada', $status === 'Menunggu' ? 'asc' : 'desc')
            ->select('kc.*', 'a.tgl', 'a.jam_masuk', 'k.nama', 'k.nip', 'pemroses.nama as nama_pemroses')
            ->paginate(20)
            ->withQueryString();

        return view('hrd.koreksi-check-out.index', [
            'pengajuan' => $pengajuan,
            'status' => $status,
            'daftarStatus' => self::STATUS,
            'jumlahMenunggu' => DB::table('tbl_koreksi_check_out')->where('status', 'Menunggu')->count(),
            'q' => $validated['q'] ?? '',
        ]);
    }

    public function update(Request $request, int $koreksi): RedirectResponse
    {
        $valid = $request->validate([
            'keputusan' => ['required', 'in:Disetujui,Ditolak'],
            'catatan_keputusan' => ['nullable', 'string', 'max:500', 'required_if:keputusan,Ditolak'],
        ], [
            'catatan_keputusan.required_if' => 'Isi alasan penolakan.',
            'catatan_keputusan.max' => 'Catatan maksimal 500 karakter.',
        ]);

        try {
            $this->putuskan($request, $koreksi, $valid);
        } catch (QueryException $exception) {
            // Batas wajar jam keluar diperiksa ulang trigger trg_koreksi_check_out_update saat disetujui.
            throw ValidationException::withMessages([
                'keputusan' => $this->teksSignalDatabase($exception) ?? throw $exception,
            ]);
        }

        return redirect()->route('hrd.koreksi-check-out.index')
            ->with('success', $valid['keputusan'] === 'Disetujui'
                ? 'Koreksi disetujui. Jam keluar absensi sudah diperbarui.'
                : 'Pengajuan koreksi ditolak.');
    }

    /**
     * @param  array{keputusan: string, catatan_keputusan?: string|null}  $valid
     */
    private function putuskan(Request $request, int $koreksi, array $valid): void
    {
        DB::transaction(function () use ($request, $koreksi, $valid): void {
            $baris = DB::table('tbl_koreksi_check_out')->where('id', $koreksi)->lockForUpdate()->first();
            abort_if($baris === null, 404);

            if ($baris->status !== 'Menunggu') {
                throw ValidationException::withMessages(['keputusan' => 'Pengajuan ini sudah diproses.']);
            }

            if ($valid['keputusan'] === 'Disetujui') {
                $this->terapkanJamKeluar((int) $baris->id_absensi, (string) $baris->jam_keluar, $request->user()->getAuthIdentifier());
            }

            DB::table('tbl_koreksi_check_out')->where('id', $koreksi)->update([
                'status' => $valid['keputusan'],
                'catatan_keputusan' => filled($valid['catatan_keputusan'] ?? null) ? trim($valid['catatan_keputusan']) : null,
                'diproses_oleh' => $request->user()->getAuthIdentifier(),
                'diproses_pada' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Isi jam_keluar absensi yang masih kosong. Ditolak bila sudah check-out atau payroll periodenya sudah dibuat.
     */
    private function terapkanJamKeluar(int $idAbsensi, string $jamKeluar, int|string $idUser): void
    {
        $absensi = DB::table('tbl_absensi')->where('id', $idAbsensi)->lockForUpdate()->first(['id', 'id_karyawan', 'tgl', 'jam_keluar']);
        abort_if($absensi === null, 404);

        if (filled($absensi->jam_keluar)) {
            throw ValidationException::withMessages(['keputusan' => 'Absensi ini sudah memiliki jam keluar.']);
        }

        $periode = Carbon::parse($absensi->tgl);
        $payrollSudahDibuat = DB::table('tbl_payroll')
            ->where('id_karyawan', $absensi->id_karyawan)
            ->where('periode_bulan', $periode->month)
            ->where('periode_tahun', $periode->year)
            ->exists();

        if ($payrollSudahDibuat) {
            throw ValidationException::withMessages(['keputusan' => 'Tidak dapat disetujui karena payroll periode absensi ini sudah dibuat.']);
        }

        DB::table('tbl_absensi')->where('id', $idAbsensi)->update(['jam_keluar' => $jamKeluar]);

        DB::table('tbl_log_aktivitas')->insert([
            'waktu' => now(),
            'id_user' => $idUser,
            'nama_tabel' => 'tbl_absensi',
            'aksi' => 'UPDATE',
            'id_data' => $idAbsensi,
            'keterangan' => sprintf('Koreksi lupa check-out absensi %s: jam keluar %s.', $absensi->tgl, substr($jamKeluar, 0, 5)),
        ]);
    }
}

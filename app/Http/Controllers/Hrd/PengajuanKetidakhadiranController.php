<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * HRD memutuskan pengajuan Izin/Sakit/Cuti dari karyawan.
 * Disetujui = sp_setujui_pengajuan_ketidakhadiran (mencatat absensi tiap hari kerja dalam rentang, hari Libur dilewati);
 * Ditolak = status saja, wajib dengan alasan (CHECK chk_pengajuan_ketidakhadiran_diproses).
 */
class PengajuanKetidakhadiranController extends Controller
{
    use MenanganiSignalDatabase;

    /** @var list<string> */
    public const array STATUS = ['Menunggu', 'Disetujui', 'Ditolak', 'Dibatalkan'];

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::STATUS)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $validated['status'] ?? 'Menunggu';

        $pengajuan = DB::table('tbl_pengajuan_ketidakhadiran as pk')
            ->join('tbl_karyawan as k', 'k.id', '=', 'pk.id_karyawan')
            ->leftJoin('tbl_users as pemroses', 'pemroses.id', '=', 'pk.diproses_oleh')
            ->where('pk.status', $status)
            ->when($validated['q'] ?? null, function ($query, string $kataKunci): void {
                $pola = '%'.$kataKunci.'%';
                $query->where(fn ($sub) => $sub->where('k.nama', 'like', $pola)->orWhere('k.nip', 'like', $pola));
            })
            ->orderBy($status === 'Menunggu' ? 'pk.tgl_mulai' : 'pk.diproses_pada', $status === 'Menunggu' ? 'asc' : 'desc')
            ->select('pk.*', 'k.nama', 'k.nip', 'k.status as status_karyawan', 'pemroses.nama as nama_pemroses')
            ->paginate(20)
            ->withQueryString();

        return view('hrd.ketidakhadiran.index', [
            'pengajuan' => $pengajuan,
            'status' => $status,
            'daftarStatus' => self::STATUS,
            'jumlahMenunggu' => DB::table('tbl_pengajuan_ketidakhadiran')->where('status', 'Menunggu')->count(),
            'q' => $validated['q'] ?? '',
        ]);
    }

    public function update(Request $request, int $pengajuan): RedirectResponse
    {
        $valid = $request->validate([
            'keputusan' => ['required', 'in:Disetujui,Ditolak'],
            'catatan_keputusan' => ['nullable', 'string', 'max:500', 'required_if:keputusan,Ditolak'],
        ], [
            'catatan_keputusan.required_if' => 'Isi alasan penolakan.',
            'catatan_keputusan.max' => 'Catatan maksimal 500 karakter.',
        ]);
        $idUser = (int) $request->user()->getAuthIdentifier();

        abort_unless(DB::table('tbl_pengajuan_ketidakhadiran')->where('id', $pengajuan)->exists(), 404);

        try {
            DB::transaction(function () use ($pengajuan, $valid, $idUser): void {
                if ($valid['keputusan'] === 'Disetujui') {
                    DB::statement('CALL sp_setujui_pengajuan_ketidakhadiran(?, ?)', [$pengajuan, $idUser]);

                    return;
                }

                $jumlah = DB::table('tbl_pengajuan_ketidakhadiran')
                    ->where('id', $pengajuan)
                    ->where('status', 'Menunggu')
                    ->update([
                        'status' => 'Ditolak',
                        'catatan_keputusan' => trim($valid['catatan_keputusan']),
                        'diproses_oleh' => $idUser,
                        'diproses_pada' => now(),
                        'updated_at' => now(),
                    ]);

                if ($jumlah === 0) {
                    throw ValidationException::withMessages(['keputusan' => 'Pengajuan ini sudah diproses.']);
                }
            });
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'keputusan' => $this->teksSignalDatabase($exception) ?? throw $exception,
            ]);
        }

        return redirect()->route('hrd.ketidakhadiran.index')
            ->with('success', $valid['keputusan'] === 'Disetujui'
                ? 'Pengajuan disetujui. Ketidakhadiran sudah dicatat pada absensi karyawan.'
                : 'Pengajuan ditolak.');
    }
}

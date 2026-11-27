<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\Controller;
use App\Support\KaryawanAktif;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Pengajuan koreksi lupa check-out oleh karyawan: absensi hari sebelumnya yang sudah check-in tetapi belum check-out
 * dapat diajukan jam keluarnya. HRD yang menyetujui atau menolak (Hrd\KoreksiCheckOutController).
 */
class KoreksiCheckOutController extends Controller
{
    /** Batas hari ke belakang absensi yang masih dapat diajukan koreksinya. */
    public const int BATAS_HARI = 30;

    public function index(): View
    {
        $idKaryawan = (int) KaryawanAktif::id();

        return view('tenaga-kerja.koreksi-check-out', [
            'lupaCheckOut' => $this->queryLupaCheckOut($idKaryawan)
                ->orderByDesc('a.tgl')
                ->get(['a.id', 'a.tgl', 'a.jam_masuk']),
            'pengajuan' => DB::table('tbl_koreksi_check_out as kc')
                ->join('tbl_absensi as a', 'a.id', '=', 'kc.id_absensi')
                ->where('a.id_karyawan', $idKaryawan)
                ->orderByDesc('kc.created_at')
                ->limit(20)
                ->get(['kc.id', 'a.tgl', 'a.jam_masuk', 'kc.jam_keluar', 'kc.alasan', 'kc.status', 'kc.catatan_keputusan', 'kc.created_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $idKaryawan = (int) KaryawanAktif::id();
        $valid = $request->validate([
            'id_absensi' => ['required', 'integer'],
            'jam_keluar' => ['required', 'date_format:H:i'],
            'alasan' => ['required', 'string', 'max:500'],
        ], [
            'jam_keluar.required' => 'Isi jam keluar yang sebenarnya.',
            'jam_keluar.date_format' => 'Format jam keluar tidak valid.',
            'alasan.required' => 'Isi alasan lupa check-out.',
            'alasan.max' => 'Alasan maksimal 500 karakter.',
        ]);

        DB::transaction(function () use ($request, $idKaryawan, $valid): void {
            $absensi = $this->queryLupaCheckOut($idKaryawan)
                ->where('a.id', $valid['id_absensi'])
                ->lockForUpdate()
                ->first(['a.id', 'a.jam_masuk']);

            if ($absensi === null) {
                throw ValidationException::withMessages([
                    'id_absensi' => 'Absensi ini tidak dapat diajukan koreksi (sudah check-out, sudah diajukan, atau di luar batas '.self::BATAS_HARI.' hari).',
                ]);
            }

            if (substr((string) $absensi->jam_masuk, 0, 5) === $valid['jam_keluar']) {
                throw ValidationException::withMessages(['jam_keluar' => 'Jam keluar tidak boleh sama dengan jam masuk.']);
            }

            DB::table('tbl_koreksi_check_out')->insert([
                'id_absensi' => $absensi->id,
                'jam_keluar' => $valid['jam_keluar'].':00',
                'alasan' => trim($valid['alasan']),
                'status' => 'Menunggu',
                'diajukan_oleh' => $request->user()->getAuthIdentifier(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return redirect()->route('tenaga-kerja.koreksi-check-out.index')
            ->with('success', 'Pengajuan koreksi check-out terkirim dan menunggu persetujuan HRD.');
    }

    /**
     * Absensi milik karyawan sebelum hari ini yang sudah check-in, belum check-out,
     * dan belum memiliki pengajuan Menunggu atau Disetujui.
     */
    private function queryLupaCheckOut(int $idKaryawan): Builder
    {
        return DB::table('tbl_absensi as a')
            ->where('a.id_karyawan', $idKaryawan)
            ->whereNotNull('a.jam_masuk')
            ->whereNull('a.jam_keluar')
            ->where('a.tgl', '<', now()->toDateString())
            ->where('a.tgl', '>=', now()->subDays(self::BATAS_HARI)->toDateString())
            ->whereNotExists(fn (Builder $sub) => $sub->select(DB::raw(1))
                ->from('tbl_koreksi_check_out as kc')
                ->whereColumn('kc.id_absensi', 'a.id')
                ->whereIn('kc.status', ['Menunggu', 'Disetujui']));
    }
}

<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use App\Support\KaryawanAktif;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengajuan koreksi lupa check-out oleh karyawan: absensi hari sebelumnya yang sudah check-in tetapi belum check-out
 * dapat diajukan jam keluarnya. Formulir & riwayatnya tampil di halaman Absensi (tidak ada halaman terpisah).
 * HRD yang menyetujui atau menolak (Hrd\KoreksiCheckOutController).
 */
class KoreksiCheckOutController extends Controller
{
    use MenanganiSignalDatabase;

    /** Batas hari ke belakang absensi yang masih dapat diajukan koreksinya. */
    public const int BATAS_HARI = 30;

    /** Jumlah pengajuan terakhir yang ditampilkan di halaman Absensi. */
    public const int JUMLAH_RIWAYAT = 5;

    /** URL lama halaman koreksi tetap berlaku: diarahkan ke bagian lupa check-out di halaman Absensi. */
    public function index(): RedirectResponse
    {
        return redirect()->to(route('tenaga-kerja.absensi').'#lupa-check-out');
    }

    /**
     * Data bagian "Lupa check-out" di halaman Absensi.
     *
     * @return array{lupaCheckOut: Collection<int, object>, pengajuanKoreksi: Collection<int, object>}
     */
    public static function dataHalamanAbsensi(int $idKaryawan): array
    {
        return [
            'lupaCheckOut' => self::queryLupaCheckOut($idKaryawan)
                ->orderByDesc('a.tgl')
                ->get(['a.id', 'a.tgl', 'a.jam_masuk']),
            'pengajuanKoreksi' => DB::table('tbl_koreksi_check_out as kc')
                ->join('tbl_absensi as a', 'a.id', '=', 'kc.id_absensi')
                ->where('a.id_karyawan', $idKaryawan)
                ->where('kc.created_at', '>=', now()->subDays(self::BATAS_HARI))
                ->orderByDesc('kc.created_at')
                ->limit(self::JUMLAH_RIWAYAT)
                ->get(['kc.id', 'a.tgl', 'a.jam_masuk', 'kc.jam_keluar', 'kc.alasan', 'kc.status', 'kc.catatan_keputusan', 'kc.created_at']),
        ];
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

        try {
            $this->simpanPengajuan($request, $idKaryawan, $valid);
        } catch (QueryException $exception) {
            // Batas wajar jam keluar (sp_cek_koreksi_check_out lewat trigger trg_koreksi_check_out_insert).
            throw ValidationException::withMessages([
                'jam_keluar' => $this->teksSignalDatabase($exception) ?? throw $exception,
            ]);
        }

        return redirect()->route('tenaga-kerja.absensi')
            ->with('sukses', 'Pengajuan koreksi check-out terkirim dan menunggu persetujuan HRD.');
    }

    /**
     * @param  array{id_absensi: int|string, jam_keluar: string, alasan: string}  $valid
     */
    private function simpanPengajuan(Request $request, int $idKaryawan, array $valid): void
    {
        DB::transaction(function () use ($request, $idKaryawan, $valid): void {
            $absensi = self::queryLupaCheckOut($idKaryawan)
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
    }

    /**
     * Absensi milik karyawan sebelum hari ini yang sudah check-in, belum check-out,
     * dan belum memiliki pengajuan Menunggu atau Disetujui.
     */
    private static function queryLupaCheckOut(int $idKaryawan): Builder
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

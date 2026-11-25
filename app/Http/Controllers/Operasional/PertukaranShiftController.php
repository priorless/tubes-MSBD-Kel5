<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use App\Services\PertukaranShiftService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Tab "Pengajuan Backup Shift" pada menu Jadwal Kerja: Operasional memeriksa, menyetujui, atau menolak
 * pengajuan backup shift yang sudah diterima rekan pengganti. Pengajuan yang masih menunggu tanggapan rekan
 * hanya ditampilkan (filter "Menunggu Rekan").
 */
class PertukaranShiftController extends Controller
{
    /** @var list<string> */
    private const array FILTER_STATUS = [
        PertukaranShiftService::STATUS_MENUNGGU,
        self::FILTER_MENUNGGU_REKAN,
        PertukaranShiftService::STATUS_DISETUJUI,
        PertukaranShiftService::STATUS_DITOLAK,
        PertukaranShiftService::STATUS_DIBATALKAN,
        PertukaranShiftService::STATUS_KEDALUWARSA,
        'Semua',
    ];

    private const string FILTER_MENUNGGU_REKAN = 'MenungguRekan';

    /**
     * Semua pengajuan dari semua karyawan (tanpa memilih karyawan), Menunggu paling atas.
     */
    public function index(Request $request, PertukaranShiftService $pertukaranShiftService): View
    {
        $this->pastikanTersedia($pertukaranShiftService);
        $pertukaranShiftService->sinkronkanKedaluwarsa();
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(self::FILTER_STATUS)],
            'tanggal_dari' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_dari'],
            'cari' => ['nullable', 'string', 'max:100'],
            'id_klien' => ['nullable', 'integer', 'min:1'],
        ], [
            'status.in' => 'Pilihan status tidak valid.',
            'tanggal_dari.date_format' => 'Format tanggal awal tidak valid.',
            'tanggal_sampai.date_format' => 'Format tanggal akhir tidak valid.',
            'tanggal_sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'cari.max' => 'Kata pencarian maksimal 100 karakter.',
            'id_klien.integer' => 'Pilihan klien tidak valid.',
        ]);
        $status = $validated['status'] ?? 'Semua';
        $cari = trim((string) ($validated['cari'] ?? ''));
        $tanggalDari = $validated['tanggal_dari'] ?? null;
        $tanggalSampai = $validated['tanggal_sampai'] ?? null;

        $query = $pertukaranShiftService->queryDaftar()
            ->when($status === PertukaranShiftService::STATUS_MENUNGGU, fn (Builder $query) => $query
                ->where('ps.status', PertukaranShiftService::STATUS_MENUNGGU)
                ->where('ps.status_rekan', PertukaranShiftService::REKAN_DITERIMA))
            ->when($status === self::FILTER_MENUNGGU_REKAN, fn (Builder $query) => $query
                ->where('ps.status', PertukaranShiftService::STATUS_MENUNGGU)
                ->where('ps.status_rekan', PertukaranShiftService::REKAN_MENUNGGU))
            ->when(! in_array($status, ['Semua', PertukaranShiftService::STATUS_MENUNGGU, self::FILTER_MENUNGGU_REKAN], true),
                fn (Builder $query) => $query->where('ps.status', $status))
            ->when($tanggalDari, fn (Builder $query, string $tanggal) => $query->where('ps.tanggal_kerja', '>=', $tanggal))
            ->when($tanggalSampai, fn (Builder $query, string $tanggal) => $query->where('ps.tanggal_kerja', '<=', $tanggal))
            ->when($validated['id_klien'] ?? null, fn (Builder $query, int|string $idKlien) => $query->where('pp.id_klien', (int) $idKlien))
            ->when($cari !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($cari): void {
                $pola = '%'.addcslashes($cari, '%_\\').'%';
                $query->where('kp.nama', 'like', $pola)
                    ->orWhere('kp.nip', 'like', $pola)
                    ->orWhere('kr.nama', 'like', $pola)
                    ->orWhere('kr.nip', 'like', $pola);
            }));

        return view('operasional.jadwal-kerja.pertukaran-shift.index', [
            'daftarPengajuan' => $query
                ->orderByRaw("CASE WHEN ps.status = 'Menunggu' AND ps.status_rekan = 'Diterima' THEN 0 WHEN ps.status = 'Menunggu' THEN 1 ELSE 2 END")
                ->orderByRaw("CASE WHEN ps.status = 'Menunggu' THEN ps.tanggal_kerja END ASC")
                ->orderByDesc('ps.diproses_pada')
                ->orderByDesc('ps.id')
                ->paginate(20)
                ->withQueryString(),
            'filter' => [
                'status' => $status,
                'tanggal_dari' => $tanggalDari,
                'tanggal_sampai' => $tanggalSampai,
                'cari' => $cari,
                'id_klien' => isset($validated['id_klien']) ? (int) $validated['id_klien'] : null,
            ],
            'filterAktif' => $cari !== '' || array_filter(array_intersect_key($validated, array_flip(['tanggal_dari', 'tanggal_sampai', 'id_klien']))) !== [],
            'filterStatus' => self::FILTER_STATUS,
            'ringkasanStatus' => $pertukaranShiftService->ringkasanStatus(),
            'daftarKlien' => DB::table('tbl_klien as kl')
                ->whereExists(fn (Builder $query) => $query->select(DB::raw(1))
                    ->from('tbl_pertukaran_shift as ps')
                    ->join('tbl_jadwal_kerja as j', 'j.id', '=', 'ps.id_jadwal_pengaju')
                    ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
                    ->whereColumn('p.id_klien', 'kl.id'))
                ->orderBy('kl.nama_perusahaan')
                ->get(['kl.id', 'kl.nama_perusahaan']),
            'jumlahMenunggu' => $pertukaranShiftService->jumlahMenunggu(),
            'pertukaranShift' => $pertukaranShiftService,
        ]);
    }

    public function show(int $pertukaranShift, PertukaranShiftService $pertukaranShiftService): View
    {
        $this->pastikanTersedia($pertukaranShiftService);
        $pengajuan = $pertukaranShiftService->detail($pertukaranShift);
        abort_if($pengajuan === null, 404);

        return view('operasional.jadwal-kerja.pertukaran-shift.show', [
            'pengajuan' => $pengajuan,
            'masalah' => $pertukaranShiftService->masalahPersetujuan($pengajuan),
            'jumlahMenunggu' => $pertukaranShiftService->jumlahMenunggu(),
            'pertukaranShift' => $pertukaranShiftService,
        ]);
    }

    public function approve(Request $request, int $pertukaranShift, PertukaranShiftService $pertukaranShiftService): RedirectResponse
    {
        $this->pastikanTersedia($pertukaranShiftService);

        try {
            $pertukaranShiftService->setujui($pertukaranShift, (int) $request->user()->id);
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first());
        }

        return redirect()
            ->route('operasional.jadwal-kerja.pertukaran-shift.show', $pertukaranShift)
            ->with('success', 'Pengajuan disetujui. Shift sudah ditugaskan kepada rekan pengganti.');
    }

    public function reject(Request $request, int $pertukaranShift, PertukaranShiftService $pertukaranShiftService): RedirectResponse
    {
        $this->pastikanTersedia($pertukaranShiftService);
        $validated = $request->validate([
            'catatan_keputusan' => ['nullable', 'string', 'max:500'],
        ], [
            'catatan_keputusan.max' => 'Catatan penolakan maksimal 500 karakter.',
        ]);

        try {
            $pertukaranShiftService->tolak(
                $pertukaranShift,
                (int) $request->user()->id,
                filled($validated['catatan_keputusan'] ?? null) ? trim($validated['catatan_keputusan']) : null,
            );
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first());
        }

        return redirect()
            ->route('operasional.jadwal-kerja.pertukaran-shift.show', $pertukaranShift)
            ->with('success', 'Pengajuan ditolak. Jadwal pengaju tetap seperti semula.');
    }

    private function pastikanTersedia(PertukaranShiftService $pertukaranShiftService): void
    {
        abort_unless($pertukaranShiftService->tersedia(), 503, 'Fitur Backup Shift belum tersedia sebelum migration diterapkan.');
    }
}

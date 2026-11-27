<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laporan absensi karyawan untuk HRD (hanya baca).
 * Data dari view v_absensi_harian: lokasi resmi = penempatan yang tercatat pada absensi
 * (tbl_penempatan, modul Operasional), jadwal = HANYA jam_datang/jam_pulang penempatan tersebut,
 * status terlambat diputuskan trigger database. Foto unggahan dibuka lewat route absensi.foto.
 */
class AbsensiKaryawanController extends Controller
{
    /** @var array<string, string> filter tanda => label */
    public const array TANDA = [
        'terlambat' => 'Terlambat',
        'lupa_check_out' => 'Lupa check-out',
        'tanpa_jadwal' => 'Jadwal belum ditentukan',
    ];

    public function __invoke(Request $request): View
    {
        if ($request->query('tab') === 'rekap') {
            return $this->halamanRekap($request);
        }

        $validated = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'layanan' => ['nullable', 'string', 'max:50'],
            'tanda' => ['nullable', 'in:'.implode(',', array_keys(self::TANDA))],
        ]);

        $tanggal = $validated['tanggal'] ?? DB::selectOne('SELECT CURDATE() AS hari_ini')->hari_ini;
        $daftarLayanan = DB::table('tbl_jenis_layanan')->orderBy('nama_layanan')->pluck('nama_layanan');

        if (! Schema::hasView('v_absensi_harian')) {
            return view('hrd.absensi.index', [
                'tab' => 'harian',
                'tanggal' => $tanggal,
                'absensi' => null,
                'payrollDibuat' => [],
                'ringkasan' => null,
                'belumAbsen' => collect(),
                'daftarLayanan' => $daftarLayanan,
                'daftarTanda' => self::TANDA,
                'daftarKaryawan' => $this->daftarKaryawan(),
            ]);
        }

        $absensi = $this->queryHarian($tanggal)
            ->when($validated['layanan'] ?? null, fn (Builder $query, string $layanan) => $query->where('nama_layanan', $layanan))
            ->when($validated['q'] ?? null, function (Builder $query, string $kataKunci): void {
                $pola = '%'.$kataKunci.'%';
                $query->where(fn (Builder $sub) => $sub->where('nama', 'like', $pola)->orWhere('nip', 'like', $pola));
            })
            ->when($validated['tanda'] ?? null, fn (Builder $query, string $tanda) => match ($tanda) {
                'terlambat' => $query->where('status_kehadiran', 'Terlambat'),
                'lupa_check_out' => $query->where('lupa_check_out', 1),
                'tanpa_jadwal' => $query->whereNull('jadwal_datang')->whereNull('jadwal_pulang'),
            })
            ->orderBy('jam_masuk')
            ->paginate(20)
            ->withQueryString();
        $periode = Carbon::parse($tanggal);
        $payrollDibuat = DB::table('tbl_payroll')
            ->whereIn('id_karyawan', $absensi->getCollection()->pluck('id_karyawan')->unique())
            ->where('periode_bulan', $periode->month)
            ->where('periode_tahun', $periode->year)
            ->pluck('id_karyawan')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return view('hrd.absensi.index', [
            'tab' => 'harian',
            'tanggal' => $tanggal,
            'absensi' => $absensi,
            'payrollDibuat' => $payrollDibuat,
            'ringkasan' => $this->queryHarian($tanggal)->selectRaw("
                COUNT(*) AS total,
                COALESCE(SUM(status_kehadiran = 'Terlambat'), 0) AS terlambat,
                COALESCE(SUM(jam_masuk IS NOT NULL AND jam_keluar IS NULL), 0) AS belum_check_out
            ")->first(),
            'belumAbsen' => $this->belumAbsen($tanggal),
            'daftarLayanan' => $daftarLayanan,
            'daftarTanda' => self::TANDA,
            'daftarKaryawan' => $this->daftarKaryawan(),
        ]);
    }

    public function show(Request $request, int $absensi): View
    {
        abort_unless(Schema::hasView('v_absensi_harian'), 503, 'Data absensi belum tersedia.');

        $baris = DB::table('v_absensi_harian')->where('id_absensi', $absensi)->first();
        abort_if($baris === null, 404);

        $periode = Carbon::parse($baris->tgl);
        $payrollDibuat = DB::table('tbl_payroll')
            ->where('id_karyawan', $baris->id_karyawan)
            ->where('periode_bulan', $periode->month)
            ->where('periode_tahun', $periode->year)
            ->exists();

        return view('hrd.absensi.detail', [
            'baris' => $baris,
            'payrollDibuat' => $payrollDibuat,
        ]);
    }

    public function exportPdf(Request $request): Response
    {
        abort_unless(Schema::hasView('v_absensi_harian'), 503, 'Data absensi belum tersedia.');

        $filter = $this->filterRekap($request);
        $karyawan = $filter['id_karyawan']
            ? DB::table('tbl_karyawan')->where('id', $filter['id_karyawan'])->first(['nip', 'nama'])
            : null;
        $klien = $filter['id_klien']
            ? DB::table('tbl_klien')->where('id', $filter['id_klien'])->value('nama_perusahaan')
            : null;
        $data = [
            'filter' => $filter,
            'karyawan' => $karyawan,
            'klien' => $klien,
            'ringkasanRekap' => $this->ringkasanRekap($filter),
            'rekap' => $this->queryRekap($filter)->orderBy('a.tgl')->orderBy('a.nama')->get(),
            'waktuCetak' => now(),
        ];
        $namaFile = 'rekap-absensi-'.$filter['tanggal_awal'].'-'.$filter['tanggal_akhir'].'.pdf';

        return Pdf::loadView('hrd.absensi.pdf', $data)
            ->setPaper('a4', 'landscape')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->download($namaFile);
    }

    public function cariKaryawan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
        ]);
        $kataKunci = trim($validated['q']);

        if ($kataKunci === '') {
            return response()->json([]);
        }

        return response()->json(DB::table('tbl_karyawan')
            ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'tbl_karyawan.id_jenis_layanan')
            ->where('tbl_karyawan.status', '<>', 'Nonaktif')
            ->where(fn (Builder $query) => $query
                ->where('tbl_karyawan.nama', 'like', '%'.$kataKunci.'%')
                ->orWhere('tbl_karyawan.nip', 'like', '%'.$kataKunci.'%'))
            ->orderBy('tbl_karyawan.nama')
            ->limit(10)
            ->get([
                'tbl_karyawan.id',
                'tbl_karyawan.nama',
                'tbl_karyawan.nip',
                DB::raw('COALESCE(jl.nama_layanan, tbl_karyawan.kategori) AS layanan'),
            ]));
    }

    private function halamanRekap(Request $request): View
    {
        $filter = $this->filterRekap($request);
        $tersedia = Schema::hasView('v_absensi_harian');
        $baris = $tersedia
            ? $this->queryRekap($filter)->orderByDesc('a.tgl')->orderBy('a.nama')->paginate(25)->withQueryString()
            : null;
        $ringkasan = $tersedia ? $this->ringkasanRekap($filter) : null;

        return view('hrd.absensi.index', [
            'tab' => 'rekap',
            'tanggal' => $filter['tanggal_akhir'],
            'absensi' => null,
            'ringkasan' => null,
            'belumAbsen' => collect(),
            'daftarLayanan' => collect(),
            'daftarTanda' => self::TANDA,
            'filterRekap' => $filter,
            'karyawanTerpilih' => $filter['id_karyawan']
                ? DB::table('tbl_karyawan')->where('id', $filter['id_karyawan'])->first(['id', 'nama', 'nip'])
                : null,
            'daftarKlien' => DB::table('tbl_klien')->orderBy('nama_perusahaan')->get(['id', 'nama_perusahaan']),
            'rekap' => $baris,
            'ringkasanRekap' => $ringkasan,
        ]);
    }

    /** @param array{tanggal_awal: string, tanggal_akhir: string, id_karyawan: ?int, id_klien: ?int} $filter */
    private function ringkasanRekap(array $filter): ?object
    {
        if (! Schema::hasView('v_absensi_harian')) {
            return null;
        }

        $ringkasan = $this->queryRekap($filter)->selectRaw(<<<'SQL'
                COUNT(CASE WHEN a.status_kehadiran IN ('Hadir', 'Terlambat') THEN 1 END) AS hadir,
                COUNT(CASE WHEN a.status_kehadiran = 'Terlambat' THEN 1 END) AS terlambat,
                COALESCE(SUM(a.menit_terlambat), 0) AS total_menit_terlambat,
                COUNT(CASE WHEN a.status_kehadiran = 'Izin' THEN 1 END) AS izin,
                COUNT(CASE WHEN a.status_kehadiran = 'Sakit' THEN 1 END) AS sakit,
                COUNT(CASE WHEN a.status_kehadiran = 'Cuti' THEN 1 END) AS cuti,
                COUNT(CASE WHEN a.status_kehadiran = 'Alpha' THEN 1 END) AS alpha,
                COUNT(CASE WHEN a.jam_masuk IS NOT NULL AND a.jam_keluar IS NULL THEN 1 END) AS belum_check_out,
                COUNT(CASE WHEN a.jam_lembur > 0 THEN 1 END) AS lembur,
                COALESCE(SUM(a.jam_lembur), 0) AS jam_lembur
                SQL)->first();

        $ringkasan->tanpa_catatan = $this->jumlahHariTanpaCatatan($filter);

        return $ringkasan;
    }

    /** @return array{tanggal_awal: string, tanggal_akhir: string, id_karyawan: ?int, id_klien: ?int} */
    private function filterRekap(Request $request): array
    {
        $hariIni = DB::selectOne('SELECT CURDATE() AS hari_ini')->hari_ini;
        $validated = $request->validate([
            'tanggal_awal' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['nullable', 'date_format:Y-m-d'],
            'id_karyawan' => ['nullable', 'integer', 'exists:tbl_karyawan,id'],
            'id_klien' => ['nullable', 'integer', 'exists:tbl_klien,id'],
        ]);
        $tanggalAwal = $validated['tanggal_awal'] ?? substr($hariIni, 0, 7).'-01';
        $tanggalAkhir = $validated['tanggal_akhir'] ?? $hariIni;

        if ($tanggalAwal > $hariIni || $tanggalAkhir > $hariIni) {
            throw ValidationException::withMessages(['tanggal_akhir' => 'Tanggal rekap tidak boleh melewati hari ini.']);
        }
        if ($tanggalAwal > $tanggalAkhir) {
            throw ValidationException::withMessages(['tanggal_akhir' => 'Tanggal akhir harus sama atau setelah tanggal awal.']);
        }

        if (Carbon::parse($tanggalAwal)->diffInDays(Carbon::parse($tanggalAkhir)) > 365) {
            throw ValidationException::withMessages(['tanggal_akhir' => 'Periode rekap maksimal 366 hari.']);
        }

        return [
            'tanggal_awal' => $tanggalAwal,
            'tanggal_akhir' => $tanggalAkhir,
            'id_karyawan' => isset($validated['id_karyawan']) ? (int) $validated['id_karyawan'] : null,
            'id_klien' => isset($validated['id_klien']) ? (int) $validated['id_klien'] : null,
        ];
    }

    /** @param array{tanggal_awal: string, tanggal_akhir: string, id_karyawan: ?int, id_klien: ?int} $filter */
    private function queryRekap(array $filter): Builder
    {
        return DB::table('v_absensi_harian as a')
            ->whereBetween('a.tgl', [$filter['tanggal_awal'], $filter['tanggal_akhir']])
            ->when($filter['id_karyawan'], fn (Builder $query, int $id) => $query->where('a.id_karyawan', $id))
            ->when($filter['id_klien'], fn (Builder $query, int $id) => $query->whereExists(fn (Builder $subquery) => $subquery
                ->selectRaw('1')->from('tbl_penempatan as p')->whereColumn('p.id', 'a.id_penempatan')->where('p.id_klien', $id)));
    }

    /**
     * Hari dalam rentang tanggal penempatan tanpa baris absensi. Penempatan hanya menyimpan
     * rentang tanggal dan jam shift, sehingga perhitungan memakai setiap tanggal kalender.
     *
     * @param  array{tanggal_awal: string, tanggal_akhir: string, id_karyawan: ?int, id_klien: ?int}  $filter
     */
    private function jumlahHariTanpaCatatan(array $filter): int
    {
        $penempatan = DB::table('tbl_penempatan as p')
            ->where('p.tgl_mulai', '<=', $filter['tanggal_akhir'])
            ->where(fn (Builder $query) => $query->whereNull('p.tgl_selesai')->orWhere('p.tgl_selesai', '>=', $filter['tanggal_awal']))
            ->when($filter['id_karyawan'], fn (Builder $query, int $id) => $query->where('p.id_karyawan', $id))
            ->when($filter['id_klien'], fn (Builder $query, int $id) => $query->where('p.id_klien', $id))
            ->get(['p.id_karyawan', 'p.tgl_mulai', 'p.tgl_selesai']);

        if ($penempatan->isEmpty()) {
            return 0;
        }

        $idKaryawan = $penempatan->pluck('id_karyawan')->unique();
        $adaCatatan = DB::table('tbl_absensi')
            ->whereIn('id_karyawan', $idKaryawan)
            ->whereBetween('tgl', [$filter['tanggal_awal'], $filter['tanggal_akhir']])
            ->get(['id_karyawan', 'tgl'])
            ->mapWithKeys(fn (object $baris) => [$baris->id_karyawan.'|'.$baris->tgl => true]);
        $hariTanpaCatatan = [];

        foreach ($penempatan as $item) {
            $mulai = max($filter['tanggal_awal'], $item->tgl_mulai);
            $akhir = min($filter['tanggal_akhir'], $item->tgl_selesai ?? $filter['tanggal_akhir']);

            for ($hari = Carbon::parse($mulai); $hari->lte(Carbon::parse($akhir)); $hari->addDay()) {
                $kunci = $item->id_karyawan.'|'.$hari->toDateString();

                if (! isset($adaCatatan[$kunci])) {
                    $hariTanpaCatatan[$kunci] = true;
                }
            }
        }

        return count($hariTanpaCatatan);
    }

    private function queryHarian(string $tanggal): Builder
    {
        return DB::table('v_absensi_harian')->where('tgl', $tanggal);
    }

    /**
     * Karyawan yang punya penempatan aktif pada tanggal tersebut tetapi tidak ada baris absensinya.
     *
     * @return Collection<int, object>
     */
    private function belumAbsen(string $tanggal): Collection
    {
        return DB::table('tbl_penempatan as p')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
            ->where('p.status', 'Aktif')
            ->where('p.tgl_mulai', '<=', $tanggal)
            ->where(fn (Builder $query) => $query->whereNull('p.tgl_selesai')->orWhere('p.tgl_selesai', '>=', $tanggal))
            ->whereNotExists(fn (Builder $query) => $query->select(DB::raw(1))
                ->from('tbl_absensi as a')
                ->whereColumn('a.id_karyawan', 'p.id_karyawan')
                ->where('a.tgl', $tanggal))
            ->orderBy('k.nama')
            ->limit(50)
            ->get(['k.id', 'k.nip', 'k.nama', 'jl.nama_layanan', 'kl.nama_perusahaan', 'p.lokasi_kerja']);
    }

    /**
     * Karyawan yang dapat dicatat ketidakhadirannya (semua kecuali Nonaktif).
     *
     * @return Collection<int, object>
     */
    private function daftarKaryawan(): Collection
    {
        return DB::table('tbl_karyawan')
            ->where('status', '<>', 'Nonaktif')
            ->orderBy('nama')
            ->get(['id', 'nip', 'nama', 'kategori']);
    }
}

<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

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
                'tanggal' => $tanggal,
                'absensi' => null,
                'ringkasan' => null,
                'belumAbsen' => collect(),
                'daftarLayanan' => $daftarLayanan,
                'daftarTanda' => self::TANDA,
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

        return view('hrd.absensi.index', [
            'tanggal' => $tanggal,
            'absensi' => $absensi,
            'ringkasan' => $this->queryHarian($tanggal)->selectRaw("
                COUNT(*) AS total,
                COALESCE(SUM(status_kehadiran = 'Terlambat'), 0) AS terlambat,
                COALESCE(SUM(jam_masuk IS NOT NULL AND jam_keluar IS NULL), 0) AS belum_check_out
            ")->first(),
            'belumAbsen' => $this->belumAbsen($tanggal),
            'daftarLayanan' => $daftarLayanan,
            'daftarTanda' => self::TANDA,
        ]);
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
            ->get(['k.nip', 'k.nama', 'jl.nama_layanan', 'kl.nama_perusahaan', 'p.lokasi_kerja']);
    }
}

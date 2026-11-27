<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\LaporanAktivitasService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Monitoring catatan yang ditulis driver (hanya baca) untuk HRD dan Operasional:
 * keterangan laporan perjalanan, keterangan P2H saat check-in, dan catatan kondisi akhir kendaraan saat check-out.
 */
class CatatanDriverController extends Controller
{
    /** @var array<string, string> filter sumber => label */
    public const array SUMBER = [
        'laporan' => 'Laporan perjalanan',
        'p2h' => 'P2H (check-in)',
        'akhir' => 'Kondisi akhir (check-out)',
    ];

    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sumber' => ['nullable', 'in:'.implode(',', array_keys(self::SUMBER))],
            'tanggal_awal' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ]);

        $catatan = DB::query()
            ->fromSub($this->semuaCatatan(), 'c')
            ->join('tbl_karyawan as k', 'k.id', '=', 'c.id_karyawan')
            ->leftJoin('tbl_absensi as a', 'a.id', '=', 'c.id_absensi')
            ->leftJoin('tbl_penempatan as p', 'p.id', '=', 'a.id_penempatan')
            ->leftJoin('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->select('c.*', 'k.nama', 'k.nip', 'kl.nama_perusahaan as klien')
            ->when($validated['sumber'] ?? null, fn (Builder $query, string $sumber) => $query->where('c.sumber', $sumber))
            ->when($validated['tanggal_awal'] ?? null, fn (Builder $query, string $tanggal) => $query->whereDate('c.waktu', '>=', $tanggal))
            ->when($validated['tanggal_akhir'] ?? null, fn (Builder $query, string $tanggal) => $query->whereDate('c.waktu', '<=', $tanggal))
            ->when($validated['q'] ?? null, function (Builder $query, string $kataKunci): void {
                $pola = '%'.$kataKunci.'%';
                $query->where(fn (Builder $sub) => $sub->where('k.nama', 'like', $pola)
                    ->orWhere('k.nip', 'like', $pola)
                    ->orWhere('c.catatan', 'like', $pola));
            })
            ->orderByDesc('c.waktu')
            ->paginate(20)
            ->withQueryString();

        return view('catatan-driver.index', [
            'catatan' => $catatan,
            'filter' => $validated,
            'daftarSumber' => self::SUMBER,
            'komponenP2h' => LaporanAktivitasService::KOMPONEN_P2H,
            'rute' => $request->routeIs('hrd.*') ? 'hrd.catatan-driver' : 'operasional.catatan-driver',
            'bisaLihatFoto' => $request->user()->hasRole(Role::Hrd),
        ]);
    }

    /**
     * Gabungan seluruh catatan driver dengan kolom yang seragam.
     */
    private function semuaCatatan(): Builder
    {
        $laporan = DB::table('tbl_laporan_aktivitas')
            ->where('jenis', LaporanAktivitasService::JENIS_PERJALANAN)
            ->whereNotNull('keterangan')->where('keterangan', '<>', '')
            ->selectRaw("'laporan' AS sumber, id AS id_laporan, id_absensi, id_karyawan, COALESCE(waktu_koreksi, waktu_lapor) AS waktu,
                keterangan AS catatan, NULL AS komponen, NULL AS kondisi");

        $p2h = DB::table('tbl_p2h_item as i')
            ->join('tbl_p2h as h', 'h.id', '=', 'i.id_p2h')
            ->whereNotNull('i.keterangan')->where('i.keterangan', '<>', '')
            ->selectRaw("'p2h' AS sumber, NULL AS id_laporan, h.id_absensi, h.id_karyawan, h.waktu_pemeriksaan AS waktu,
                i.keterangan AS catatan, i.komponen, i.kondisi");

        $akhir = DB::table('tbl_p2h')
            ->whereNotNull('catatan_akhir')->where('catatan_akhir', '<>', '')
            ->selectRaw("'akhir' AS sumber, NULL AS id_laporan, id_absensi, id_karyawan, waktu_selesai AS waktu,
                catatan_akhir AS catatan, NULL AS komponen, kondisi_akhir AS kondisi");

        return $laporan->unionAll($p2h)->unionAll($akhir);
    }
}

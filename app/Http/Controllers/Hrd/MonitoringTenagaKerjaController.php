<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use App\Services\LaporanAktivitasService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MonitoringTenagaKerjaController extends Controller
{
    public function __invoke(Request $request, LaporanAktivitasService $laporanAktivitas): View
    {
        $validated = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'karyawan' => ['nullable', 'integer'],
            'klien' => ['nullable', 'integer', Rule::exists('tbl_klien', 'id')],
            'layanan' => ['nullable', 'integer', Rule::exists('tbl_jenis_layanan', 'id')],
        ], [
            'tanggal.date_format' => 'Tanggal laporan tidak valid.',
            'q.max' => 'Kata pencarian maksimal 100 karakter.',
            'karyawan.integer' => 'Tenaga kerja yang dipilih tidak valid.',
            'klien.*' => 'Klien yang dipilih tidak valid.',
            'layanan.*' => 'Jenis layanan yang dipilih tidak valid.',
        ]);

        $tanggal = $validated['tanggal'] ?? null;
        $kataKunci = trim((string) ($validated['q'] ?? ''));
        // Klien = tempat bekerja aktual saat laporan dikirim (penempatan pada absensi laporan, termasuk backup shift).
        $idKlien = isset($validated['klien']) ? (int) $validated['klien'] : null;
        $idLayanan = isset($validated['layanan']) ? (int) $validated['layanan'] : null;
        $detailKaryawanId = isset($validated['karyawan']) ? (int) $validated['karyawan'] : null;
        $laporan = null;
        $tenagaKerja = null;
        $karyawan = null;

        if ($laporanAktivitas->tersedia()) {
            $queryLaporan = static function () use ($tanggal, $kataKunci, $idKlien, $idLayanan): Builder {
                return DB::table('tbl_laporan_aktivitas as la')
                    ->join('tbl_karyawan as k', 'k.id', '=', 'la.id_karyawan')
                    ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
                    ->leftJoin('tbl_absensi as a', 'a.id', '=', 'la.id_absensi')
                    ->leftJoin('tbl_penempatan as p', 'p.id', '=', 'a.id_penempatan')
                    ->leftJoin('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
                    ->when($tanggal, fn (Builder $query, string $tgl) => $query->whereDate('la.waktu_lapor', $tgl))
                    ->when($idKlien, fn (Builder $query, int $id) => $query->where('p.id_klien', $id))
                    ->when($idLayanan, fn (Builder $query, int $id) => $query->where('k.id_jenis_layanan', $id))
                    ->when($kataKunci !== '', fn (Builder $query) => $query->where(fn (Builder $cari) => $cari
                        ->where('k.nama', 'like', '%'.$kataKunci.'%')
                        ->orWhere('k.nip', 'like', '%'.$kataKunci.'%')));
            };

            if ($detailKaryawanId !== null) {
                $karyawan = DB::table('tbl_karyawan as k')
                    ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
                    ->where('k.id', $detailKaryawanId)
                    ->select(['k.id', 'k.nama', 'k.nip', 'jl.nama_layanan'])
                    ->first();

                $laporan = $queryLaporan()
                    ->where('la.id_karyawan', $detailKaryawanId)
                    ->orderByDesc('la.waktu_lapor')
                    ->orderByDesc('la.id')
                    ->select([
                        'la.id', 'la.id_karyawan', 'la.jenis', 'la.periode_ke', 'la.periode_mulai', 'la.periode_selesai',
                        'la.tahapan', 'la.keterangan', 'la.foto_koreksi', 'la.waktu_lapor', 'la.waktu_koreksi',
                        'k.nama', 'k.nip', 'jl.nama_layanan', 'kl.nama_perusahaan', 'p.lokasi_kerja',
                    ])
                    ->paginate(24)
                    ->withQueryString();
            } else {
                $tenagaKerja = $queryLaporan()
                    ->select([
                        'k.id as id_karyawan',
                        'k.nama',
                        'k.nip',
                        'jl.nama_layanan',
                        DB::raw('COUNT(la.id) as jumlah_laporan'),
                        DB::raw('MAX(la.waktu_lapor) as tanggal_monitoring'),
                    ])
                    ->groupBy('k.id', 'k.nama', 'k.nip', 'jl.nama_layanan')
                    ->orderByDesc('tanggal_monitoring')
                    ->orderBy('k.nama')
                    ->paginate(24)
                    ->withQueryString();
            }
        }

        return view('hrd.monitoring.index', [
            'laporan' => $laporan,
            'tenagaKerja' => $tenagaKerja,
            'karyawan' => $karyawan,
            'detailKaryawanId' => $detailKaryawanId,
            'tanggal' => $tanggal,
            'kataKunci' => $kataKunci,
            'idKlien' => $idKlien,
            'idLayanan' => $idLayanan,
            // Hanya klien yang pernah menjadi tempat penempatan, agar pilihan tidak berisi klien tanpa tenaga kerja.
            'daftarKlien' => DB::table('tbl_klien as kl')
                ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('tbl_penempatan as p')->whereColumn('p.id_klien', 'kl.id'))
                ->orderBy('kl.nama_perusahaan')
                ->get(['kl.id', 'kl.nama_perusahaan']),
            'daftarLayanan' => DB::table('tbl_jenis_layanan')->orderBy('nama_layanan')->get(['id', 'nama_layanan']),
            'filterAktif' => array_filter($request->only('tanggal', 'q', 'klien', 'layanan'), filled(...)),
        ]);
    }
}

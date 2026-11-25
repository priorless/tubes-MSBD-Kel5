<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use App\Services\RekapOperasionalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekap & Laporan Operasional: jadwal kerja dibandingkan dengan realisasi absensi per karyawan per bulan.
 */
class RekapOperasionalController extends Controller
{
    public function __construct(private RekapOperasionalService $rekapOperasionalService) {}

    public function index(Request $request): View
    {
        $filter = $this->filter($request);

        return view('operasional.rekap.index', [
            'filter' => $filter,
            'rekap' => $this->rekapOperasionalService->rekap($filter['bulan'], $filter['id_klien'], $filter['id_karyawan']),
            'daftarKlien' => DB::table('tbl_klien as kl')
                ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('tbl_penempatan as p')->whereColumn('p.id_klien', 'kl.id'))
                ->orderBy('kl.nama_perusahaan')
                ->get(['kl.id', 'kl.nama_perusahaan']),
            'daftarKaryawan' => DB::table('tbl_karyawan as k')
                ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('tbl_penempatan as p')
                    ->whereColumn('p.id_karyawan', 'k.id')
                    ->when($filter['id_klien'] !== null, fn ($sub) => $sub->where('p.id_klien', $filter['id_klien'])))
                ->orderBy('k.nama')
                ->get(['k.id', 'k.nip', 'k.nama']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filter = $this->filter($request);
        $rekap = $this->rekapOperasionalService->rekap($filter['bulan'], $filter['id_klien'], $filter['id_karyawan']);
        $belumTersedia = 'belum tersedia';
        $kolom = [
            'NIP' => static fn (object $b): string => (string) $b->nip,
            'Nama' => static fn (object $b): string => (string) $b->nama,
            'Klien' => static fn (object $b): string => (string) $b->klien,
            'Hari kerja terjadwal' => static fn (object $b): int => $b->kerja_terjadwal,
            'Libur terjadwal' => static fn (object $b): int => $b->libur_terjadwal,
            'Tanggal belum diatur' => static fn (object $b): int => $b->belum_diatur,
            'Hadir' => static fn (object $b): int => $b->hadir,
            'Terlambat (hari)' => static fn (object $b): int => $b->terlambat,
            'Terlambat (menit)' => static fn (object $b): int|string => $rekap['kolom']['menit_terlambat'] ? $b->menit_terlambat : $belumTersedia,
            'Izin' => static fn (object $b): int => $b->izin,
            'Sakit' => static fn (object $b): int => $b->sakit,
            'Alpha' => static fn (object $b): int => $b->alpha,
            'Kerja tanpa catatan absensi' => static fn (object $b): int => count($b->tanggal_tanpa_absensi),
            'Jam kerja tercatat' => static fn (object $b): int => $b->jam_kerja,
            'Jam lembur' => static fn (object $b): int => $b->jam_lembur,
        ];

        return response()->streamDownload(function () use ($rekap, $kolom): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys($kolom), ';');

            foreach ($rekap['baris'] as $baris) {
                fputcsv($out, array_map(static fn (callable $nilai): int|string => $nilai($baris), array_values($kolom)), ';');
            }

            fclose($out);
        }, 'rekap-operasional-'.$filter['bulan'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{bulan: string, id_klien: int|null, id_karyawan: int|null}
     */
    private function filter(Request $request): array
    {
        $validated = $request->validate([
            'bulan' => ['nullable', 'date_format:Y-m'],
            'id_klien' => ['nullable', 'integer', 'min:1'],
            'id_karyawan' => ['nullable', 'integer', 'min:1'],
        ], [
            'bulan.date_format' => 'Format bulan tidak valid.',
            'id_klien.integer' => 'Pilihan klien tidak valid.',
            'id_karyawan.integer' => 'Pilihan karyawan tidak valid.',
        ]);

        return [
            'bulan' => $validated['bulan'] ?? now()->format('Y-m'),
            'id_klien' => isset($validated['id_klien']) ? (int) $validated['id_klien'] : null,
            'id_karyawan' => isset($validated['id_karyawan']) ? (int) $validated['id_karyawan'] : null,
        ];
    }
}

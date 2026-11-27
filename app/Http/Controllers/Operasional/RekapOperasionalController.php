<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use App\Services\RekapOperasionalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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

    public function export(Request $request): Response
    {
        $filter = $this->filter($request);

        return Pdf::loadView('operasional.rekap.pdf', [
            'filter' => $filter,
            'rekap' => $this->rekapOperasionalService->rekap($filter['bulan'], $filter['id_klien'], $filter['id_karyawan']),
            'klien' => $filter['id_klien'] ? DB::table('tbl_klien')->where('id', $filter['id_klien'])->value('nama_perusahaan') : null,
            'waktuCetak' => now(),
        ])
            ->setPaper('a4', 'landscape')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->download('rekap-operasional-'.$filter['bulan'].'.pdf');
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

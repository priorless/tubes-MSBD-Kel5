<?php

namespace App\Http\Controllers\Ceo;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Log aktivitas (tbl_log_aktivitas) untuk CEO, hanya baca. Isi log ditulis trigger database.
 */
class LogAktivitasController extends Controller
{
    /** @var array<string, string> nama tabel => label */
    public const array TABEL = [
        'tbl_karyawan' => 'Karyawan',
        'tbl_penempatan' => 'Penempatan',
        'tbl_payroll' => 'Payroll',
        'tbl_invoice' => 'Invoice',
        'tbl_users' => 'Akun Login',
        'tbl_klien' => 'Klien',
    ];

    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'tabel' => ['nullable', 'in:'.implode(',', array_keys(self::TABEL))],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ], [
            'tabel.in' => 'Tabel yang dipilih tidak valid.',
            'dari.date_format' => 'Tanggal awal tidak valid.',
            'sampai.date_format' => 'Tanggal akhir tidak valid.',
            'sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ]);

        $log = DB::table('tbl_log_aktivitas as l')
            ->leftJoin('tbl_users as u', 'u.id', '=', 'l.id_user')
            ->when($validated['tabel'] ?? null, fn (Builder $query, string $tabel) => $query->where('l.nama_tabel', $tabel))
            ->when($validated['dari'] ?? null, fn (Builder $query, string $dari) => $query->where('l.waktu', '>=', $dari.' 00:00:00'))
            ->when($validated['sampai'] ?? null, fn (Builder $query, string $sampai) => $query->where('l.waktu', '<=', $sampai.' 23:59:59'))
            ->orderByDesc('l.waktu')
            ->orderByDesc('l.id')
            ->select(['l.id', 'l.waktu', 'l.nama_tabel', 'l.aksi', 'l.id_data', 'l.keterangan', 'l.id_user', 'u.username', 'u.nama as nama_user'])
            ->paginate(25)
            ->withQueryString();

        return view('ceo.log_aktivitas', [
            'log' => $log,
            'daftarTabel' => self::TABEL,
            'filter' => $validated,
        ]);
    }
}

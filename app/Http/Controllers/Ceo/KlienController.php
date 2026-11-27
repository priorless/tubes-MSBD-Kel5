<?php

namespace App\Http\Controllers\Ceo;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Daftar klien untuk CEO, hanya baca: jumlah klien, karyawan yang sedang ditempatkan (penempatan Aktif)
 * di tiap klien, beserta nama-namanya. Data berasal dari tbl_klien yang sama dengan Operasional & HRD.
 */
class KlienController extends Controller
{
    public function __invoke(): View
    {
        $klien = DB::table('tbl_klien')
            ->orderByRaw("CASE WHEN status = 'Aktif' THEN 0 ELSE 1 END")
            ->orderBy('nama_perusahaan')
            ->get(['id', 'kode_klien', 'nama_perusahaan', 'alamat', 'pic_nama', 'pic_telp', 'status']);

        $penempatanPerKlien = DB::table('tbl_penempatan as p')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
            ->where('p.status', 'Aktif')
            ->orderBy('k.nama')
            ->get(['p.id', 'p.id_klien', 'p.id_karyawan', 'p.lokasi_kerja', 'p.tgl_mulai', 'k.nip', 'k.nama', 'jl.nama_layanan'])
            ->groupBy(static fn (object $baris): int => (int) $baris->id_klien);

        return view('ceo.klien', [
            'klien' => $klien,
            'penempatanPerKlien' => $penempatanPerKlien,
            'jumlahKlien' => $klien->count(),
            'jumlahKlienAktif' => $klien->where('status', 'Aktif')->count(),
            'jumlahDitempatkan' => $penempatanPerKlien->flatten(1)->unique('id_karyawan')->count(),
        ]);
    }
}

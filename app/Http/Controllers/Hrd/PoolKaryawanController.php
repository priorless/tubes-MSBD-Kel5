<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PoolKaryawanController extends Controller
{
    /**
     * Karyawan Lapangan berstatus Tersedia, dibaca langsung dari view v_pool_karyawan_hrd.
     */
    public function __invoke(Request $request): View
    {
        $pool = DB::table('v_pool_karyawan_hrd')
            ->when($request->filled('layanan'), fn ($query) => $query->where('nama_layanan', $request->string('layanan')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $kataKunci = '%'.$request->string('q').'%';
                $query->where(fn ($sub) => $sub->where('nama', 'like', $kataKunci)->orWhere('nip', 'like', $kataKunci));
            })
            ->orderBy('nama')
            ->paginate(15)
            ->withQueryString();

        return view('hrd.pool.index', [
            'pool' => $pool,
            'daftarLayanan' => DB::table('tbl_jenis_layanan')->orderBy('nama_layanan')->pluck('nama_layanan'),
        ]);
    }
}

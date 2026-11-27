<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrd\StoreSertifikatRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SertifikatController extends Controller
{
    use MenanganiSignalDatabase;

    /**
     * Dokumen dengan sisa hari di bawah batas ini ditandai merah di tampilan.
     */
    public const BATAS_HARI_PERINGATAN = 30;

    /**
     * Disk privat berkas sertifikat: berkas hanya dibuka lewat route sertifikat.berkas (pemilik atau HRD).
     */
    public const string DISK = 'local';

    public function index(Request $request): View
    {
        // Status & sisa hari dihitung view v_sertifikat_karyawan dari tgl_kadaluarsa (tidak disimpan di tabel).
        $sertifikat = DB::table('v_sertifikat_karyawan')
            ->when($request->boolean('hampir_habis'), fn ($query) => $query->where('sisa_hari', '<', self::BATAS_HARI_PERINGATAN))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $kataKunci = '%'.$request->string('q').'%';
                $query->where(fn ($sub) => $sub->where('nama', 'like', $kataKunci)
                    ->orWhere('nip', 'like', $kataKunci)
                    ->orWhere('nama_dokumen', 'like', $kataKunci));
            })
            ->orderBy('sisa_hari')
            ->orderBy('nama')
            ->paginate(15)
            ->withQueryString();

        return view('hrd.sertifikat.index', [
            'sertifikat' => $sertifikat,
            'batasHari' => self::BATAS_HARI_PERINGATAN,
        ]);
    }

    public function create(): View
    {
        return view('hrd.sertifikat.create', [
            'karyawan' => DB::table('tbl_karyawan')->orderBy('nama')->get(['id', 'nip', 'nama', 'kategori']),
        ]);
    }

    public function store(StoreSertifikatRequest $request): RedirectResponse
    {
        $pathBerkas = $request->file('file_upload')?->store('sertifikat', self::DISK);

        try {
            DB::table('tbl_sertifikat')->insert([
                ...Arr::except($request->validated(), ['file_upload']),
                'file_upload' => $pathBerkas,
            ]);
        } catch (QueryException $exception) {
            if ($pathBerkas !== null) {
                Storage::disk(self::DISK)->delete($pathBerkas);
            }

            $pesan = $this->pesanSignalDatabase($exception) ?? throw $exception;

            return back()->withInput()->with('error', $pesan);
        }

        return redirect()->route('hrd.sertifikat.index')->with('success', 'Dokumen sertifikat berhasil ditambahkan.');
    }

    public function destroy(int $sertifikat): RedirectResponse
    {
        $data = DB::table('tbl_sertifikat')->where('id', $sertifikat)->firstOrFail();

        try {
            DB::table('tbl_sertifikat')->where('id', $sertifikat)->delete();
        } catch (QueryException $exception) {
            $pesan = $this->pesanSignalDatabase($exception) ?? throw $exception;

            return back()->with('error', $pesan);
        }

        if ($data->file_upload) {
            Storage::disk(self::DISK)->delete($data->file_upload);
        }

        return redirect()->route('hrd.sertifikat.index')->with('success', 'Dokumen sertifikat berhasil dihapus.');
    }
}

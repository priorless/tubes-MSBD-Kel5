<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\BerkasDokumenController;
use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenagaKerja\StorePengajuanKetidakhadiranRequest;
use App\Support\KaryawanAktif;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Pengajuan Izin/Sakit/Cuti oleh karyawan. HRD yang memutuskan (Hrd\PengajuanKetidakhadiranController);
 * saat disetujui, sp_setujui_pengajuan_ketidakhadiran mencatat baris absensi per hari kerja.
 * Karyawan hanya dapat membatalkan pengajuan miliknya yang masih Menunggu.
 */
class PengajuanKetidakhadiranController extends Controller
{
    use MenanganiSignalDatabase;

    public function index(): View
    {
        return view('tenaga-kerja.ketidakhadiran', [
            'pengajuan' => DB::table('tbl_pengajuan_ketidakhadiran')
                ->where('id_karyawan', (int) KaryawanAktif::id())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
            'daftarJenis' => StorePengajuanKetidakhadiranRequest::JENIS,
        ]);
    }

    public function store(StorePengajuanKetidakhadiranRequest $request): RedirectResponse
    {
        $idKaryawan = (int) KaryawanAktif::id();
        $valid = $request->validated();
        $pathBukti = $request->file('file_bukti')?->store('ketidakhadiran', BerkasDokumenController::DISK);

        try {
            DB::transaction(function () use ($idKaryawan, $valid, $pathBukti): void {
                // Kunci baris karyawan agar dua pengajuan bersamaan tidak lolos pemeriksaan tabrakan tanggal di trigger.
                DB::table('tbl_karyawan')->where('id', $idKaryawan)->lockForUpdate()->value('id');

                DB::table('tbl_pengajuan_ketidakhadiran')->insert([
                    'id_karyawan' => $idKaryawan,
                    'jenis' => $valid['jenis'],
                    'tgl_mulai' => $valid['tgl_mulai'],
                    'tgl_selesai' => $valid['tgl_selesai'],
                    'alasan' => trim($valid['alasan']),
                    'file_bukti' => $pathBukti,
                    'status' => 'Menunggu',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            if ($pathBukti !== null) {
                Storage::disk(BerkasDokumenController::DISK)->delete($pathBukti);
            }

            throw ValidationException::withMessages([
                'tgl_mulai' => $this->teksSignalDatabase($exception) ?? throw $exception,
            ]);
        }

        return redirect()->route('tenaga-kerja.ketidakhadiran.index')
            ->with('success', 'Pengajuan '.strtolower($valid['jenis']).' terkirim dan menunggu persetujuan HRD.');
    }

    public function batalkan(int $pengajuan): RedirectResponse
    {
        $jumlah = DB::table('tbl_pengajuan_ketidakhadiran')
            ->where('id', $pengajuan)
            ->where('id_karyawan', (int) KaryawanAktif::id())
            ->where('status', 'Menunggu')
            ->update(['status' => 'Dibatalkan', 'diproses_pada' => now(), 'updated_at' => now()]);

        if ($jumlah === 0) {
            throw ValidationException::withMessages(['pengajuan' => 'Pengajuan tidak ditemukan atau sudah diproses HRD.']);
        }

        return redirect()->route('tenaga-kerja.ketidakhadiran.index')->with('success', 'Pengajuan dibatalkan.');
    }
}

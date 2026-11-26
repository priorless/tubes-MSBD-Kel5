<?php

namespace App\Http\Controllers\TenagaKerja;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenagaKerja\KoreksiLaporanAktivitasRequest;
use App\Http\Requests\TenagaKerja\StoreLaporanAktivitasRequest;
use App\Services\LaporanAktivitasService;
use App\Services\TenagaKerjaService;
use App\Support\AbsensiDitolak;
use App\Support\FotoAbsensiTidakValid;
use App\Support\KaryawanAktif;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;

/**
 * Laporan aktivitas selama sesi absensi: monitoring kapan saja (Satpam & Cleaning Service)
 * dan catatan perjalanan (Driver). Pelapor selalu karyawan yang sedang login.
 */
class LaporanAktivitasController extends Controller
{
    public function store(StoreLaporanAktivitasRequest $request, LaporanAktivitasService $laporan, TenagaKerjaService $tenagaKerja): RedirectResponse
    {
        $idKaryawan = (int) KaryawanAktif::id();
        $validated = $request->validated();

        return $this->jalankan($laporan, function () use ($request, $laporan, $tenagaKerja, $idKaryawan, $validated): string {
            $laporan->buatLaporan(
                $idKaryawan,
                $tenagaKerja->namaLayanan($idKaryawan),
                (string) $request->file('foto')->get(),
                trim($validated['keterangan']),
            );

            return 'Laporan aktivitas berhasil dikirim.';
        });
    }

    public function koreksi(KoreksiLaporanAktivitasRequest $request, int $laporanAktivitas, LaporanAktivitasService $laporan): RedirectResponse
    {
        $idKaryawan = (int) KaryawanAktif::id();
        $validated = $request->validated();

        return $this->jalankan($laporan, function () use ($request, $laporan, $laporanAktivitas, $idKaryawan, $validated): string {
            $laporan->koreksiLaporan(
                $idKaryawan,
                $laporanAktivitas,
                (string) $request->file('foto')->get(),
                filled($validated['keterangan'] ?? null) ? trim($validated['keterangan']) : null,
            );

            return 'Koreksi foto berhasil diunggah.';
        });
    }

    /**
     * @param  callable(): string  $aksi
     */
    private function jalankan(LaporanAktivitasService $laporan, callable $aksi): RedirectResponse
    {
        $kembali = redirect()->to(route('tenaga-kerja.absensi').'#monitoring');

        if (! $laporan->tersedia()) {
            return $kembali->with('gagal', 'Fitur laporan aktivitas belum tersedia. Hubungi bagian Operasional.');
        }

        try {
            return $kembali->with('sukses', $aksi());
        } catch (AbsensiDitolak|FotoAbsensiTidakValid $e) {
            return $kembali->withInput()->with('gagal', $e->getMessage());
        } catch (QueryException $e) {
            return $kembali->withInput()->with('gagal', TenagaKerjaService::pesanError($e));
        }
    }
}

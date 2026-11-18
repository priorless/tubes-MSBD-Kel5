<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use App\Services\PertukaranShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Beranda Operasional: ringkasan singkat dari data asli dan daftar pekerjaan yang perlu diperhatikan.
 * Hanya membaca data; tidak ada perubahan pada tabel, procedure, atau view database.
 */
class BerandaOperasionalController extends Controller
{
    private const int BATAS_HARI_KONTRAK = 30;

    private const int BATAS_DAFTAR = 5;

    public function __invoke(Request $request, PertukaranShiftService $pertukaranShiftService): View
    {
        $hariIni = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->startOfDay();
        $awalBulan = $hariIni->startOfMonth();
        $akhirBulan = $hariIni->endOfMonth()->startOfDay();

        $penempatanAktif = DB::table('tbl_penempatan as p')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->where('p.status', 'Aktif')
            ->orderBy('k.nama')
            ->get(['p.id', 'p.id_karyawan', 'p.tgl_mulai', 'p.tgl_selesai', 'k.nip', 'k.nama', 'kl.nama_perusahaan']);
        $berlakuHariIni = $penempatanAktif->filter(fn (object $item): bool => $this->berlakuPada($item, $hariIni));
        $kelengkapanJadwal = $this->kelengkapanJadwal($penempatanAktif, $awalBulan, $akhirBulan);

        return view('operasional.beranda', [
            'namaPengguna' => $request->user()->namaTampilan(),
            'namaBulan' => $awalBulan->locale('id')->translatedFormat('F Y'),
            'bulan' => $awalBulan->format('Y-m'),
            'jumlahKlienAktif' => DB::table('tbl_klien')->where('status', 'Aktif')->count(),
            'jumlahKaryawanDitempatkan' => $berlakuHariIni->pluck('id_karyawan')->unique()->count(),
            'jadwalTersedia' => $kelengkapanJadwal !== null,
            'jumlahTanggalTerjadwal' => $kelengkapanJadwal?->sum('terjadwal') ?? 0,
            'jumlahTanggalBelumDiatur' => $kelengkapanJadwal?->sum('belum_diatur') ?? 0,
            'jadwalBelumLengkap' => $kelengkapanJadwal?->where('belum_diatur', '>', 0)->sortByDesc('belum_diatur')->values() ?? collect(),
            'kontrakSegeraBerakhir' => $berlakuHariIni
                ->filter(fn (object $item): bool => $item->tgl_selesai !== null
                    && $item->tgl_selesai <= $hariIni->addDays(self::BATAS_HARI_KONTRAK)->toDateString())
                ->map(fn (object $item): object => (object) [
                    ...(array) $item,
                    'sisa_hari' => (int) $hariIni->diffInDays(CarbonImmutable::parse($item->tgl_selesai)),
                ])
                ->sortBy('tgl_selesai')
                ->values(),
            'penempatanLewatMasa' => $penempatanAktif
                ->filter(fn (object $item): bool => $item->tgl_selesai !== null && $item->tgl_selesai < $hariIni->toDateString())
                ->sortBy('tgl_selesai')
                ->values(),
            'pertukaranShiftTersedia' => $pertukaranShiftService->tersedia(),
            'ringkasanPertukaranShift' => $pertukaranShiftService->ringkasanStatus(),
            'batasDaftar' => self::BATAS_DAFTAR,
            'batasHariKontrak' => self::BATAS_HARI_KONTRAK,
        ]);
    }

    /**
     * Per penempatan Aktif yang beririsan dengan bulan ini: jumlah tanggal wajib dalam masa penempatan,
     * yang sudah terjadwal (Kerja/Libur), dan yang Belum Diatur. Null bila tabel jadwal belum tersedia.
     *
     * @param  Collection<int, object>  $penempatanAktif
     * @return Collection<int, object>|null
     */
    private function kelengkapanJadwal(Collection $penempatanAktif, CarbonImmutable $awalBulan, CarbonImmutable $akhirBulan): ?Collection
    {
        if (! Schema::hasTable('tbl_jadwal_kerja')) {
            return null;
        }

        $penempatanBulanIni = $penempatanAktif->filter(fn (object $item): bool => $item->tgl_mulai <= $akhirBulan->toDateString()
            && ($item->tgl_selesai === null || $item->tgl_selesai >= $awalBulan->toDateString()));
        $terjadwal = DB::table('tbl_jadwal_kerja')
            ->whereIn('id_penempatan', $penempatanBulanIni->pluck('id')->all())
            ->whereBetween('tanggal_kerja', [$awalBulan->toDateString(), $akhirBulan->toDateString()])
            ->groupBy('id_penempatan')
            ->selectRaw('id_penempatan, COUNT(*) as jumlah')
            ->pluck('jumlah', 'id_penempatan');

        return $penempatanBulanIni->map(function (object $item) use ($awalBulan, $akhirBulan, $terjadwal): object {
            $mulai = CarbonImmutable::parse(max($item->tgl_mulai, $awalBulan->toDateString()));
            $selesai = CarbonImmutable::parse(min($item->tgl_selesai ?? $akhirBulan->toDateString(), $akhirBulan->toDateString()));
            $wajib = (int) $mulai->diffInDays($selesai) + 1;
            $sudah = (int) ($terjadwal[$item->id] ?? 0);

            return (object) [...(array) $item, 'terjadwal' => $sudah, 'belum_diatur' => max(0, $wajib - $sudah)];
        })->values();
    }

    private function berlakuPada(object $penempatan, CarbonImmutable $tanggal): bool
    {
        return $penempatan->tgl_mulai <= $tanggal->toDateString()
            && ($penempatan->tgl_selesai === null || $penempatan->tgl_selesai >= $tanggal->toDateString());
    }
}

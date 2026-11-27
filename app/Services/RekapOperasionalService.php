<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rekap kerja bulanan: membandingkan jadwal Operasional (tbl_jadwal_kerja) dengan realisasi Absensi (tbl_absensi).
 * Hanya membaca data. Rumus absensi dan payroll tidak diubah:
 * - jam kerja tercatat mengikuti fn_hitung_jam_kerja (GREATEST(0, TIMESTAMPDIFF(HOUR, masuk, keluar))),
 * - semua jam lembur tercatat diteruskan ke Keuangan (tanpa persetujuan terpisah).
 * Absensi dihitung hanya pada tanggal yang berada dalam masa penempatan yang direkap.
 */
class RekapOperasionalService
{
    /**
     * @return array{
     *     bulan: CarbonImmutable,
     *     kolom: array{menit_terlambat: bool},
     *     baris: Collection<int, object>,
     *     total: array<string, int>,
     *     catatan: Collection<int, object>
     * }
     */
    public function rekap(string $bulan, ?int $idKlien = null, ?int $idKaryawan = null): array
    {
        $awalBulan = CarbonImmutable::parse($bulan.'-01')->startOfDay();
        $akhirBulan = $awalBulan->endOfMonth()->startOfDay();
        $hariIni = CarbonImmutable::now()->toDateString();
        $kolom = [
            'menit_terlambat' => Schema::hasColumn('tbl_absensi', 'menit_terlambat'),
        ];

        $penempatan = DB::table('tbl_penempatan as p')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->where('p.tgl_mulai', '<=', $akhirBulan->toDateString())
            ->where(fn ($query) => $query->whereNull('p.tgl_selesai')->orWhere('p.tgl_selesai', '>=', $awalBulan->toDateString()))
            ->when($idKlien !== null, fn ($query) => $query->where('p.id_klien', $idKlien))
            ->when($idKaryawan !== null, fn ($query) => $query->where('p.id_karyawan', $idKaryawan))
            ->orderBy('k.nama')
            ->get(['p.id', 'p.id_karyawan', 'p.tgl_mulai', 'p.tgl_selesai', 'k.nip', 'k.nama', 'kl.nama_perusahaan']);

        $adaPengganti = Schema::hasTable('tbl_jadwal_kerja') && Schema::hasColumn('tbl_jadwal_kerja', 'id_karyawan_pengganti');
        $jadwal = Schema::hasTable('tbl_jadwal_kerja') && $penempatan->isNotEmpty()
            ? DB::table('tbl_jadwal_kerja')
                ->whereIn('id_penempatan', $penempatan->pluck('id')->all())
                ->whereBetween('tanggal_kerja', [$awalBulan->toDateString(), $akhirBulan->toDateString()])
                ->get(array_values(array_filter(['id_penempatan', 'tanggal_kerja', 'status', $adaPengganti ? 'id_karyawan_pengganti' : null])))
                ->keyBy(static fn (object $baris): string => $baris->id_penempatan.'|'.$baris->tanggal_kerja)
            : collect();
        // Backup shift: tanggal yang dikerjakan karyawan sebagai pengganti rekan (penempatan milik rekan).
        $backup = $adaPengganti && $penempatan->isNotEmpty()
            ? DB::table('tbl_jadwal_kerja')
                ->whereIn('id_karyawan_pengganti', $penempatan->pluck('id_karyawan')->unique()->all())
                ->where('status', 'Kerja')
                ->whereBetween('tanggal_kerja', [$awalBulan->toDateString(), $akhirBulan->toDateString()])
                ->get(['id_karyawan_pengganti', 'tanggal_kerja'])
                ->groupBy('id_karyawan_pengganti')
            : collect();
        $absensi = $penempatan->isEmpty() ? collect() : DB::table('tbl_absensi')
            ->whereIn('id_karyawan', $penempatan->pluck('id_karyawan')->unique()->all())
            ->whereBetween('tgl', [$awalBulan->toDateString(), $akhirBulan->toDateString()])
            ->get(array_values(array_filter([
                'id_karyawan', 'tgl', 'jam_masuk', 'jam_keluar', 'status_kehadiran', 'jam_lembur',
                $kolom['menit_terlambat'] ? 'menit_terlambat' : null,
            ])))
            ->groupBy('id_karyawan');

        $baris = $penempatan->groupBy('id_karyawan')->map(function (Collection $daftar) use ($awalBulan, $akhirBulan, $hariIni, $jadwal, $backup, $absensi, $kolom): object {
            $data = $this->barisKosong($daftar);
            $tanggalPenempatan = [];

            foreach ($daftar as $item) {
                $mulai = max($item->tgl_mulai, $awalBulan->toDateString());
                $selesai = min($item->tgl_selesai ?? $akhirBulan->toDateString(), $akhirBulan->toDateString());

                for ($tanggal = CarbonImmutable::parse($mulai); $tanggal->toDateString() <= $selesai; $tanggal = $tanggal->addDay()) {
                    $hariJadwal = $jadwal->get($item->id.'|'.$tanggal->toDateString());
                    // Jadwal yang digantikan rekan bukan hari kerja pemiliknya (tidak dihitung tanpa absensi).
                    $tanggalPenempatan[$tanggal->toDateString()] = $hariJadwal?->status === 'Kerja' && ($hariJadwal->id_karyawan_pengganti ?? null) !== null
                        ? 'Libur'
                        : $hariJadwal?->status;
                }
            }

            foreach ($backup->get($daftar->first()->id_karyawan, collect()) as $hariBackup) {
                if (array_key_exists((string) $hariBackup->tanggal_kerja, $tanggalPenempatan)) {
                    $tanggalPenempatan[(string) $hariBackup->tanggal_kerja] = 'Kerja';
                }
            }

            $absensiPerTanggal = $absensi->get($daftar->first()->id_karyawan, collect())
                ->filter(static fn (object $hari): bool => array_key_exists((string) $hari->tgl, $tanggalPenempatan))
                ->keyBy(static fn (object $hari): string => (string) $hari->tgl);

            foreach ($tanggalPenempatan as $tanggal => $statusJadwal) {
                $hari = $absensiPerTanggal->get($tanggal);
                match ($statusJadwal) {
                    'Kerja' => $data->kerja_terjadwal++,
                    'Libur' => $data->libur_terjadwal++,
                    default => $data->belum_diatur++,
                };

                if ($statusJadwal === 'Kerja' && $hari === null && $tanggal < $hariIni) {
                    $data->tanggal_tanpa_absensi[] = $tanggal;
                }

                if ($hari === null) {
                    continue;
                }

                $hadir = in_array($hari->status_kehadiran, ['Hadir', 'Terlambat'], true);
                $data->hadir += $hadir ? 1 : 0;
                $data->terlambat += $hari->status_kehadiran === 'Terlambat' ? 1 : 0;
                $data->izin += $hari->status_kehadiran === 'Izin' ? 1 : 0;
                $data->sakit += $hari->status_kehadiran === 'Sakit' ? 1 : 0;
                $data->cuti += $hari->status_kehadiran === 'Cuti' ? 1 : 0;
                $data->alpha += $hari->status_kehadiran === 'Alpha' ? 1 : 0;
                $data->menit_terlambat += $kolom['menit_terlambat'] ? (int) $hari->menit_terlambat : 0;
                $data->jam_kerja += $this->jamKerjaTercatat($hari->jam_masuk, $hari->jam_keluar);
                $data->jam_lembur += (int) $hari->jam_lembur;

                if ($hadir && $statusJadwal !== 'Kerja') {
                    $data->tanggal_hadir_tanpa_jadwal_kerja[] = $tanggal;
                }

                if ($hadir && $hari->jam_keluar === null && $tanggal < $hariIni) {
                    $data->tanggal_tanpa_jam_keluar[] = $tanggal;
                }

                if ($hari->jam_masuk !== null && $hari->jam_keluar !== null && $hari->jam_keluar <= $hari->jam_masuk) {
                    $data->tanggal_lintas_tengah_malam[] = $tanggal;
                }
            }

            $data->tidak_hadir = $data->izin + $data->sakit + $data->cuti + $data->alpha + count($data->tanggal_tanpa_absensi);

            return $data;
        })->values();

        return [
            'bulan' => $awalBulan,
            'kolom' => $kolom,
            'baris' => $baris,
            'total' => [
                'karyawan' => $baris->count(),
                'kerja_terjadwal' => (int) $baris->sum('kerja_terjadwal'),
                'hadir' => (int) $baris->sum('hadir'),
                'tidak_hadir' => (int) $baris->sum('tidak_hadir'),
                'terlambat' => (int) $baris->sum('terlambat'),
                'jam_kerja' => (int) $baris->sum('jam_kerja'),
                'jam_lembur' => (int) $baris->sum('jam_lembur'),
            ],
            'catatan' => $this->catatanBelumLengkap($baris),
        ];
    }

    /**
     * Sama dengan fn_hitung_jam_kerja di database: jam penuh, tidak negatif, tanpa penyesuaian lewat tengah malam.
     */
    public function jamKerjaTercatat(?string $jamMasuk, ?string $jamKeluar): int
    {
        if ($jamMasuk === null || $jamKeluar === null) {
            return 0;
        }

        $detik = strtotime('1970-01-01 '.$jamKeluar.' UTC') - strtotime('1970-01-01 '.$jamMasuk.' UTC');

        return max(0, intdiv($detik, 3600));
    }

    /**
     * @param  Collection<int, object>  $daftar  penempatan satu karyawan dalam bulan rekap
     */
    private function barisKosong(Collection $daftar): object
    {
        $pertama = $daftar->first();

        return (object) [
            'id_karyawan' => (int) $pertama->id_karyawan,
            'nip' => $pertama->nip,
            'nama' => $pertama->nama,
            'klien' => $daftar->pluck('nama_perusahaan')->unique()->implode(', '),
            'kerja_terjadwal' => 0,
            'libur_terjadwal' => 0,
            'belum_diatur' => 0,
            'hadir' => 0,
            'terlambat' => 0,
            'menit_terlambat' => 0,
            'izin' => 0,
            'sakit' => 0,
            'cuti' => 0,
            'alpha' => 0,
            'tidak_hadir' => 0,
            'jam_kerja' => 0,
            'jam_lembur' => 0,
            'tanggal_tanpa_absensi' => [],
            'tanggal_hadir_tanpa_jadwal_kerja' => [],
            'tanggal_tanpa_jam_keluar' => [],
            'tanggal_lintas_tengah_malam' => [],
        ];
    }

    /**
     * @param  Collection<int, object>  $baris
     * @return Collection<int, object>
     */
    private function catatanBelumLengkap(Collection $baris): Collection
    {
        $jenis = [
            'belum_diatur' => 'tanggal jadwal belum diatur',
            'tanggal_tanpa_absensi' => 'hari kerja terjadwal tanpa catatan absensi',
            'tanggal_hadir_tanpa_jadwal_kerja' => 'kehadiran pada tanggal yang tidak dijadwalkan kerja',
            'tanggal_tanpa_jam_keluar' => 'kehadiran tanpa jam keluar',
            'tanggal_lintas_tengah_malam' => 'jam keluar lebih awal dari jam masuk (tercatat 0 jam)',
        ];

        return $baris->flatMap(static function (object $data) use ($jenis): array {
            $hasil = [];

            foreach ($jenis as $kunci => $keterangan) {
                $nilai = $data->{$kunci};
                $jumlah = is_array($nilai) ? count($nilai) : (int) $nilai;

                if ($jumlah > 0) {
                    $hasil[] = (object) [
                        'id_karyawan' => $data->id_karyawan,
                        'nama' => $data->nama,
                        'jumlah' => $jumlah,
                        'keterangan' => $keterangan,
                        'tanggal' => is_array($nilai) ? array_slice($nilai, 0, 5) : [],
                    ];
                }
            }

            return $hasil;
        })->values();
    }
}

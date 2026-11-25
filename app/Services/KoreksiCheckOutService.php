<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Perbaikan lupa check-out (tbl_koreksi_check_out).
 *
 * Absensi dianggap lupa check-out bila sudah check-in, belum check-out, dan sesinya sudah ditutup
 * (lebih dari BATAS_SESI_JAM sejak check-in, sama dengan fn_id_absensi_aktif & v_absensi_harian.lupa_check_out).
 * Karyawan mengajukan jam keluar + alasan, HRD menyetujui/menolak; HRD juga dapat langsung mengisi jam keluar.
 * Saat disetujui jam_keluar di-UPDATE dari NULL sehingga trigger trg_auto_hitung_lembur menghitung jam_lembur
 * seperti check-out biasa (dibulatkan ke bawah per jam penuh). Satu absensi hanya boleh punya satu pengajuan Menunggu.
 */
class KoreksiCheckOutService
{
    public const string STATUS_MENUNGGU = 'Menunggu';

    public const string STATUS_DISETUJUI = 'Disetujui';

    public const string STATUS_DITOLAK = 'Ditolak';

    /** Sesi absensi ditutup setelah sekian jam sejak check-in (fn_id_absensi_aktif). */
    public const int BATAS_SESI_JAM = 16;

    /** Rentang hari daftar lupa check-out di halaman HRD. */
    public const int HARI_DAFTAR_HRD = 60;

    public function tersedia(): bool
    {
        return Schema::hasTable('tbl_koreksi_check_out');
    }

    /**
     * Absensi milik karyawan yang lupa check-out, terbaru dahulu, beserta pengajuan terakhirnya (bila ada).
     *
     * @return Collection<int, object>
     */
    public function absensiLupaCheckOut(int $idKaryawan): Collection
    {
        if (! $this->tersedia()) {
            return collect();
        }

        return DB::table('tbl_absensi as a')
            ->leftJoin('tbl_koreksi_check_out as kc', fn (JoinClause $join) => $join->on('kc.id_absensi', '=', 'a.id')
                ->where('kc.status', self::STATUS_MENUNGGU))
            ->where('a.id_karyawan', $idKaryawan)
            ->whereNotNull('a.jam_masuk')
            ->whereNull('a.jam_keluar')
            ->orderByDesc('a.tgl')
            ->get(['a.id', 'a.tgl', 'a.jam_masuk', 'kc.id as id_koreksi', 'kc.jam_keluar as jam_keluar_diajukan'])
            ->filter(fn (object $absensi): bool => $this->sesiSudahDitutup($absensi))
            ->values();
    }

    /**
     * Semua absensi lupa check-out dalam HARI_DAFTAR_HRD hari terakhir (untuk HRD), terbaru dahulu.
     *
     * @return Collection<int, object>
     */
    public function semuaLupaCheckOut(): Collection
    {
        if (! $this->tersedia()) {
            return collect();
        }

        return DB::table('tbl_absensi as a')
            ->join('tbl_karyawan as k', 'k.id', '=', 'a.id_karyawan')
            ->leftJoin('tbl_koreksi_check_out as kc', fn (JoinClause $join) => $join->on('kc.id_absensi', '=', 'a.id')
                ->where('kc.status', self::STATUS_MENUNGGU))
            ->whereNotNull('a.jam_masuk')
            ->whereNull('a.jam_keluar')
            ->where('a.tgl', '>=', CarbonImmutable::now()->subDays(self::HARI_DAFTAR_HRD)->toDateString())
            ->orderByDesc('a.tgl')
            ->get(['a.id', 'a.tgl', 'a.jam_masuk', 'k.nama', 'k.nip', 'kc.id as id_koreksi'])
            ->filter(fn (object $absensi): bool => $this->sesiSudahDitutup($absensi))
            ->values();
    }

    /**
     * Karyawan mengajukan jam keluar untuk absensi miliknya yang lupa check-out.
     */
    public function ajukan(int $idKaryawan, int $idAbsensi, string $jamKeluar, string $alasan, int $idUser): int
    {
        $id = DB::transaction(function () use ($idKaryawan, $idAbsensi, $jamKeluar, $alasan, $idUser): int {
            $absensi = $this->absensiLupa($idAbsensi, $idKaryawan);
            $this->pastikanJamKeluarValid($absensi, $jamKeluar);

            try {
                return (int) DB::table('tbl_koreksi_check_out')->insertGetId([
                    'id_absensi' => $absensi->id,
                    'jam_keluar' => $jamKeluar.':00',
                    'alasan' => $alasan,
                    'status' => self::STATUS_MENUNGGU,
                    'diajukan_oleh' => $idUser,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $this->gagal('id_absensi', 'Absensi ini sudah memiliki pengajuan koreksi check-out yang menunggu HRD.');
            }
        });

        return $id;
    }

    /**
     * HRD menyetujui pengajuan: jam_keluar absensi diisi (lembur dihitung trigger).
     */
    public function setujui(int $idKoreksi, int $idUserHrd): void
    {
        DB::transaction(function () use ($idKoreksi, $idUserHrd): void {
            $koreksi = $this->ambilMenunggu($idKoreksi);
            $absensi = $this->absensiLupa((int) $koreksi->id_absensi);
            $jam = substr((string) $koreksi->jam_keluar, 0, 5);
            $this->pastikanJamKeluarValid($absensi, $jam);

            $this->isiJamKeluar((int) $absensi->id, $jam);
            $this->simpanKeputusan($idKoreksi, self::STATUS_DISETUJUI, $idUserHrd, null);

        });
    }

    public function tolak(int $idKoreksi, int $idUserHrd, ?string $catatan): void
    {
        DB::transaction(function () use ($idKoreksi, $idUserHrd, $catatan): void {
            $koreksi = $this->ambilMenunggu($idKoreksi);
            $this->simpanKeputusan($idKoreksi, self::STATUS_DITOLAK, $idUserHrd, $catatan);

        });
    }

    /**
     * HRD langsung mengisi jam keluar (tercatat sebagai koreksi Disetujui). Pengajuan Menunggu pada absensi
     * yang sama ikut ditolak dengan catatan bahwa HRD sudah mengisi jam keluar.
     */
    public function isiLangsung(int $idAbsensi, string $jamKeluar, string $alasan, int $idUserHrd): void
    {
        DB::transaction(function () use ($idAbsensi, $jamKeluar, $alasan, $idUserHrd): void {
            $absensi = $this->absensiLupa($idAbsensi);
            $this->pastikanJamKeluarValid($absensi, $jamKeluar);

            DB::table('tbl_koreksi_check_out')
                ->where('id_absensi', $idAbsensi)
                ->where('status', self::STATUS_MENUNGGU)
                ->update([
                    'status' => self::STATUS_DITOLAK,
                    'catatan_keputusan' => 'Jam keluar sudah diisi langsung oleh HRD.',
                    'diproses_oleh' => $idUserHrd,
                    'diproses_pada' => now(),
                    'updated_at' => now(),
                ]);

            $this->isiJamKeluar($idAbsensi, $jamKeluar);
            DB::table('tbl_koreksi_check_out')->insert([
                'id_absensi' => $idAbsensi,
                'jam_keluar' => $jamKeluar.':00',
                'alasan' => $alasan,
                'status' => self::STATUS_DISETUJUI,
                'diajukan_oleh' => $idUserHrd,
                'diproses_oleh' => $idUserHrd,
                'diproses_pada' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

    }

    /**
     * Pengajuan Menunggu untuk HRD, terlama dahulu.
     *
     * @return Collection<int, object>
     */
    public function daftarMenunggu(): Collection
    {
        if (! $this->tersedia()) {
            return collect();
        }

        return $this->queryDaftar()
            ->where('kc.status', self::STATUS_MENUNGGU)
            ->orderBy('kc.created_at')
            ->get();
    }

    /**
     * Pengajuan milik karyawan, terbaru dahulu.
     *
     * @return Collection<int, object>
     */
    public function riwayatKaryawan(int $idKaryawan, int $batas = 10): Collection
    {
        if (! $this->tersedia()) {
            return collect();
        }

        return $this->queryDaftar()
            ->where('a.id_karyawan', $idKaryawan)
            ->orderByDesc('kc.created_at')
            ->limit($batas)
            ->get();
    }

    /** True bila sesi absensi sudah ditutup (lebih dari BATAS_SESI_JAM sejak check-in). */
    public function sesiSudahDitutup(object $absensi): bool
    {
        return $this->waktuMasuk($absensi)->addHours(self::BATAS_SESI_JAM)->isPast();
    }

    private function queryDaftar(): Builder
    {
        return DB::table('tbl_koreksi_check_out as kc')
            ->join('tbl_absensi as a', 'a.id', '=', 'kc.id_absensi')
            ->join('tbl_karyawan as k', 'k.id', '=', 'a.id_karyawan')
            ->leftJoin('tbl_users as up', 'up.id', '=', 'kc.diproses_oleh')
            ->select(['kc.*', 'a.tgl', 'a.jam_masuk', 'a.id_karyawan', 'k.nama', 'k.nip',
                DB::raw('COALESCE(up.nama, up.username) as nama_pemroses')]);
    }

    /**
     * Absensi yang masih lupa check-out (dikunci). $idKaryawan null = tanpa batas pemilik (HRD).
     */
    private function absensiLupa(int $idAbsensi, ?int $idKaryawan = null): object
    {
        $absensi = DB::table('tbl_absensi')
            ->where('id', $idAbsensi)
            ->when($idKaryawan !== null, fn (Builder $query) => $query->where('id_karyawan', $idKaryawan))
            ->lockForUpdate()
            ->first();

        if ($absensi === null) {
            $this->gagal('id_absensi', 'Data absensi tidak ditemukan.');
        }

        if (blank($absensi->jam_masuk) || filled($absensi->jam_keluar)) {
            $this->gagal('id_absensi', 'Absensi ini tidak memerlukan koreksi check-out.');
        }

        if (! $this->sesiSudahDitutup($absensi)) {
            $this->gagal('id_absensi', 'Sesi absensi ini masih berjalan. Lakukan check-out seperti biasa.');
        }

        return $absensi;
    }

    /**
     * Jam keluar (H:i) harus setelah check-in, paling lama BATAS_SESI_JAM jam setelahnya, dan tidak di masa depan.
     * Jam yang lebih kecil dari jam masuk berarti keesokan harinya (shift malam).
     */
    private function pastikanJamKeluarValid(object $absensi, string $jamKeluar): void
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jamKeluar) !== 1) {
            $this->gagal('jam_keluar', 'Format jam keluar harus JJ:MM.');
        }

        $masuk = $this->waktuMasuk($absensi);
        $keluar = CarbonImmutable::parse($masuk->toDateString().' '.$jamKeluar);
        $keluar = $keluar->lte($masuk) ? $keluar->addDay() : $keluar;

        if ($keluar->diffInMinutes($masuk, true) > self::BATAS_SESI_JAM * 60) {
            $this->gagal('jam_keluar', 'Jam keluar paling lambat '.self::BATAS_SESI_JAM.' jam setelah check-in.');
        }

        if ($keluar->isFuture()) {
            $this->gagal('jam_keluar', 'Jam keluar tidak boleh di masa depan.');
        }
    }

    private function isiJamKeluar(int $idAbsensi, string $jamKeluar): void
    {
        $diubah = DB::table('tbl_absensi')
            ->where('id', $idAbsensi)
            ->whereNull('jam_keluar')
            ->update(['jam_keluar' => $jamKeluar.':00']);

        if ($diubah !== 1) {
            $this->gagal('id_absensi', 'Jam keluar absensi ini sudah tercatat.');
        }
    }

    private function ambilMenunggu(int $idKoreksi): object
    {
        $koreksi = DB::table('tbl_koreksi_check_out')->where('id', $idKoreksi)->lockForUpdate()->first();

        if ($koreksi === null) {
            $this->gagal('koreksi', 'Pengajuan koreksi check-out tidak ditemukan.');
        }

        if ($koreksi->status !== self::STATUS_MENUNGGU) {
            $this->gagal('koreksi', 'Pengajuan ini sudah diproses sebelumnya.');
        }

        return $koreksi;
    }

    private function simpanKeputusan(int $idKoreksi, string $status, int $idUser, ?string $catatan): void
    {
        DB::table('tbl_koreksi_check_out')
            ->where('id', $idKoreksi)
            ->where('status', self::STATUS_MENUNGGU)
            ->update([
                'status' => $status,
                'catatan_keputusan' => $catatan,
                'diproses_oleh' => $idUser,
                'diproses_pada' => now(),
                'updated_at' => now(),
            ]);
    }

    private function waktuMasuk(object $absensi): CarbonImmutable
    {
        return CarbonImmutable::parse(substr((string) $absensi->tgl, 0, 10).' '.substr((string) $absensi->jam_masuk, 0, 8));
    }

    private function gagal(string $kunci, string $pesan): never
    {
        throw ValidationException::withMessages([$kunci => $pesan]);
    }
}

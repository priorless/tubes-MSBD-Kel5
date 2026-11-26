<?php

namespace App\Services;

use App\Support\AbsensiDitolak;
use App\Support\ShiftAbsensi;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hak absensi berdasarkan jadwal shift dari Operasional.
 *
 * Urutan jadwal (sama dengan fn_jadwal_absensi di sql/Komponen/13_jadwal_absensi_dari_roster.sql):
 *   0. Backup shift: jadwal Kerja rekan yang id_karyawan_pengganti-nya karyawan ini (pengajuan sudah disetujui)
 *      -> shift tersebut, dicatat pada penempatan rekan yang digantikan (klien boleh berbeda);
 *   1. tbl_jadwal_kerja status 'Kerja' miliknya sendiri -> shift harian; bila sudah digantikan rekan -> 'Digantikan';
 *   2. tbl_jadwal_kerja status 'Libur' -> tidak boleh absen;
 *   3. belum diatur -> jam_datang/jam_pulang penempatan; keduanya kosong -> tidak boleh absen.
 * Pengajuan backup shift yang belum/tidak disetujui tidak pernah mengubah tbl_jadwal_kerja, sehingga tidak berpengaruh.
 */
class JadwalAbsensiService
{
    public const string STATUS_KERJA = 'Kerja';

    public const string STATUS_LIBUR = 'Libur';

    public const string STATUS_TANPA_JADWAL = 'Tanpa Jadwal';

    public const string STATUS_TANPA_PENEMPATAN = 'Tanpa Penempatan';

    /** Jadwal Kerja miliknya sudah digantikan rekan lewat backup shift yang disetujui. */
    public const string STATUS_DIGANTIKAN = 'Digantikan';

    public const string SUMBER_BACKUP = 'Backup Shift';

    private ?bool $adaTabelJadwal = null;

    private ?bool $adaKolomPengganti = null;

    /**
     * Jadwal efektif karyawan pada satu tanggal.
     *
     * @return array{status: string, shift: ShiftAbsensi|null}
     */
    public function jadwalPada(int $idKaryawan, string $tanggal, ?int $idPenempatan = null): array
    {
        $backup = $this->jadwalBackup($idKaryawan, $tanggal, $idPenempatan);

        if ($backup !== null) {
            return ['status' => self::STATUS_KERJA, 'shift' => $this->buatShift(
                $tanggal, (string) $backup->jam_masuk, (string) $backup->jam_pulang,
                $backup->durasi_menit !== null ? (int) $backup->durasi_menit : null,
                self::SUMBER_BACKUP, $backup->nama_shift_snapshot, (int) $backup->id_penempatan,
            )];
        }

        $penempatan = DB::table('tbl_penempatan')
            ->when($idPenempatan !== null,
                fn (Builder $query) => $query->where('id', $idPenempatan),
                fn (Builder $query) => $query->where('status', 'Aktif')
                    ->where('tgl_mulai', '<=', $tanggal)
                    ->where(fn (Builder $query) => $query->whereNull('tgl_selesai')->orWhere('tgl_selesai', '>=', $tanggal)))
            ->where('id_karyawan', $idKaryawan)
            ->orderByDesc('tgl_mulai')
            ->orderByDesc('id')
            ->first(['id', 'jam_datang', 'jam_pulang']);

        if ($penempatan === null) {
            return ['status' => self::STATUS_TANPA_PENEMPATAN, 'shift' => null];
        }

        $jadwal = $this->adaTabelJadwal()
            ? DB::table('tbl_jadwal_kerja')->where('id_penempatan', $penempatan->id)->where('tanggal_kerja', $tanggal)->first()
            : null;

        if ($jadwal?->status === self::STATUS_KERJA && ($jadwal->id_karyawan_pengganti ?? null) !== null) {
            return ['status' => self::STATUS_DIGANTIKAN, 'shift' => null];
        }

        if ($jadwal?->status === self::STATUS_LIBUR) {
            return ['status' => self::STATUS_LIBUR, 'shift' => null];
        }

        if ($jadwal?->status === self::STATUS_KERJA && filled($jadwal->jam_masuk) && filled($jadwal->jam_pulang)) {
            return ['status' => self::STATUS_KERJA, 'shift' => $this->buatShift(
                $tanggal, (string) $jadwal->jam_masuk, (string) $jadwal->jam_pulang,
                $jadwal->durasi_menit !== null ? (int) $jadwal->durasi_menit : null,
                'Jadwal Harian', $jadwal->nama_shift_snapshot, (int) $penempatan->id,
            )];
        }

        if (filled($penempatan->jam_datang) && filled($penempatan->jam_pulang)) {
            return ['status' => self::STATUS_KERJA, 'shift' => $this->buatShift(
                $tanggal, (string) $penempatan->jam_datang, (string) $penempatan->jam_pulang, null,
                'Penempatan', null, (int) $penempatan->id,
            )];
        }

        return ['status' => self::STATUS_TANPA_JADWAL, 'shift' => null];
    }

    /**
     * Shift yang check-in-nya sedang terbuka. Shift kemarin yang melewati tengah malam dan masih
     * berlangsung didahulukan (absensi dicatat pada tanggal shift itu).
     *
     * @throws AbsensiDitolak
     */
    public function shiftUntukCheckIn(int $idKaryawan, CarbonImmutable $sekarang): ShiftAbsensi
    {
        $kemarin = $this->jadwalPada($idKaryawan, $sekarang->subDay()->toDateString())['shift'];

        if ($kemarin !== null && $sekarang->lt($kemarin->selesai) && $sekarang->gte($kemarin->bukaCheckIn())
            && ! DB::table('tbl_absensi')->where('id_karyawan', $idKaryawan)->where('tgl', $kemarin->tanggal)->exists()) {
            return $kemarin;
        }

        ['status' => $status, 'shift' => $shift] = $this->jadwalPada($idKaryawan, $sekarang->toDateString());

        if ($shift === null) {
            throw new AbsensiDitolak($this->pesanTanpaShift($status));
        }

        if ($sekarang->lt($shift->bukaCheckIn())) {
            throw new AbsensiDitolak(sprintf(
                'Check-in belum dibuka. Shift Anda hari ini %s; check-in dibuka pukul %s (%d menit sebelum shift dimulai).',
                $shift->label(), $shift->bukaCheckIn()->format('H:i'), (int) config('tenaga_kerja.absensi.buka_check_in_menit', 30),
            ));
        }

        if ($sekarang->gte($shift->selesai)) {
            throw new AbsensiDitolak("Shift Anda hari ini ({$shift->label()}) sudah berakhir. Check-in tidak dapat dilakukan.");
        }

        return $shift;
    }

    /**
     * Roster harian karyawan dari tbl_jadwal_kerja (untuk Jadwal Saya dan hitungan hari wajib).
     * Penempatan dipilih berdasarkan masa berlakunya pada tanggal itu (apa pun status saat ini), sama
     * dengan fn_hari_kerja_roster. Status: Kerja | Backup | Digantikan | Libur | Belum Dijadwalkan | Tanpa Penempatan.
     * Hari wajib = Kerja + Backup.
     *
     * @return list<array{tanggal: string, status: string, shift: string|null, klien: string|null, rekan: string|null}>
     */
    public function rosterKaryawan(int $idKaryawan, string $awal, string $akhir): array
    {
        $penempatan = DB::table('tbl_penempatan as p')
            ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->where('p.id_karyawan', $idKaryawan)
            ->where('p.tgl_mulai', '<=', $akhir)
            ->where(fn (Builder $query) => $query->whereNull('p.tgl_selesai')->orWhere('p.tgl_selesai', '>=', $awal))
            ->orderByDesc('p.tgl_mulai')
            ->orderByDesc('p.id')
            ->get(['p.id', 'p.tgl_mulai', 'p.tgl_selesai', 'kl.nama_perusahaan']);

        $adaJadwal = $this->adaTabelJadwal();
        $milikSendiri = $adaJadwal && $penempatan->isNotEmpty()
            ? DB::table('tbl_jadwal_kerja as j')
                ->leftJoin('tbl_karyawan as kg', 'kg.id', '=', 'j.id_karyawan_pengganti')
                ->whereIn('j.id_penempatan', $penempatan->pluck('id')->all())
                ->whereBetween('j.tanggal_kerja', [$awal, $akhir])
                ->get(['j.*', 'kg.nama as nama_pengganti'])
                ->keyBy(fn (object $jadwal): string => $jadwal->id_penempatan.'|'.$jadwal->tanggal_kerja)
            : collect();
        $backup = $adaJadwal && Schema::hasColumn('tbl_jadwal_kerja', 'id_karyawan_pengganti')
            ? DB::table('tbl_jadwal_kerja as j')
                ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
                ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
                ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
                ->where('j.id_karyawan_pengganti', $idKaryawan)
                ->where('j.status', self::STATUS_KERJA)
                ->whereBetween('j.tanggal_kerja', [$awal, $akhir])
                ->get(['j.*', 'k.nama as nama_pemilik', 'kl.nama_perusahaan'])
                ->keyBy('tanggal_kerja')
            : collect();

        $roster = [];
        for ($hari = CarbonImmutable::parse($awal); $hari->toDateString() <= $akhir; $hari = $hari->addDay()) {
            $tanggal = $hari->toDateString();
            $item = ['tanggal' => $tanggal, 'status' => self::STATUS_TANPA_PENEMPATAN, 'shift' => null, 'klien' => null, 'rekan' => null];

            if (($jadwalBackup = $backup->get($tanggal)) !== null) {
                $roster[] = ['status' => 'Backup', 'shift' => $this->labelJadwal($jadwalBackup),
                    'klien' => $jadwalBackup->nama_perusahaan, 'rekan' => $jadwalBackup->nama_pemilik] + $item;

                continue;
            }

            $berlaku = $penempatan->first(fn (object $p): bool => $p->tgl_mulai <= $tanggal && ($p->tgl_selesai === null || $p->tgl_selesai >= $tanggal));

            if ($berlaku !== null) {
                $jadwal = $milikSendiri->get($berlaku->id.'|'.$tanggal);
                $item['klien'] = $berlaku->nama_perusahaan;
                $item['status'] = match (true) {
                    $jadwal === null => 'Belum Dijadwalkan',
                    $jadwal->status === self::STATUS_KERJA && $jadwal->id_karyawan_pengganti !== null => self::STATUS_DIGANTIKAN,
                    default => $jadwal->status,
                };
                $item['shift'] = $jadwal?->status === self::STATUS_KERJA ? $this->labelJadwal($jadwal) : null;
                $item['rekan'] = $jadwal?->nama_pengganti;
            }

            $roster[] = $item;
        }

        return $roster;
    }

    /**
     * Rekap satu bulan: hari wajib kerja menurut roster (Kerja + Backup) dibandingkan absensi.
     * Hari wajib yang sudah lewat tanpa baris absensi dihitung Alpha (tanpa keterangan), sehingga hari bolos
     * tanpa check-in tetap terdeteksi. Hari ini dan sesudahnya dihitung "belum berjalan".
     *
     * @return array{hari_wajib: int, hadir: int, terlambat: int, izin: int, sakit: int, alpha: int, belum_berjalan: int,
     *     hadir_luar_roster: int, harian: list<array{tanggal: string, status_roster: string, shift: string|null, klien: string|null,
     *     absensi: object|null, keterangan_hadir: string}>}
     */
    public function rekapKehadiran(int $idKaryawan, int $tahun, int $bulan, ?string $hariIni = null): array
    {
        $awal = CarbonImmutable::create($tahun, $bulan, 1);
        $hariIni ??= CarbonImmutable::now()->toDateString();
        $absensi = DB::table('tbl_absensi')
            ->where('id_karyawan', $idKaryawan)
            ->whereBetween('tgl', [$awal->toDateString(), $awal->endOfMonth()->toDateString()])
            ->get()
            ->keyBy(fn (object $baris): string => substr((string) $baris->tgl, 0, 10));

        $rekap = ['hari_wajib' => 0, 'hadir' => 0, 'terlambat' => 0, 'izin' => 0, 'sakit' => 0, 'alpha' => 0,
            'belum_berjalan' => 0, 'hadir_luar_roster' => 0, 'harian' => []];

        foreach ($this->rosterKaryawan($idKaryawan, $awal->toDateString(), $awal->endOfMonth()->toDateString()) as $hari) {
            $baris = $absensi->get($hari['tanggal']);
            $wajib = in_array($hari['status'], [self::STATUS_KERJA, 'Backup'], true);
            $status = $baris->status_kehadiran ?? null;

            $keterangan = match (true) {
                $status !== null => $status,
                ! $wajib => '-',
                $hari['tanggal'] < $hariIni => 'Alpha (tanpa keterangan)',
                default => 'Belum berjalan',
            };

            if ($wajib) {
                $rekap['hari_wajib']++;
                match (true) {
                    in_array($status, ['Hadir', 'Terlambat'], true) => $rekap['hadir']++,
                    $status === 'Izin' => $rekap['izin']++,
                    $status === 'Sakit' => $rekap['sakit']++,
                    $status === 'Alpha', $status === null && $hari['tanggal'] < $hariIni => $rekap['alpha']++,
                    default => $rekap['belum_berjalan']++,
                };
            } elseif (in_array($status, ['Hadir', 'Terlambat'], true)) {
                $rekap['hadir_luar_roster']++;
            }

            if ($status === 'Terlambat') {
                $rekap['terlambat']++;
            }

            $rekap['harian'][] = [
                'tanggal' => $hari['tanggal'],
                'status_roster' => $hari['status'],
                'shift' => $hari['shift'],
                'klien' => $hari['klien'],
                'absensi' => $baris,
                'keterangan_hadir' => $keterangan,
            ];
        }

        return $rekap;
    }

    /** Pesan penolakan bila tidak ada shift kerja pada tanggal itu. */
    public function pesanTanpaShift(string $status): string
    {
        return match ($status) {
            self::STATUS_TANPA_PENEMPATAN => 'Anda belum memiliki penempatan aktif. Absensi dapat dilakukan setelah ditempatkan oleh bagian Operasional.',
            self::STATUS_LIBUR => 'Hari ini adalah hari libur Anda sesuai jadwal dari Operasional. Absensi tidak dapat dilakukan.',
            self::STATUS_DIGANTIKAN => 'Shift Anda hari ini sudah digantikan rekan melalui backup shift yang disetujui Operasional. Absensi tidak dapat dilakukan.',
            default => 'Jadwal shift Anda hari ini belum ditentukan oleh Operasional. Absensi tidak dapat dilakukan tanpa jadwal.',
        };
    }

    /** Shift tempat sebuah baris absensi tercatat (tanggal absensi + penempatannya). */
    public function shiftAbsensi(object $absensi): ?ShiftAbsensi
    {
        return $this->jadwalPada(
            (int) $absensi->id_karyawan,
            substr((string) $absensi->tgl, 0, 10),
            filled($absensi->id_penempatan ?? null) ? (int) $absensi->id_penempatan : null,
        )['shift'];
    }

    /** Waktu check-in sebuah baris absensi, atau null. */
    public function waktuMasuk(object $absensi): ?CarbonImmutable
    {
        return blank($absensi->jam_masuk ?? null) ? null
            : CarbonImmutable::parse(substr((string) $absensi->tgl, 0, 10).' '.$absensi->jam_masuk);
    }

    /** Waktu check-out (keesokan hari bila jam keluar lebih kecil dari jam masuk), atau null. */
    public function waktuKeluar(object $absensi): ?CarbonImmutable
    {
        $masuk = $this->waktuMasuk($absensi);
        if ($masuk === null || blank($absensi->jam_keluar ?? null)) {
            return null;
        }

        $keluar = CarbonImmutable::parse($masuk->toDateString().' '.$absensi->jam_keluar);

        return $keluar->lt($masuk) ? $keluar->addDay() : $keluar;
    }

    /** Contoh: "Pagi · 07:00–15:00" atau "07:00–15:00". */
    private function labelJadwal(object $jadwal): string
    {
        $jam = substr((string) $jadwal->jam_masuk, 0, 5).'–'.substr((string) $jadwal->jam_pulang, 0, 5);

        return filled($jadwal->nama_shift_snapshot) ? $jadwal->nama_shift_snapshot.' · '.$jam : $jam;
    }

    private function buatShift(string $tanggal, string $jamMasuk, string $jamPulang, ?int $durasiMenit, string $sumber, ?string $namaShift, int $idPenempatan): ShiftAbsensi
    {
        $mulai = CarbonImmutable::parse($tanggal.' '.substr($jamMasuk, 0, 8));

        if ($durasiMenit === null || $durasiMenit <= 0) {
            $pulang = CarbonImmutable::parse($tanggal.' '.substr($jamPulang, 0, 8));
            $durasiMenit = (int) $mulai->diffInMinutes($pulang->lte($mulai) ? $pulang->addDay() : $pulang);
        }

        return new ShiftAbsensi($tanggal, $mulai, $mulai->addMinutes($durasiMenit), $sumber, $namaShift, $idPenempatan);
    }

    /**
     * Jadwal Kerja milik rekan yang ditugaskan kepada karyawan ini sebagai pengganti pada tanggal itu.
     * Penempatan rekan harus masih Aktif dan berlaku pada tanggal tersebut.
     */
    private function jadwalBackup(int $idKaryawan, string $tanggal, ?int $idPenempatan): ?object
    {
        $this->adaKolomPengganti ??= $this->adaTabelJadwal() && Schema::hasColumn('tbl_jadwal_kerja', 'id_karyawan_pengganti');

        if (! $this->adaKolomPengganti) {
            return null;
        }

        return DB::table('tbl_jadwal_kerja as j')
            ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
            ->where('j.id_karyawan_pengganti', $idKaryawan)
            ->where('j.tanggal_kerja', $tanggal)
            ->where('j.status', self::STATUS_KERJA)
            ->whereNotNull('j.jam_masuk')
            ->whereNotNull('j.jam_pulang')
            ->where('p.status', 'Aktif')
            ->whereColumn('p.tgl_mulai', '<=', 'j.tanggal_kerja')
            ->where(fn (Builder $query) => $query->whereNull('p.tgl_selesai')->orWhereColumn('p.tgl_selesai', '>=', 'j.tanggal_kerja'))
            ->when($idPenempatan !== null, fn (Builder $query) => $query->where('j.id_penempatan', $idPenempatan))
            ->first(['j.*']);
    }

    private function adaTabelJadwal(): bool
    {
        return $this->adaTabelJadwal ??= Schema::hasTable('tbl_jadwal_kerja');
    }
}

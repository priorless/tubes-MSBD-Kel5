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
 *   1. tbl_jadwal_kerja status 'Kerja' (termasuk hasil Pertukaran Shift yang SUDAH disetujui) -> shift harian;
 *   2. tbl_jadwal_kerja status 'Libur' -> tidak boleh absen;
 *   3. belum diatur -> jam_datang/jam_pulang penempatan; keduanya kosong -> tidak boleh absen.
 * Pengajuan tukar shift yang belum/tidak disetujui tidak pernah mengubah tbl_jadwal_kerja, sehingga tidak berpengaruh.
 *
 * Periode monitoring berkala dihitung dari JAM MULAI SHIFT (bukan jam check-in):
 * periode ke-n = [mulai + n×interval, mulai + (n+1)×interval).
 */
class JadwalAbsensiService
{
    public const string STATUS_KERJA = 'Kerja';

    public const string STATUS_LIBUR = 'Libur';

    public const string STATUS_TANPA_JADWAL = 'Tanpa Jadwal';

    public const string STATUS_TANPA_PENEMPATAN = 'Tanpa Penempatan';

    public const string PERIODE_AKAN_DATANG = 'Akan Datang';

    public const string PERIODE_DIBUKA = 'Dibuka';

    public const string PERIODE_DITUTUP = 'Ditutup';

    /** Batas jumlah periode yang dihitung untuk satu sesi (sesuai batas 16 jam fn_id_absensi_aktif + cadangan). */
    private const int MAKS_PERIODE = 24;

    private ?bool $adaTabelJadwal = null;

    /**
     * Jadwal efektif karyawan pada satu tanggal.
     *
     * @return array{status: string, shift: ShiftAbsensi|null}
     */
    public function jadwalPada(int $idKaryawan, string $tanggal, ?int $idPenempatan = null): array
    {
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

    /** Pesan penolakan bila tidak ada shift kerja pada tanggal itu. */
    public function pesanTanpaShift(string $status): string
    {
        return match ($status) {
            self::STATUS_TANPA_PENEMPATAN => 'Anda belum memiliki penempatan aktif. Absensi dapat dilakukan setelah ditempatkan oleh bagian Operasional.',
            self::STATUS_LIBUR => 'Hari ini adalah hari libur Anda sesuai jadwal dari Operasional. Absensi tidak dapat dilakukan.',
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

    /**
     * Periode monitoring berkala sebuah sesi: semua periode yang sudah dimulai sebelum check-out/sekarang,
     * ditambah satu periode berikutnya selama sesi masih berjalan.
     *
     * @return list<array{ke: int, mulai: CarbonImmutable, selesai: CarbonImmutable, status: string}>
     */
    public function periodeMonitoring(ShiftAbsensi $shift, ?CarbonImmutable $checkOut, CarbonImmutable $sekarang): array
    {
        $interval = max(1, (int) config('tenaga_kerja.absensi.interval_menit', 60));
        $batas = $checkOut ?? $sekarang;
        $periode = [];

        for ($ke = 1; $ke <= self::MAKS_PERIODE; $ke++) {
            $mulai = $shift->mulai->addMinutes($ke * $interval);
            $selesai = $mulai->addMinutes($interval);

            if ($mulai->gt($batas) || ($checkOut !== null && $mulai->gte($checkOut))) {
                if ($checkOut === null) {
                    $periode[] = ['ke' => $ke, 'mulai' => $mulai, 'selesai' => $selesai, 'status' => self::PERIODE_AKAN_DATANG];
                }
                break;
            }

            $dibuka = $checkOut === null && $sekarang->gte($mulai) && $sekarang->lt($selesai);
            $periode[] = ['ke' => $ke, 'mulai' => $mulai, 'selesai' => $selesai, 'status' => $dibuka ? self::PERIODE_DIBUKA : self::PERIODE_DITUTUP];
        }

        return $periode;
    }

    /**
     * Periode yang sedang dibuka, atau null.
     *
     * @return array{ke: int, mulai: CarbonImmutable, selesai: CarbonImmutable, status: string}|null
     */
    public function periodeDibuka(ShiftAbsensi $shift, CarbonImmutable $sekarang): ?array
    {
        foreach ($this->periodeMonitoring($shift, null, $sekarang) as $periode) {
            if ($periode['status'] === self::PERIODE_DIBUKA) {
                return $periode;
            }
        }

        return null;
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

    private function adaTabelJadwal(): bool
    {
        return $this->adaTabelJadwal ??= Schema::hasTable('tbl_jadwal_kerja');
    }
}

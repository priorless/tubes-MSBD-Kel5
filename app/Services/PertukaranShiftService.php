<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Pengajuan Pertukaran Shift: karyawan menukar nilai shift jadwalnya dengan jadwal rekan
 * pada klien yang sama. Pengajuan langsung masuk ke Operasional (tanpa persetujuan rekan),
 * lalu Operasional menyetujui atau menolak.
 *
 * Tanggal kedua jadwal boleh berbeda. tbl_pertukaran_shift.tanggal_kerja menyimpan tanggal jadwal
 * pengaju dan tanggal_kerja_rekan menyimpan tanggal jadwal rekan (pengajuan lama tanpa nilai ini
 * memakai tanggal pengaju). Saat disetujui,
 * pengaju menerima nilai shift rekan pada tanggal pengaju, dan rekan menerima nilai shift pengaju
 * pada tanggal rekan. Tanggal kerja dan penempatan tidak pernah dipindahkan.
 *
 * Aturan:
 * - pengajuan paling lambat H-1 (kedua tanggal shift harus setelah hari ini);
 * - kedua karyawan punya penempatan Aktif di klien yang sama dan jadwal pada tanggal masing-masing;
 * - kedua karyawan memiliki jenis layanan yang sama (mis. Satpam hanya dengan Satpam);
 * - pengaju dapat membatalkan pengajuannya selama masih Menunggu;
 * - shift keduanya berbeda, belum ada absensi, belum ada pengajuan lain yang menunggu, dan shift baru
 *   tidak bertumpuk dengan jadwal hari sebelum/sesudah tanggal masing-masing;
 * - saat disetujui, jadwal harus masih sama dengan saat pengajuan dan shift baru tidak
 *   boleh bertumpuk dengan shift hari sebelum/sesudahnya;
 * - pengajuan yang sudah diproses tidak dapat diproses lagi.
 */
class PertukaranShiftService
{
    public const string STATUS_MENUNGGU = 'Menunggu';

    public const string STATUS_DISETUJUI = 'Disetujui';

    public const string STATUS_DITOLAK = 'Ditolak';

    /**
     * Nilai status_rekan 'Ditolak' hanya ada pada data lama dari alur persetujuan rekan yang sudah
     * dihapus; dipertahankan agar riwayat lama tetap berlabel "Ditolak Rekan". Kolom status_rekan,
     * catatan_rekan, dan ditanggapi_rekan_pada tidak lagi diisi atau diperiksa oleh alur baru.
     */
    public const string REKAN_DITOLAK = 'Ditolak';

    /** Catatan keputusan penanda pengajuan yang dibatalkan oleh pengajunya sendiri. */
    public const string CATATAN_DIBATALKAN = 'Dibatalkan oleh pengaju.';

    /** Rentang tanggal mendatang yang ditawarkan pada form pengajuan karyawan. */
    public const int HARI_KE_DEPAN = 45;

    public function __construct(private JadwalKerjaService $jadwalKerjaService) {}

    public function tersedia(): bool
    {
        return Schema::hasTable('tbl_pertukaran_shift') && Schema::hasTable('tbl_jadwal_kerja');
    }

    /**
     * Pilihan form karyawan: tanggal mendatang miliknya beserta rekan dan jadwal rekan (tanggal boleh
     * berbeda) yang dapat diajak bertukar.
     *
     * @return list<array{tanggal: string, shift: string, rekan: list<array{id_karyawan: int, nama: string, nip: string, jadwal: list<array{tanggal: string, shift: string}>}>}>
     */
    public function opsiPengajuan(int $idKaryawan): array
    {
        if (! $this->tersedia()) {
            return [];
        }

        $besok = $this->hariIni()->addDay()->toDateString();
        $batas = $this->hariIni()->addDays(self::HARI_KE_DEPAN)->toDateString();

        $jadwalSaya = $this->queryJadwalAktif()
            ->where('p.id_karyawan', $idKaryawan)
            ->whereBetween('j.tanggal_kerja', [$besok, $batas])
            ->orderBy('j.tanggal_kerja')
            ->get();

        if ($jadwalSaya->isEmpty()) {
            return [];
        }

        $jadwalRekan = $this->queryJadwalAktif()
            ->where('p.id_karyawan', '<>', $idKaryawan)
            ->whereIn('p.id_klien', $jadwalSaya->pluck('id_klien')->unique()->all())
            ->whereBetween('j.tanggal_kerja', [$besok, $batas])
            ->addSelect(['k.nama', 'k.nip'])
            ->orderBy('k.nama')
            ->orderBy('j.tanggal_kerja')
            ->get()
            ->groupBy('id_klien');

        $sedangDiajukan = $this->idJadwalMenunggu();
        $opsi = [];

        foreach ($jadwalSaya as $jadwal) {
            if (in_array((int) $jadwal->id, $sedangDiajukan, true)) {
                continue;
            }

            $rekan = collect($jadwalRekan->get($jadwal->id_klien, []))
                ->reject(fn (object $item): bool => in_array((int) $item->id, $sedangDiajukan, true)
                    || ! $this->layananSama($jadwal, $item)
                    || $this->shiftSama($jadwal, $item))
                ->groupBy('id_karyawan')
                ->map(fn ($jadwalSatuRekan): array => [
                    'id_karyawan' => (int) $jadwalSatuRekan->first()->id_karyawan,
                    'nama' => (string) $jadwalSatuRekan->first()->nama,
                    'nip' => (string) $jadwalSatuRekan->first()->nip,
                    'jadwal' => $jadwalSatuRekan->map(fn (object $item): array => [
                        'tanggal' => (string) $item->tanggal_kerja,
                        'shift' => $this->labelShift($this->jadwalKerjaService->snapshot($item)),
                    ])->values()->all(),
                ])
                ->values()
                ->all();

            if ($rekan === []) {
                continue;
            }

            $opsi[] = [
                'tanggal' => (string) $jadwal->tanggal_kerja,
                'shift' => $this->labelShift($this->jadwalKerjaService->snapshot($jadwal)),
                'rekan' => $rekan,
            ];
        }

        return $opsi;
    }

    /**
     * Membuat pengajuan berstatus Menunggu. $tanggalRekan = tanggal jadwal rekan; bila null sama dengan $tanggal.
     */
    public function ajukan(int $idKaryawan, int $idKaryawanRekan, string $tanggal, string $alasan, ?string $tanggalRekan = null): int
    {
        $tanggalRekan ??= $tanggal;

        return DB::transaction(function () use ($idKaryawan, $idKaryawanRekan, $tanggal, $tanggalRekan, $alasan): int {
            if ($idKaryawan === $idKaryawanRekan) {
                $this->gagal('id_karyawan_rekan', 'Rekan yang diajak bertukar tidak boleh diri sendiri.');
            }

            $this->pastikanMinimalHMinusSatu($tanggal, 'tanggal_kerja');
            $this->pastikanMinimalHMinusSatu($tanggalRekan, 'tanggal_kerja_rekan');

            $batas = $this->hariIni()->addDays(self::HARI_KE_DEPAN)->toDateString();
            if ($tanggal > $batas || $tanggalRekan > $batas) {
                $this->gagal('tanggal_kerja_rekan', 'Jadwal yang dipilih berada di luar batas pilihan tanggal.');
            }

            $jadwalPengaju = $this->jadwalKaryawan($idKaryawan, $tanggal, true);
            if ($jadwalPengaju === null) {
                $this->gagal('tanggal_kerja', 'Anda tidak memiliki jadwal pada tanggal tersebut dengan penempatan aktif.');
            }

            $jadwalRekan = $this->jadwalKaryawan($idKaryawanRekan, $tanggalRekan, true);
            if ($jadwalRekan === null) {
                $this->gagal('id_karyawan_rekan', 'Rekan yang dipilih tidak memiliki jadwal pada tanggal tersebut.');
            }

            if ((int) $jadwalPengaju->id_klien !== (int) $jadwalRekan->id_klien) {
                $this->gagal('id_karyawan_rekan', 'Pertukaran hanya dapat dilakukan dengan rekan pada klien yang sama.');
            }

            if (! $this->layananSama($jadwalPengaju, $jadwalRekan)) {
                $this->gagal('id_karyawan_rekan', 'Pertukaran hanya dapat dilakukan dengan rekan yang memiliki jenis layanan yang sama.');
            }

            if ($this->shiftSama($jadwalPengaju, $jadwalRekan)) {
                $this->gagal('id_karyawan_rekan', 'Shift Anda dan shift rekan yang dipilih sama, tidak ada yang perlu ditukar.');
            }

            $idJadwal = [(int) $jadwalPengaju->id, (int) $jadwalRekan->id];
            if (array_intersect($idJadwal, $this->idJadwalMenunggu()) !== []) {
                $this->gagal('tanggal_kerja', 'Salah satu jadwal sudah memiliki pengajuan yang masih menunggu persetujuan.');
            }

            if ($this->sudahAbsen([[$idKaryawan, $tanggal], [$idKaryawanRekan, $tanggalRekan]])) {
                $this->gagal('tanggal_kerja', 'Absensi pada salah satu tanggal sudah tercatat. Jadwal tidak dapat ditukar.');
            }

            $bentrok = $this->bentrokDenganHariSekitar($idKaryawan, $tanggal, $jadwalRekan);
            if ($bentrok !== null) {
                $this->gagal('tanggal_kerja_rekan', "Shift baru Anda bertumpuk dengan jadwal Anda pada {$bentrok}.");
            }

            $bentrok = $this->bentrokDenganHariSekitar($idKaryawanRekan, $tanggalRekan, $jadwalPengaju);
            if ($bentrok !== null) {
                $this->gagal('tanggal_kerja_rekan', "Shift baru rekan bertumpuk dengan jadwalnya pada {$bentrok}.");
            }

            return (int) DB::table('tbl_pertukaran_shift')->insertGetId([
                'id_karyawan_pengaju' => $idKaryawan,
                'id_karyawan_rekan' => $idKaryawanRekan,
                'id_jadwal_pengaju' => $jadwalPengaju->id,
                'id_jadwal_rekan' => $jadwalRekan->id,
                'tanggal_kerja' => $tanggal,
                'tanggal_kerja_rekan' => $tanggalRekan,
                'snapshot_pengaju' => json_encode($this->jadwalKerjaService->snapshot($jadwalPengaju), JSON_THROW_ON_ERROR),
                'snapshot_rekan' => json_encode($this->jadwalKerjaService->snapshot($jadwalRekan), JSON_THROW_ON_ERROR),
                'alasan' => $alasan,
                'status' => self::STATUS_MENUNGGU,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Menyetujui pengajuan: nilai shift kedua jadwal ditukar, masing-masing tetap pada tanggalnya sendiri.
     */
    public function setujui(int $idPengajuan, int $idUser): void
    {
        DB::transaction(function () use ($idPengajuan, $idUser): void {
            $pengajuan = $this->ambilUntukDiproses($idPengajuan);
            $jadwal = DB::table('tbl_jadwal_kerja')
                ->whereIn('id', [(int) $pengajuan->id_jadwal_pengaju, (int) $pengajuan->id_jadwal_rekan])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Kunci penempatan agar status/masa berlakunya tidak berubah di tengah proses.
            DB::table('tbl_penempatan')
                ->whereIn('id', $jadwal->pluck('id_penempatan')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $masalah = $this->masalahPersetujuan($pengajuan);
            if ($masalah !== []) {
                $this->gagal('pengajuan', $masalah[0]);
            }

            $jadwalPengaju = $jadwal->get($pengajuan->id_jadwal_pengaju);
            $jadwalRekan = $jadwal->get($pengajuan->id_jadwal_rekan);
            $sumber = ['pertukaran_shift' => (int) $pengajuan->id];

            $this->jadwalKerjaService->simpanNilaiJadwal(
                (int) $jadwalPengaju->id_penempatan,
                (string) $jadwalPengaju->tanggal_kerja,
                $jadwalPengaju,
                $this->nilaiJadwal($jadwalRekan),
                'Diubah',
                $idUser,
                $sumber,
            );
            $this->jadwalKerjaService->simpanNilaiJadwal(
                (int) $jadwalRekan->id_penempatan,
                (string) $jadwalRekan->tanggal_kerja,
                $jadwalRekan,
                $this->nilaiJadwal($jadwalPengaju),
                'Diubah',
                $idUser,
                $sumber,
            );

            $this->simpanKeputusan($idPengajuan, self::STATUS_DISETUJUI, $idUser, null);
        });
    }

    /**
     * Label tahap pengajuan untuk tampilan.
     */
    public static function labelTahap(object $pengajuan): string
    {
        if ($pengajuan->status === self::STATUS_MENUNGGU
            && self::tanggalTerawal($pengajuan) <= CarbonImmutable::now((string) config('app.timezone', 'UTC'))->toDateString()) {
            return 'Kedaluwarsa';
        }

        if ($pengajuan->status === self::STATUS_DITOLAK && ($pengajuan->catatan_keputusan ?? null) === self::CATATAN_DIBATALKAN) {
            return 'Dibatalkan';
        }

        if ($pengajuan->status === self::STATUS_MENUNGGU) {
            return 'Menunggu Persetujuan Operasional';
        }

        return ($pengajuan->status_rekan ?? null) === self::REKAN_DITOLAK ? 'Ditolak Rekan' : $pengajuan->status;
    }

    /**
     * Tanggal jadwal rekan (kolom tanggal_kerja_rekan); pengajuan lama tanpa nilai ini memakai tanggal pengaju.
     */
    public static function tanggalRekan(object $pengajuan): string
    {
        return (string) ($pengajuan->tanggal_kerja_rekan ?? $pengajuan->tanggal_kerja);
    }

    /**
     * Tanggal paling awal di antara kedua jadwal: pengajuan kedaluwarsa begitu tanggal ini tiba.
     */
    public static function tanggalTerawal(object $pengajuan): string
    {
        return min((string) $pengajuan->tanggal_kerja, self::tanggalRekan($pengajuan));
    }

    /**
     * Menolak pengajuan. Jadwal kedua karyawan tidak disentuh.
     */
    public function tolak(int $idPengajuan, int $idUser, ?string $catatan): void
    {
        DB::transaction(function () use ($idPengajuan, $idUser, $catatan): void {
            $this->ambilUntukDiproses($idPengajuan);
            $this->simpanKeputusan($idPengajuan, self::STATUS_DITOLAK, $idUser, $catatan);
        });
    }

    /**
     * Pengaju membatalkan pengajuannya sendiri selama masih Menunggu. Jadwal tidak disentuh.
     */
    public function batalkan(int $idPengajuan, int $idKaryawanPengaju, int $idUser): void
    {
        DB::transaction(function () use ($idPengajuan, $idKaryawanPengaju, $idUser): void {
            $pengajuan = $this->ambilUntukDiproses($idPengajuan);

            if ((int) $pengajuan->id_karyawan_pengaju !== $idKaryawanPengaju) {
                $this->gagal('pengajuan', 'Pengajuan pertukaran shift tidak ditemukan.');
            }

            $this->simpanKeputusan($idPengajuan, self::STATUS_DITOLAK, $idUser, self::CATATAN_DIBATALKAN);
        });
    }

    /**
     * Daftar alasan pengajuan belum dapat disetujui (kosong = siap disetujui).
     * Dipakai untuk peringatan di halaman detail dan pemeriksaan akhir saat menyetujui.
     *
     * @return list<string>
     */
    public function masalahPersetujuan(object $pengajuan): array
    {
        if ($pengajuan->status !== self::STATUS_MENUNGGU) {
            return ['Pengajuan ini sudah diproses sebelumnya.'];
        }

        return $this->masalahJadwal($pengajuan);
    }

    /**
     * Pemeriksaan kondisi jadwal kedua karyawan:
     * tanggal, penempatan, klien, jenis layanan, perubahan jadwal, absensi, dan bentrok shift.
     * Setiap pemeriksaan memakai tanggal jadwal milik masing-masing karyawan.
     *
     * @return list<string>
     */
    private function masalahJadwal(object $pengajuan): array
    {
        $tanggalPengaju = (string) $pengajuan->tanggal_kerja;
        $tanggalRekan = self::tanggalRekan($pengajuan);

        if ($this->tanggalSudahLewat(self::tanggalTerawal($pengajuan))) {
            return ['Tanggal shift sudah lewat atau sedang berlangsung, pengajuan tidak dapat disetujui.'];
        }

        $jadwalPengaju = $this->jadwalKaryawan((int) $pengajuan->id_karyawan_pengaju, $tanggalPengaju);
        $jadwalRekan = $this->jadwalKaryawan((int) $pengajuan->id_karyawan_rekan, $tanggalRekan);

        if ($jadwalPengaju === null || (int) $jadwalPengaju->id !== (int) $pengajuan->id_jadwal_pengaju
            || $jadwalRekan === null || (int) $jadwalRekan->id !== (int) $pengajuan->id_jadwal_rekan) {
            return ['Penempatan salah satu karyawan sudah tidak aktif pada tanggal tersebut.'];
        }

        if ((int) $jadwalPengaju->id_klien !== (int) $jadwalRekan->id_klien) {
            return ['Kedua karyawan sudah tidak ditempatkan pada klien yang sama.'];
        }

        if (! $this->layananSama($jadwalPengaju, $jadwalRekan)) {
            return ['Kedua karyawan tidak memiliki jenis layanan yang sama.'];
        }

        $masalah = [];

        if ($this->jadwalKerjaService->snapshot($jadwalPengaju) != $this->snapshotTersimpan($pengajuan->snapshot_pengaju)
            || $this->jadwalKerjaService->snapshot($jadwalRekan) != $this->snapshotTersimpan($pengajuan->snapshot_rekan)) {
            $masalah[] = 'Jadwal salah satu karyawan sudah diubah setelah pengajuan dibuat. Tolak pengajuan ini dan minta karyawan mengajukan ulang.';
        }

        if ($this->sudahAbsen([[(int) $pengajuan->id_karyawan_pengaju, $tanggalPengaju], [(int) $pengajuan->id_karyawan_rekan, $tanggalRekan]])) {
            $masalah[] = 'Absensi pada salah satu tanggal sudah tercatat.';
        }

        foreach ([
            [$pengajuan->id_karyawan_pengaju, $tanggalPengaju, $jadwalRekan, $pengajuan->nama_pengaju ?? 'Pengaju'],
            [$pengajuan->id_karyawan_rekan, $tanggalRekan, $jadwalPengaju, $pengajuan->nama_rekan ?? 'Rekan'],
        ] as [$idKaryawan, $tanggal, $jadwalBaru, $nama]) {
            $bentrok = $this->bentrokDenganHariSekitar((int) $idKaryawan, $tanggal, $jadwalBaru);
            if ($bentrok !== null) {
                $masalah[] = "Shift baru {$nama} bertumpuk dengan jadwalnya pada {$bentrok}.";
            }
        }

        return $masalah;
    }

    /**
     * Versi instance dari tanggalRekan(), dipakai tampilan Operasional.
     */
    public function tanggalKerjaRekan(object $pengajuan): string
    {
        return self::tanggalRekan($pengajuan);
    }

    /**
     * True bila salah satu tanggal dalam pengajuan sudah lewat atau hari ini.
     */
    public function pengajuanKedaluwarsa(object $pengajuan): bool
    {
        return $this->tanggalSudahLewat(self::tanggalTerawal($pengajuan));
    }

    public function jumlahMenunggu(): int
    {
        return $this->tersedia() ? $this->queryMenungguBerlaku()->count() : 0;
    }

    /**
     * Jumlah pengajuan per status (untuk badge filter dan ringkasan Beranda).
     *
     * @return array{Menunggu: int, Disetujui: int, Ditolak: int}
     */
    public function ringkasanStatus(): array
    {
        $ringkasan = [self::STATUS_MENUNGGU => 0, self::STATUS_DISETUJUI => 0, self::STATUS_DITOLAK => 0];

        if (! $this->tersedia()) {
            return $ringkasan;
        }

        $jumlah = DB::table('tbl_pertukaran_shift')
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as jumlah')
            ->pluck('jumlah', 'status');

        foreach (array_keys($ringkasan) as $status) {
            $ringkasan[$status] = (int) ($jumlah[$status] ?? 0);
        }

        return $ringkasan;
    }

    /**
     * True bila tanggal shift sudah lewat atau hari ini: pengajuan Menunggu tidak lagi dapat disetujui.
     * Untuk pengajuan, berikan tanggalTerawal() agar kedua tanggal ikut diperhitungkan.
     */
    public function tanggalSudahLewat(string $tanggal): bool
    {
        return $tanggal <= $this->hariIni()->toDateString();
    }

    /**
     * Query daftar pengajuan lengkap dengan nama karyawan, klien, dan pemroses. Alias jr = jadwal rekan
     * (dipakai filter tanggal Operasional); tanggal_kerja_rekan berasal dari kolom ps.*.
     */
    public function queryDaftar(): Builder
    {
        return DB::table('tbl_pertukaran_shift as ps')
            ->join('tbl_karyawan as kp', 'kp.id', '=', 'ps.id_karyawan_pengaju')
            ->join('tbl_karyawan as kr', 'kr.id', '=', 'ps.id_karyawan_rekan')
            ->join('tbl_jadwal_kerja as jp', 'jp.id', '=', 'ps.id_jadwal_pengaju')
            ->join('tbl_jadwal_kerja as jr', 'jr.id', '=', 'ps.id_jadwal_rekan')
            ->join('tbl_penempatan as pp', 'pp.id', '=', 'jp.id_penempatan')
            ->join('tbl_klien as kl', 'kl.id', '=', 'pp.id_klien')
            ->leftJoin('tbl_users as u', 'u.id', '=', 'ps.diproses_oleh')
            ->select([
                'ps.*',
                'kp.nama as nama_pengaju',
                'kp.nip as nip_pengaju',
                'kr.nama as nama_rekan',
                'kr.nip as nip_rekan',
                'kl.nama_perusahaan',
                DB::raw('COALESCE(u.nama, u.username) as nama_pemroses'),
            ]);
    }

    public function detail(int $idPengajuan): ?object
    {
        return $this->queryDaftar()->where('ps.id', $idPengajuan)->first();
    }

    /**
     * Pengajuan yang melibatkan karyawan (sebagai pengaju maupun rekan), terbaru dahulu.
     */
    public function riwayatKaryawan(int $idKaryawan, int $perHalaman = 10): LengthAwarePaginator
    {
        return $this->queryDaftar()
            ->where(fn (Builder $query) => $query->where('ps.id_karyawan_pengaju', $idKaryawan)->orWhere('ps.id_karyawan_rekan', $idKaryawan))
            ->orderByDesc('ps.created_at')
            ->orderByDesc('ps.id')
            ->paginate($perHalaman)
            ->withQueryString();
    }

    /**
     * Label singkat shift untuk tampilan, mis. "Pagi · 07:00–15:00" atau "Libur".
     *
     * @param  array<string, mixed>|string|null  $snapshot
     */
    public function labelShift(array|string|null $snapshot): string
    {
        $snapshot = is_string($snapshot) ? $this->snapshotTersimpan($snapshot) : $snapshot;

        if ($snapshot === null || ($snapshot['status'] ?? null) !== 'Kerja') {
            return 'Libur';
        }

        $jam = substr((string) $snapshot['jam_masuk'], 0, 5).'–'.substr((string) $snapshot['jam_pulang'], 0, 5);

        return filled($snapshot['nama_shift_snapshot'] ?? null) ? $snapshot['nama_shift_snapshot'].' · '.$jam : $jam;
    }

    public static function kelasBadge(string $status): string
    {
        return match ($status) {
            self::STATUS_DISETUJUI => 'badge-success',
            self::STATUS_DITOLAK => 'badge-danger',
            default => 'badge-warning',
        };
    }

    public static function labelStatus(string $status): string
    {
        return $status === self::STATUS_MENUNGGU ? 'Menunggu Persetujuan' : $status;
    }

    /**
     * Jadwal karyawan pada satu tanggal, hanya dari penempatan Aktif yang berlaku pada tanggal itu.
     */
    private function jadwalKaryawan(int $idKaryawan, string $tanggal, bool $kunci = false): ?object
    {
        $query = $this->queryJadwalAktif()
            ->where('p.id_karyawan', $idKaryawan)
            ->where('j.tanggal_kerja', $tanggal);

        if ($kunci) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function queryJadwalAktif(): Builder
    {
        return DB::table('tbl_jadwal_kerja as j')
            ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->where('p.status', 'Aktif')
            ->whereColumn('p.tgl_mulai', '<=', 'j.tanggal_kerja')
            ->where(fn (Builder $query) => $query->whereNull('p.tgl_selesai')->orWhereColumn('p.tgl_selesai', '>=', 'j.tanggal_kerja'))
            ->select(['j.*', 'p.id_karyawan', 'p.id_klien', 'p.tgl_mulai', 'p.tgl_selesai', 'k.id_jenis_layanan']);
    }

    private function ambilUntukDiproses(int $idPengajuan): object
    {
        $terkunci = DB::table('tbl_pertukaran_shift')->where('id', $idPengajuan)->lockForUpdate()->exists();
        $pengajuan = $terkunci ? $this->detail($idPengajuan) : null;

        if ($pengajuan === null) {
            $this->gagal('pengajuan', 'Pengajuan pertukaran shift tidak ditemukan.');
        }

        if ($pengajuan->status !== self::STATUS_MENUNGGU) {
            $this->gagal('pengajuan', 'Pengajuan ini sudah '.strtolower($pengajuan->status).' sebelumnya dan tidak dapat diproses lagi.');
        }

        return $pengajuan;
    }

    private function simpanKeputusan(int $idPengajuan, string $status, int $idUser, ?string $catatan): void
    {
        $diperbarui = DB::table('tbl_pertukaran_shift')
            ->where('id', $idPengajuan)
            ->where('status', self::STATUS_MENUNGGU)
            ->update([
                'status' => $status,
                'catatan_keputusan' => $catatan,
                'diproses_oleh' => $idUser,
                'diproses_pada' => now(),
                'updated_at' => now(),
            ]);

        if ($diperbarui !== 1) {
            $this->gagal('pengajuan', 'Pengajuan ini sudah diproses sebelumnya dan tidak dapat diproses lagi.');
        }
    }

    /**
     * Mengembalikan tanggal (d/m/Y) yang bertumpuk bila $jadwalBaru dipasang pada $tanggal,
     * dibandingkan dengan jadwal Kerja karyawan pada hari sebelum dan sesudahnya.
     */
    private function bentrokDenganHariSekitar(int $idKaryawan, string $tanggal, object $jadwalBaru): ?string
    {
        if ($jadwalBaru->status !== 'Kerja') {
            return null;
        }

        $hari = CarbonImmutable::parse($tanggal);
        [$mulaiBaru, $selesaiBaru] = $this->rentangMenit($jadwalBaru, 0);
        $jadwalSekitar = $this->queryJadwalAktif()
            ->where('p.id_karyawan', $idKaryawan)
            ->where('j.status', 'Kerja')
            ->whereIn('j.tanggal_kerja', [$hari->subDay()->toDateString(), $hari->addDay()->toDateString()])
            ->get();

        foreach ($jadwalSekitar as $jadwal) {
            $selisihHari = (string) $jadwal->tanggal_kerja < $tanggal ? -1 : 1;
            [$mulai, $selesai] = $this->rentangMenit($jadwal, $selisihHari);

            if ($mulai < $selesaiBaru && $mulaiBaru < $selesai) {
                return CarbonImmutable::parse((string) $jadwal->tanggal_kerja)->format('d/m/Y');
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function rentangMenit(object $jadwal, int $selisihHari): array
    {
        $jamMasuk = substr((string) $jadwal->jam_masuk, 0, 5);
        $mulai = ((int) substr($jamMasuk, 0, 2) * 60) + (int) substr($jamMasuk, 3, 2) + ($selisihHari * 1440);
        $durasi = $jadwal->durasi_menit !== null
            ? (int) $jadwal->durasi_menit
            : $this->jadwalKerjaService->hitungDurasiMenit($jamMasuk, substr((string) $jadwal->jam_pulang, 0, 5));

        return [$mulai, $mulai + $durasi];
    }

    /**
     * @return array<string, mixed>
     */
    private function nilaiJadwal(object $jadwal): array
    {
        return [
            'status' => $jadwal->status,
            'id_template_shift' => $jadwal->id_template_shift,
            'nama_shift_snapshot' => $jadwal->nama_shift_snapshot,
            'jam_masuk' => $jadwal->jam_masuk,
            'jam_pulang' => $jadwal->jam_pulang,
            'durasi_menit' => $jadwal->durasi_menit,
        ];
    }

    /**
     * Jenis layanan kedua jadwal sama. Karyawan tanpa jenis layanan tidak pernah dianggap sama.
     */
    private function layananSama(object $jadwalA, object $jadwalB): bool
    {
        return $jadwalA->id_jenis_layanan !== null
            && (int) $jadwalA->id_jenis_layanan === (int) $jadwalB->id_jenis_layanan;
    }

    private function shiftSama(object $jadwalA, object $jadwalB): bool
    {
        if ($jadwalA->status !== $jadwalB->status) {
            return false;
        }

        return $jadwalA->status === 'Libur'
            || (substr((string) $jadwalA->jam_masuk, 0, 5) === substr((string) $jadwalB->jam_masuk, 0, 5)
                && substr((string) $jadwalA->jam_pulang, 0, 5) === substr((string) $jadwalB->jam_pulang, 0, 5));
    }

    /**
     * @return list<int>
     */
    private function idJadwalMenunggu(): array
    {
        return $this->queryMenungguBerlaku()
            ->get(['ps.id_jadwal_pengaju', 'ps.id_jadwal_rekan'])
            ->flatMap(static fn (object $item): array => [(int) $item->id_jadwal_pengaju, (int) $item->id_jadwal_rekan])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Pengajuan Menunggu yang kedua tanggal jadwalnya masih setelah hari ini (belum kedaluwarsa).
     */
    private function queryMenungguBerlaku(): Builder
    {
        $hariIni = $this->hariIni()->toDateString();

        return DB::table('tbl_pertukaran_shift as ps')
            ->join('tbl_jadwal_kerja as jr', 'jr.id', '=', 'ps.id_jadwal_rekan')
            ->where('ps.status', self::STATUS_MENUNGGU)
            ->where('ps.tanggal_kerja', '>', $hariIni)
            ->where('jr.tanggal_kerja', '>', $hariIni);
    }

    /**
     * True bila salah satu karyawan sudah memiliki absensi pada tanggal jadwalnya sendiri.
     *
     * @param  list<array{0: int, 1: string}>  $karyawanPerTanggal  pasangan [id_karyawan, tanggal]
     */
    private function sudahAbsen(array $karyawanPerTanggal): bool
    {
        return Schema::hasTable('tbl_absensi')
            && DB::table('tbl_absensi')
                ->where(function (Builder $query) use ($karyawanPerTanggal): void {
                    foreach ($karyawanPerTanggal as [$idKaryawan, $tanggal]) {
                        $query->orWhere(fn (Builder $query) => $query->where('id_karyawan', $idKaryawan)->where('tgl', $tanggal));
                    }
                })
                ->exists();
    }

    private function pastikanMinimalHMinusSatu(string $tanggal, string $kunci): void
    {
        if ($tanggal <= $this->hariIni()->toDateString()) {
            $this->gagal($kunci, 'Pengajuan pertukaran shift paling lambat H-1 sebelum tanggal shift.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshotTersimpan(?string $json): ?array
    {
        return $json === null ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function hariIni(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone', 'UTC'))->startOfDay();
    }

    private function gagal(string $kunci, string $pesan): never
    {
        throw ValidationException::withMessages([$kunci => $pesan]);
    }
}

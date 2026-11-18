<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Pengajuan Pertukaran Shift: karyawan menukar jadwal satu tanggal dengan rekan
 * pada klien yang sama, lalu Operasional menyetujui atau menolak.
 *
 * Aturan:
 * - pengajuan paling lambat H-1 (tanggal shift harus setelah hari ini);
 * - kedua karyawan punya penempatan Aktif di klien yang sama dan jadwal pada tanggal itu;
 * - rekan menerima pengajuan lebih dulu (status_rekan 'Diterima'); bila rekan menolak, pengajuan langsung Ditolak;
 * - shift keduanya berbeda, belum ada absensi, dan belum ada pengajuan lain yang menunggu;
 * - saat disetujui, jadwal harus masih sama dengan saat pengajuan dan shift baru tidak
 *   boleh bertumpuk dengan shift hari sebelum/sesudahnya;
 * - pengajuan yang sudah diproses tidak dapat diproses lagi.
 */
class PertukaranShiftService
{
    public const string STATUS_MENUNGGU = 'Menunggu';

    public const string STATUS_DISETUJUI = 'Disetujui';

    public const string STATUS_DITOLAK = 'Ditolak';

    public const string REKAN_MENUNGGU = 'Menunggu';

    public const string REKAN_DITERIMA = 'Diterima';

    public const string REKAN_DITOLAK = 'Ditolak';

    /** Rentang tanggal mendatang yang ditawarkan pada form pengajuan karyawan. */
    public const int HARI_KE_DEPAN = 45;

    public function __construct(private JadwalKerjaService $jadwalKerjaService) {}

    public function tersedia(): bool
    {
        return Schema::hasTable('tbl_pertukaran_shift') && Schema::hasTable('tbl_jadwal_kerja');
    }

    /**
     * Pilihan form karyawan: tanggal mendatang miliknya beserta rekan yang dapat diajak bertukar.
     *
     * @return list<array{tanggal: string, shift: string, rekan: list<array{id_karyawan: int, nama: string, nip: string, shift: string}>}>
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
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->where('p.id_karyawan', '<>', $idKaryawan)
            ->whereIn('p.id_klien', $jadwalSaya->pluck('id_klien')->unique()->all())
            ->whereIn('j.tanggal_kerja', $jadwalSaya->pluck('tanggal_kerja')->all())
            ->addSelect(['k.nama', 'k.nip'])
            ->orderBy('k.nama')
            ->get()
            ->groupBy(static fn (object $jadwal): string => $jadwal->id_klien.'|'.$jadwal->tanggal_kerja);

        $sedangDiajukan = $this->idJadwalMenunggu();
        $opsi = [];

        foreach ($jadwalSaya as $jadwal) {
            if (in_array((int) $jadwal->id, $sedangDiajukan, true)) {
                continue;
            }

            $rekan = collect($jadwalRekan->get($jadwal->id_klien.'|'.$jadwal->tanggal_kerja, []))
                ->reject(fn (object $item): bool => in_array((int) $item->id, $sedangDiajukan, true)
                    || $this->shiftSama($jadwal, $item))
                ->map(fn (object $item): array => [
                    'id_karyawan' => (int) $item->id_karyawan,
                    'nama' => (string) $item->nama,
                    'nip' => (string) $item->nip,
                    'shift' => $this->labelShift($this->jadwalKerjaService->snapshot($item)),
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
     * Membuat pengajuan berstatus Menunggu.
     */
    public function ajukan(int $idKaryawan, int $idKaryawanRekan, string $tanggal, string $alasan): int
    {
        return DB::transaction(function () use ($idKaryawan, $idKaryawanRekan, $tanggal, $alasan): int {
            if ($idKaryawan === $idKaryawanRekan) {
                $this->gagal('id_karyawan_rekan', 'Rekan yang diajak bertukar tidak boleh diri sendiri.');
            }

            $this->pastikanMinimalHMinusSatu($tanggal);

            $jadwalPengaju = $this->jadwalKaryawan($idKaryawan, $tanggal, true);
            if ($jadwalPengaju === null) {
                $this->gagal('tanggal_kerja', 'Anda tidak memiliki jadwal pada tanggal tersebut dengan penempatan aktif.');
            }

            $jadwalRekan = $this->jadwalKaryawan($idKaryawanRekan, $tanggal, true);
            if ($jadwalRekan === null) {
                $this->gagal('id_karyawan_rekan', 'Rekan yang dipilih tidak memiliki jadwal pada tanggal tersebut.');
            }

            if ((int) $jadwalPengaju->id_klien !== (int) $jadwalRekan->id_klien) {
                $this->gagal('id_karyawan_rekan', 'Pertukaran hanya dapat dilakukan dengan rekan pada klien yang sama.');
            }

            if ($this->shiftSama($jadwalPengaju, $jadwalRekan)) {
                $this->gagal('id_karyawan_rekan', 'Shift Anda dan rekan pada tanggal tersebut sama, tidak ada yang perlu ditukar.');
            }

            $idJadwal = [(int) $jadwalPengaju->id, (int) $jadwalRekan->id];
            if (array_intersect($idJadwal, $this->idJadwalMenunggu()) !== []) {
                $this->gagal('tanggal_kerja', 'Sudah ada pengajuan pertukaran untuk jadwal ini yang masih menunggu persetujuan.');
            }

            if ($this->sudahAbsen([$idKaryawan, $idKaryawanRekan], $tanggal)) {
                $this->gagal('tanggal_kerja', 'Absensi pada tanggal tersebut sudah tercatat, jadwal tidak dapat ditukar.');
            }

            return (int) DB::table('tbl_pertukaran_shift')->insertGetId([
                'id_karyawan_pengaju' => $idKaryawan,
                'id_karyawan_rekan' => $idKaryawanRekan,
                'id_jadwal_pengaju' => $jadwalPengaju->id,
                'id_jadwal_rekan' => $jadwalRekan->id,
                'tanggal_kerja' => $tanggal,
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
     * Menyetujui pengajuan: isi jadwal kedua karyawan pada tanggal itu ditukar.
     */
    public function setujui(int $idPengajuan, int $idUser): void
    {
        DB::transaction(function () use ($idPengajuan, $idUser): void {
            $pengajuan = $this->ambilUntukDiproses($idPengajuan);
            $jadwal = DB::table('tbl_jadwal_kerja')
                ->whereIn('id', [$pengajuan->id_jadwal_pengaju, $pengajuan->id_jadwal_rekan])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

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
     * Tanggapan rekan atas pengajuan yang ditujukan kepadanya. Menerima = diteruskan ke Operasional;
     * menolak = pengajuan langsung Ditolak. Jadwal tidak pernah disentuh di tahap ini.
     */
    public function tanggapiRekan(int $idPengajuan, int $idKaryawanRekan, bool $terima, ?string $catatan, int $idUser): void
    {
        DB::transaction(function () use ($idPengajuan, $idKaryawanRekan, $terima, $catatan, $idUser): void {
            $pengajuan = $this->ambilUntukDiproses($idPengajuan);

            if ((int) $pengajuan->id_karyawan_rekan !== $idKaryawanRekan) {
                $this->gagal('pengajuan', 'Pengajuan pertukaran shift tidak ditemukan.');
            }

            if (($pengajuan->status_rekan ?? self::REKAN_DITERIMA) !== self::REKAN_MENUNGGU) {
                $this->gagal('pengajuan', 'Anda sudah menanggapi pengajuan ini sebelumnya.');
            }

            if ($terima && $this->tanggalSudahLewat((string) $pengajuan->tanggal_kerja)) {
                $this->gagal('pengajuan', 'Tanggal shift sudah lewat atau sedang berlangsung, pengajuan tidak dapat diterima.');
            }

            $nilai = [
                'status_rekan' => $terima ? self::REKAN_DITERIMA : self::REKAN_DITOLAK,
                'catatan_rekan' => $catatan,
                'ditanggapi_rekan_pada' => now(),
                'updated_at' => now(),
            ];

            if (! $terima) {
                $nilai += [
                    'status' => self::STATUS_DITOLAK,
                    'catatan_keputusan' => 'Ditolak oleh rekan'.(filled($catatan) ? ': '.$catatan : '.'),
                    'diproses_oleh' => $idUser,
                    'diproses_pada' => now(),
                ];
            }

            DB::table('tbl_pertukaran_shift')
                ->where('id', $idPengajuan)
                ->where('status', self::STATUS_MENUNGGU)
                ->where('status_rekan', self::REKAN_MENUNGGU)
                ->update($nilai);
        });
    }

    /**
     * Label tahap pengajuan untuk tampilan, mempertimbangkan persetujuan rekan.
     */
    public static function labelTahap(object $pengajuan): string
    {
        if ($pengajuan->status === self::STATUS_MENUNGGU && ($pengajuan->status_rekan ?? self::REKAN_DITERIMA) === self::REKAN_MENUNGGU) {
            return 'Menunggu Persetujuan Rekan';
        }

        if ($pengajuan->status === self::STATUS_MENUNGGU) {
            return 'Menunggu Persetujuan Operasional';
        }

        return ($pengajuan->status_rekan ?? null) === self::REKAN_DITOLAK ? 'Ditolak Rekan' : $pengajuan->status;
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

        if (($pengajuan->status_rekan ?? self::REKAN_DITERIMA) !== self::REKAN_DITERIMA) {
            return ['Rekan belum menerima pengajuan ini. Persetujuan Operasional baru dapat diberikan setelah rekan menerima.'];
        }

        $tanggal = (string) $pengajuan->tanggal_kerja;

        if ($tanggal <= $this->hariIni()->toDateString()) {
            return ['Tanggal shift sudah lewat atau sedang berlangsung, pengajuan tidak dapat disetujui.'];
        }

        $jadwalPengaju = $this->jadwalKaryawan((int) $pengajuan->id_karyawan_pengaju, $tanggal);
        $jadwalRekan = $this->jadwalKaryawan((int) $pengajuan->id_karyawan_rekan, $tanggal);

        if ($jadwalPengaju === null || (int) $jadwalPengaju->id !== (int) $pengajuan->id_jadwal_pengaju
            || $jadwalRekan === null || (int) $jadwalRekan->id !== (int) $pengajuan->id_jadwal_rekan) {
            return ['Penempatan salah satu karyawan sudah tidak aktif pada tanggal tersebut.'];
        }

        if ((int) $jadwalPengaju->id_klien !== (int) $jadwalRekan->id_klien) {
            return ['Kedua karyawan sudah tidak ditempatkan pada klien yang sama.'];
        }

        $masalah = [];

        if ($this->jadwalKerjaService->snapshot($jadwalPengaju) != $this->snapshotTersimpan($pengajuan->snapshot_pengaju)
            || $this->jadwalKerjaService->snapshot($jadwalRekan) != $this->snapshotTersimpan($pengajuan->snapshot_rekan)) {
            $masalah[] = 'Jadwal salah satu karyawan sudah diubah setelah pengajuan dibuat. Tolak pengajuan ini dan minta karyawan mengajukan ulang.';
        }

        if ($this->sudahAbsen([(int) $pengajuan->id_karyawan_pengaju, (int) $pengajuan->id_karyawan_rekan], $tanggal)) {
            $masalah[] = 'Absensi pada tanggal tersebut sudah tercatat.';
        }

        foreach ([
            [$pengajuan->id_karyawan_pengaju, $jadwalRekan, $pengajuan->nama_pengaju ?? 'Pengaju'],
            [$pengajuan->id_karyawan_rekan, $jadwalPengaju, $pengajuan->nama_rekan ?? 'Rekan'],
        ] as [$idKaryawan, $jadwalBaru, $nama]) {
            $bentrok = $this->bentrokDenganHariSekitar((int) $idKaryawan, $tanggal, $jadwalBaru);
            if ($bentrok !== null) {
                $masalah[] = "Shift baru {$nama} bertumpuk dengan jadwalnya pada {$bentrok}.";
            }
        }

        return $masalah;
    }

    public function jumlahMenunggu(): int
    {
        return $this->tersedia()
            ? DB::table('tbl_pertukaran_shift')->where('status', self::STATUS_MENUNGGU)->count()
            : 0;
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
     */
    public function tanggalSudahLewat(string $tanggal): bool
    {
        return $tanggal <= $this->hariIni()->toDateString();
    }

    /**
     * Query daftar pengajuan lengkap dengan nama karyawan, klien, dan pemroses.
     */
    public function queryDaftar(): Builder
    {
        return DB::table('tbl_pertukaran_shift as ps')
            ->join('tbl_karyawan as kp', 'kp.id', '=', 'ps.id_karyawan_pengaju')
            ->join('tbl_karyawan as kr', 'kr.id', '=', 'ps.id_karyawan_rekan')
            ->join('tbl_jadwal_kerja as jp', 'jp.id', '=', 'ps.id_jadwal_pengaju')
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
     *
     * @return Collection<int, object>
     */
    public function riwayatKaryawan(int $idKaryawan, int $batas = 10): Collection
    {
        if (! $this->tersedia()) {
            return collect();
        }

        return $this->queryDaftar()
            ->where(fn (Builder $query) => $query->where('ps.id_karyawan_pengaju', $idKaryawan)->orWhere('ps.id_karyawan_rekan', $idKaryawan))
            ->orderByDesc('ps.created_at')
            ->orderByDesc('ps.id')
            ->limit($batas)
            ->get();
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
            ->where('j.tanggal_kerja', $tanggal)
            ->select(['j.*', 'p.id_karyawan', 'p.id_klien']);

        if ($kunci) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function queryJadwalAktif(): Builder
    {
        return DB::table('tbl_jadwal_kerja as j')
            ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
            ->where('p.status', 'Aktif')
            ->whereColumn('p.tgl_mulai', '<=', 'j.tanggal_kerja')
            ->where(fn (Builder $query) => $query->whereNull('p.tgl_selesai')->orWhereColumn('p.tgl_selesai', '>=', 'j.tanggal_kerja'))
            ->select(['j.*', 'p.id_karyawan', 'p.id_klien']);
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
        $jadwalSekitar = DB::table('tbl_jadwal_kerja as j')
            ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
            ->where('p.id_karyawan', $idKaryawan)
            ->where('j.status', 'Kerja')
            ->whereIn('j.tanggal_kerja', [$hari->subDay()->toDateString(), $hari->addDay()->toDateString()])
            ->get(['j.*']);

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
        return DB::table('tbl_pertukaran_shift')
            ->where('status', self::STATUS_MENUNGGU)
            ->get(['id_jadwal_pengaju', 'id_jadwal_rekan'])
            ->flatMap(static fn (object $item): array => [(int) $item->id_jadwal_pengaju, (int) $item->id_jadwal_rekan])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $idKaryawan
     */
    private function sudahAbsen(array $idKaryawan, string $tanggal): bool
    {
        return Schema::hasTable('tbl_absensi')
            && DB::table('tbl_absensi')->whereIn('id_karyawan', $idKaryawan)->where('tgl', $tanggal)->exists();
    }

    private function pastikanMinimalHMinusSatu(string $tanggal): void
    {
        if ($tanggal <= $this->hariIni()->toDateString()) {
            $this->gagal('tanggal_kerja', 'Pengajuan pertukaran shift paling lambat H-1 sebelum tanggal shift.');
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

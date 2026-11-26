<?php

namespace App\Services;

use App\Support\ShiftAbsensi;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Backup Shift (roker 1 arah): karyawan meminta rekan menggantikan SATU jadwal Kerja miliknya.
 *
 * Alur: pengaju memilih jadwalnya sendiri + rekan pengganti -> rekan menerima/menolak -> Operasional
 * menyetujui/menolak. Saat disetujui, tbl_jadwal_kerja.id_karyawan_pengganti diisi rekan; jadwal tetap
 * milik penempatan pengaju sehingga lokasi, jam shift, dan klien yang ditagih tidak berubah.
 *
 * Aturan:
 * - pengajuan paling lambat H-1 (tanggal shift setelah hari ini, WIB);
 * - calon rekan: jabatan/jenis layanan sama, klien boleh berbeda, punya penempatan Aktif pada tanggal itu,
 *   tidak punya shift Kerja pada tanggal tersebut (Libur atau belum terjadwal), dan tidak bertumpuk
 *   dengan shift Kerja-nya pada hari sebelum/sesudah;
 * - rekan wajib menanggapi paling lambat 5 jam sebelum shift dimulai; ditolak rekan = pengajuan Ditolak;
 * - Operasional memutuskan paling lambat saat shift dimulai; lewat batas mana pun = Kedaluwarsa;
 * - satu jadwal paling banyak satu pengajuan Menunggu/Disetujui (UNIQUE id_jadwal_aktif);
 * - jadwal yang sedang diajukan tidak dapat diubah Operasional (trigger + JadwalKerjaService).
 * Status kedaluwarsa disinkronkan oleh sinkronkanKedaluwarsa() sebelum setiap hitungan/daftar, dan oleh
 * perintah terjadwal pertukaran-shift:kedaluwarsa.
 */
class PertukaranShiftService
{
    public const string STATUS_MENUNGGU = 'Menunggu';

    public const string STATUS_DISETUJUI = 'Disetujui';

    public const string STATUS_DITOLAK = 'Ditolak';

    public const string STATUS_DIBATALKAN = 'Dibatalkan';

    public const string STATUS_KEDALUWARSA = 'Kedaluwarsa';

    /** @var list<string> */
    public const array SEMUA_STATUS = [
        self::STATUS_MENUNGGU, self::STATUS_DISETUJUI, self::STATUS_DITOLAK, self::STATUS_DIBATALKAN, self::STATUS_KEDALUWARSA,
    ];

    public const string REKAN_MENUNGGU = 'Menunggu';

    public const string REKAN_DITERIMA = 'Diterima';

    public const string REKAN_DITOLAK = 'Ditolak';

    /** Rentang tanggal mendatang yang ditawarkan pada form pengajuan karyawan. */
    public const int HARI_KE_DEPAN = 45;

    /** Rekan harus menanggapi paling lambat sekian jam sebelum shift dimulai. */
    public const int JAM_BATAS_TANGGAPAN_REKAN = 5;

    public function __construct(
        private JadwalKerjaService $jadwalKerjaService,
        private JadwalAbsensiService $jadwalAbsensiService,
    ) {}

    public function tersedia(): bool
    {
        return Schema::hasTable('tbl_pertukaran_shift')
            && Schema::hasTable('tbl_jadwal_kerja')
            && Schema::hasColumn('tbl_jadwal_kerja', 'id_karyawan_pengganti');
    }

    /* =====================================================================
     * Pilihan form karyawan
     * ===================================================================== */

    /**
     * Jadwal Kerja milik karyawan (H+1 s.d. HARI_KE_DEPAN) yang masih dapat dimintakan backup.
     *
     * @return list<array{id: int, tanggal: string, shift: string, klien: string}>
     */
    public function jadwalDapatDiajukan(int $idKaryawan): array
    {
        if (! $this->tersedia()) {
            return [];
        }

        $this->sinkronkanKedaluwarsa();

        return $this->queryJadwalAktif()
            ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->where('p.id_karyawan', $idKaryawan)
            ->where('j.status', 'Kerja')
            ->whereNull('j.id_karyawan_pengganti')
            ->whereBetween('j.tanggal_kerja', [$this->hariIni()->addDay()->toDateString(), $this->hariIni()->addDays(self::HARI_KE_DEPAN)->toDateString()])
            ->whereNotExists(fn (Builder $query) => $query->select(DB::raw(1))
                ->from('tbl_pertukaran_shift as ps')
                ->whereColumn('ps.id_jadwal_pengaju', 'j.id')
                ->whereIn('ps.status', [self::STATUS_MENUNGGU, self::STATUS_DISETUJUI]))
            ->orderBy('j.tanggal_kerja')
            ->addSelect('kl.nama_perusahaan')
            ->get()
            ->filter(fn (object $jadwal): bool => $this->batasTanggapanRekan($jadwal)->isFuture())
            ->map(fn (object $jadwal): array => [
                'id' => (int) $jadwal->id,
                'tanggal' => (string) $jadwal->tanggal_kerja,
                'shift' => $this->labelShift($this->jadwalKerjaService->snapshot($jadwal)),
                'klien' => (string) $jadwal->nama_perusahaan,
            ])
            ->values()
            ->all();
    }

    /**
     * Rekan yang dapat menggantikan jadwal tersebut (lihat aturan kelas), urut nama.
     *
     * @return list<array{id_karyawan: int, nama: string, nip: string, klien: string}>
     */
    public function calonRekan(int $idKaryawan, int $idJadwal): array
    {
        $jadwal = $this->jadwalMilik($idKaryawan, $idJadwal);

        if ($jadwal === null || $jadwal->status !== 'Kerja') {
            return [];
        }

        return DB::table('tbl_karyawan as k')
            ->join('tbl_penempatan as p', 'p.id_karyawan', '=', 'k.id')
            ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->where('k.id', '<>', $idKaryawan)
            ->where('k.id_jenis_layanan', $jadwal->id_jenis_layanan)
            ->where('p.status', 'Aktif')
            ->where('p.tgl_mulai', '<=', $jadwal->tanggal_kerja)
            ->where(fn (Builder $query) => $query->whereNull('p.tgl_selesai')->orWhere('p.tgl_selesai', '>=', $jadwal->tanggal_kerja))
            ->orderBy('k.nama')
            ->get(['k.id', 'k.nama', 'k.nip', 'kl.nama_perusahaan'])
            ->unique('id')
            ->filter(fn (object $rekan): bool => $this->alasanRekanTidakTersedia((int) $rekan->id, $jadwal) === null)
            ->map(fn (object $rekan): array => [
                'id_karyawan' => (int) $rekan->id,
                'nama' => (string) $rekan->nama,
                'nip' => (string) $rekan->nip,
                'klien' => (string) $rekan->nama_perusahaan,
            ])
            ->values()
            ->all();
    }

    /* =====================================================================
     * Alur pengajuan
     * ===================================================================== */

    /**
     * Pengaju meminta rekan menggantikan satu jadwal miliknya.
     */
    public function ajukan(int $idKaryawan, int $idJadwal, int $idKaryawanRekan, string $alasan): int
    {
        $idPengajuan = DB::transaction(function () use ($idKaryawan, $idJadwal, $idKaryawanRekan, $alasan): int {
            if ($idKaryawan === $idKaryawanRekan) {
                $this->gagal('id_karyawan_rekan', 'Rekan pengganti tidak boleh diri sendiri.');
            }

            $jadwal = $this->jadwalMilik($idKaryawan, $idJadwal, kunci: true);

            if ($jadwal === null) {
                $this->gagal('id_jadwal', 'Jadwal yang dipilih bukan milik Anda atau penempatannya sudah tidak aktif.');
            }

            $this->pastikanJadwalDapatDiajukan($jadwal);

            $alasanRekan = $this->alasanRekanTidakTersedia($idKaryawanRekan, $jadwal);
            if ($alasanRekan !== null) {
                $this->gagal('id_karyawan_rekan', $alasanRekan);
            }

            try {
                return (int) DB::table('tbl_pertukaran_shift')->insertGetId([
                    'id_karyawan_pengaju' => $idKaryawan,
                    'id_karyawan_rekan' => $idKaryawanRekan,
                    'id_jadwal_pengaju' => $jadwal->id,
                    'tanggal_kerja' => $jadwal->tanggal_kerja,
                    'snapshot_pengaju' => json_encode($this->jadwalKerjaService->snapshot($jadwal), JSON_THROW_ON_ERROR),
                    'alasan' => $alasan,
                    'status' => self::STATUS_MENUNGGU,
                    'status_rekan' => self::REKAN_MENUNGGU,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $this->gagal('id_jadwal', 'Jadwal ini sudah memiliki pengajuan backup shift yang masih berjalan.');
            }
        });

        return $idPengajuan;
    }

    /**
     * Rekan menerima (diteruskan ke Operasional) atau menolak (pengajuan langsung Ditolak).
     */
    public function tanggapiRekan(int $idPengajuan, int $idKaryawanRekan, bool $terima, ?string $catatan): void
    {
        $this->sinkronkanKedaluwarsa();

        DB::transaction(function () use ($idPengajuan, $idKaryawanRekan, $terima, $catatan): void {
            $pengajuan = $this->ambilUntukDiproses($idPengajuan);

            if ((int) $pengajuan->id_karyawan_rekan !== $idKaryawanRekan) {
                $this->gagal('pengajuan', 'Permintaan backup shift tidak ditemukan.');
            }

            if ($pengajuan->status_rekan !== self::REKAN_MENUNGGU) {
                $this->gagal('pengajuan', 'Anda sudah menanggapi permintaan ini.');
            }

            if ($terima) {
                $alasan = $this->alasanRekanTidakTersedia($idKaryawanRekan, $this->jadwalPengajuan($pengajuan));
                if ($alasan !== null) {
                    $this->gagal('pengajuan', $alasan);
                }
            }

            $sekarang = now();
            DB::table('tbl_pertukaran_shift')
                ->where('id', $idPengajuan)
                ->update([
                    'status_rekan' => $terima ? self::REKAN_DITERIMA : self::REKAN_DITOLAK,
                    'catatan_rekan' => $catatan,
                    'ditanggapi_rekan_pada' => $sekarang,
                    ...($terima ? [] : ['status' => self::STATUS_DITOLAK, 'diproses_pada' => $sekarang]),
                    'updated_at' => $sekarang,
                ]);
        });

    }

    /**
     * Operasional menyetujui: shift pengaju ditugaskan kepada rekan (id_karyawan_pengganti).
     */
    public function setujui(int $idPengajuan, int $idUser): void
    {
        $this->sinkronkanKedaluwarsa();

        DB::transaction(function () use ($idPengajuan, $idUser): void {
            $pengajuan = $this->ambilUntukDiproses($idPengajuan);
            $jadwal = DB::table('tbl_jadwal_kerja')->where('id', $pengajuan->id_jadwal_pengaju)->lockForUpdate()->first();

            $masalah = $this->masalahPersetujuan($pengajuan);
            if ($masalah !== []) {
                $this->gagal('pengajuan', $masalah[0]);
            }

            $this->simpanKeputusan($idPengajuan, self::STATUS_DISETUJUI, $idUser, null);
            $this->jadwalKerjaService->simpanNilaiJadwal(
                (int) $jadwal->id_penempatan,
                (string) $jadwal->tanggal_kerja,
                $jadwal,
                [
                    'status' => $jadwal->status,
                    'id_template_shift' => $jadwal->id_template_shift,
                    'nama_shift_snapshot' => $jadwal->nama_shift_snapshot,
                    'jam_masuk' => $jadwal->jam_masuk,
                    'jam_pulang' => $jadwal->jam_pulang,
                    'durasi_menit' => $jadwal->durasi_menit,
                    'id_karyawan_pengganti' => (int) $pengajuan->id_karyawan_rekan,
                ],
                'Diubah',
                $idUser,
                ['pertukaran_shift' => (int) $pengajuan->id],
            );
        });

    }

    /**
     * Operasional menolak pengajuan. Jadwal tidak disentuh.
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
                $this->gagal('pengajuan', 'Pengajuan backup shift tidak ditemukan.');
            }

            $this->simpanKeputusan($idPengajuan, self::STATUS_DIBATALKAN, $idUser, null);
        });

    }

    /**
     * Menandai Kedaluwarsa pengajuan Menunggu yang melewati batas: tanggapan rekan (5 jam sebelum shift)
     * atau keputusan Operasional (saat shift dimulai).
     * Mengembalikan jumlah pengajuan yang diubah.
     */
    public function sinkronkanKedaluwarsa(): int
    {
        if (! $this->tersedia()) {
            return 0;
        }

        $sekarang = CarbonImmutable::now();
        $kandidat = DB::table('tbl_pertukaran_shift as ps')
            ->join('tbl_jadwal_kerja as j', 'j.id', '=', 'ps.id_jadwal_pengaju')
            ->where('ps.status', self::STATUS_MENUNGGU)
            ->where('ps.tanggal_kerja', '<=', $sekarang->addDay()->toDateString())
            ->get(['ps.id', 'ps.status_rekan', 'ps.tanggal_kerja', 'j.jam_masuk']);

        $jumlah = 0;
        foreach ($kandidat as $pengajuan) {
            $olehRekan = $pengajuan->status_rekan === self::REKAN_MENUNGGU;
            $batas = $olehRekan ? $this->batasTanggapanRekan($pengajuan) : $this->mulaiShift($pengajuan);

            if ($batas->isFuture()) {
                continue;
            }

            $diubah = DB::table('tbl_pertukaran_shift')
                ->where('id', $pengajuan->id)
                ->where('status', self::STATUS_MENUNGGU)
                ->update([
                    'status' => self::STATUS_KEDALUWARSA,
                    'catatan_keputusan' => $olehRekan
                        ? 'Rekan tidak menanggapi hingga '.self::JAM_BATAS_TANGGAPAN_REKAN.' jam sebelum shift dimulai.'
                        : 'Operasional belum memutuskan hingga shift dimulai.',
                    'diproses_pada' => $sekarang,
                    'updated_at' => $sekarang,
                ]);

            if ($diubah === 1) {
                $jumlah++;
            }
        }

        return $jumlah;
    }

    /* =====================================================================
     * Pemeriksaan
     * ===================================================================== */

    /**
     * Daftar alasan pengajuan belum dapat disetujui Operasional (kosong = siap disetujui).
     *
     * @return list<string>
     */
    public function masalahPersetujuan(object $pengajuan): array
    {
        if ($pengajuan->status !== self::STATUS_MENUNGGU) {
            return ['Pengajuan ini sudah diproses sebelumnya.'];
        }

        if ($pengajuan->status_rekan !== self::REKAN_DITERIMA) {
            return ['Rekan pengganti belum menerima permintaan ini.'];
        }

        if (! $this->mulaiShift($pengajuan)->isFuture()) {
            return ['Shift sudah dimulai, pengajuan tidak dapat disetujui.'];
        }

        $jadwal = $this->jadwalPengajuan($pengajuan);

        if ($jadwal === null || (int) $jadwal->id_karyawan !== (int) $pengajuan->id_karyawan_pengaju) {
            return ['Penempatan pengaju sudah tidak aktif pada tanggal tersebut.'];
        }

        $masalah = [];

        if ($this->jadwalKerjaService->snapshot($jadwal) != $this->snapshotTersimpan($pengajuan->snapshot_pengaju)) {
            $masalah[] = 'Jadwal pengaju sudah diubah setelah pengajuan dibuat. Tolak pengajuan ini dan minta karyawan mengajukan ulang.';
        }

        $alasanRekan = $this->alasanRekanTidakTersedia((int) $pengajuan->id_karyawan_rekan, $jadwal);
        if ($alasanRekan !== null) {
            $masalah[] = $alasanRekan;
        }

        return $masalah;
    }

    /**
     * Alasan rekan tidak dapat menggantikan jadwal, atau null bila tersedia.
     * $jadwal hasil queryJadwalAktif() (memuat id_karyawan & id_jenis_layanan pemilik).
     */
    public function alasanRekanTidakTersedia(int $idRekan, ?object $jadwal): ?string
    {
        if ($jadwal === null) {
            return 'Jadwal yang akan digantikan sudah tidak tersedia.';
        }

        if ($idRekan === (int) $jadwal->id_karyawan) {
            return 'Rekan pengganti tidak boleh diri sendiri.';
        }

        $rekan = DB::table('tbl_karyawan')->where('id', $idRekan)->first(['id', 'nama', 'id_jenis_layanan']);

        if ($rekan === null || $jadwal->id_jenis_layanan === null || (int) $rekan->id_jenis_layanan !== (int) $jadwal->id_jenis_layanan) {
            return 'Rekan pengganti harus memiliki jabatan/jenis layanan yang sama.';
        }

        $tanggal = (string) $jadwal->tanggal_kerja;
        $jadwalRekan = $this->jadwalAbsensiService->jadwalPada($idRekan, $tanggal);

        if ($jadwalRekan['status'] === JadwalAbsensiService::STATUS_TANPA_PENEMPATAN) {
            return "{$rekan->nama} tidak memiliki penempatan aktif pada tanggal tersebut.";
        }

        if ($jadwalRekan['status'] === JadwalAbsensiService::STATUS_KERJA
            && ($jadwalRekan['shift']?->idPenempatan !== (int) $jadwal->id_penempatan)) {
            return "{$rekan->nama} sudah memiliki jadwal kerja pada tanggal tersebut.";
        }

        $mulai = $this->mulaiShift($jadwal);
        $selesai = $mulai->addMinutes($this->durasiMenit($jadwal));
        $hari = CarbonImmutable::parse($tanggal);

        foreach ([$hari->subDay(), $hari->addDay()] as $hariSekitar) {
            $shift = $this->jadwalAbsensiService->jadwalPada($idRekan, $hariSekitar->toDateString())['shift'];

            if ($shift instanceof ShiftAbsensi && $shift->mulai->lt($selesai) && $mulai->lt($shift->selesai)) {
                return "Shift ini bertumpuk dengan jadwal {$rekan->nama} pada {$hariSekitar->format('d/m/Y')}.";
            }
        }

        return null;
    }

    /* =====================================================================
     * Daftar & hitungan
     * ===================================================================== */

    /** Pengajuan yang menunggu keputusan Operasional (sudah diterima rekan). */
    public function jumlahMenunggu(): int
    {
        if (! $this->tersedia()) {
            return 0;
        }

        $this->sinkronkanKedaluwarsa();

        return DB::table('tbl_pertukaran_shift')
            ->where('status', self::STATUS_MENUNGGU)
            ->where('status_rekan', self::REKAN_DITERIMA)
            ->count();
    }

    /** Permintaan backup yang menunggu tanggapan karyawan ini sebagai rekan. */
    public function jumlahPermintaanMasuk(int $idKaryawan): int
    {
        if (! $this->tersedia()) {
            return 0;
        }

        $this->sinkronkanKedaluwarsa();

        return DB::table('tbl_pertukaran_shift')
            ->where('id_karyawan_rekan', $idKaryawan)
            ->where('status', self::STATUS_MENUNGGU)
            ->where('status_rekan', self::REKAN_MENUNGGU)
            ->count();
    }

    /**
     * Jumlah pengajuan per status (badge filter dan ringkasan Beranda). "Menunggu" di sini
     * dipecah: menunggu rekan vs menunggu Operasional.
     *
     * @return array{Menunggu: int, Disetujui: int, Ditolak: int, Dibatalkan: int, Kedaluwarsa: int, MenungguRekan: int}
     */
    public function ringkasanStatus(): array
    {
        $ringkasan = array_fill_keys(self::SEMUA_STATUS, 0) + ['MenungguRekan' => 0];

        if (! $this->tersedia()) {
            return $ringkasan;
        }

        $this->sinkronkanKedaluwarsa();

        $baris = DB::table('tbl_pertukaran_shift')
            ->groupBy('status', 'status_rekan')
            ->selectRaw('status, status_rekan, COUNT(*) as jumlah')
            ->get();

        foreach ($baris as $item) {
            if ($item->status === self::STATUS_MENUNGGU && $item->status_rekan === self::REKAN_MENUNGGU) {
                $ringkasan['MenungguRekan'] += (int) $item->jumlah;

                continue;
            }

            $ringkasan[$item->status] += (int) $item->jumlah;
        }

        return $ringkasan;
    }

    /**
     * Query daftar pengajuan lengkap dengan nama karyawan, klien jadwal, jam shift, dan pemroses.
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
                'jp.jam_masuk',
                'jp.jam_pulang',
                'pp.id_klien',
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
        $this->sinkronkanKedaluwarsa();

        return $this->queryDaftar()
            ->where(fn (Builder $query) => $query->where('ps.id_karyawan_pengaju', $idKaryawan)->orWhere('ps.id_karyawan_rekan', $idKaryawan))
            ->orderByDesc('ps.created_at')
            ->orderByDesc('ps.id')
            ->paginate($perHalaman)
            ->withQueryString();
    }

    /**
     * Permintaan backup yang menunggu tanggapan karyawan ini sebagai rekan, shift terdekat dahulu.
     *
     * @return Collection<int, object>
     */
    public function permintaanMasuk(int $idKaryawan): Collection
    {
        $this->sinkronkanKedaluwarsa();

        return $this->queryDaftar()
            ->where('ps.id_karyawan_rekan', $idKaryawan)
            ->where('ps.status', self::STATUS_MENUNGGU)
            ->where('ps.status_rekan', self::REKAN_MENUNGGU)
            ->orderBy('ps.tanggal_kerja')
            ->orderBy('jp.jam_masuk')
            ->get();
    }

    /* =====================================================================
     * Label & waktu
     * ===================================================================== */

    /**
     * Label tahap pengajuan untuk tampilan.
     */
    public static function labelTahap(object $pengajuan): string
    {
        return match (true) {
            $pengajuan->status === self::STATUS_MENUNGGU && $pengajuan->status_rekan === self::REKAN_MENUNGGU => 'Menunggu Tanggapan Rekan',
            $pengajuan->status === self::STATUS_MENUNGGU => 'Menunggu Persetujuan Operasional',
            $pengajuan->status === self::STATUS_DITOLAK && $pengajuan->status_rekan === self::REKAN_DITOLAK => 'Ditolak Rekan',
            $pengajuan->status === self::STATUS_DITOLAK => 'Ditolak Operasional',
            default => (string) $pengajuan->status,
        };
    }

    public static function kelasBadge(string $status): string
    {
        return match ($status) {
            self::STATUS_DISETUJUI => 'badge-success',
            self::STATUS_DITOLAK => 'badge-danger',
            self::STATUS_DIBATALKAN, self::STATUS_KEDALUWARSA => 'badge-secondary',
            default => 'badge-warning',
        };
    }

    /** Label tombol filter status di halaman Operasional. */
    public static function labelStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_MENUNGGU => 'Menunggu Persetujuan',
            'MenungguRekan' => 'Menunggu Rekan',
            default => $status,
        };
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

    /** Waktu mulai shift (WIB) dari objek ber-tanggal_kerja + jam_masuk. */
    public function mulaiShift(object $jadwal): CarbonImmutable
    {
        return CarbonImmutable::parse(substr((string) $jadwal->tanggal_kerja, 0, 10).' '.substr((string) $jadwal->jam_masuk, 0, 8));
    }

    /** Batas rekan menanggapi: JAM_BATAS_TANGGAPAN_REKAN jam sebelum shift dimulai. */
    public function batasTanggapanRekan(object $jadwal): CarbonImmutable
    {
        return $this->mulaiShift($jadwal)->subHours(self::JAM_BATAS_TANGGAPAN_REKAN);
    }

    /* =====================================================================
     * Internal
     * ===================================================================== */

    /**
     * Jadwal milik karyawan dari penempatan Aktif yang berlaku pada tanggalnya, atau null.
     */
    private function jadwalMilik(int $idKaryawan, int $idJadwal, bool $kunci = false): ?object
    {
        return $this->queryJadwalAktif()
            ->where('j.id', $idJadwal)
            ->where('p.id_karyawan', $idKaryawan)
            ->when($kunci, fn (Builder $query) => $query->lockForUpdate())
            ->first();
    }

    /** Jadwal pengaju sebuah pengajuan (hanya bila penempatannya masih aktif pada tanggal itu). */
    private function jadwalPengajuan(object $pengajuan): ?object
    {
        return $this->queryJadwalAktif()->where('j.id', $pengajuan->id_jadwal_pengaju)->first();
    }

    private function pastikanJadwalDapatDiajukan(object $jadwal): void
    {
        if ($jadwal->status !== 'Kerja' || blank($jadwal->jam_masuk)) {
            $this->gagal('id_jadwal', 'Hanya jadwal Kerja yang dapat dimintakan backup shift.');
        }

        if ($jadwal->id_karyawan_pengganti !== null) {
            $this->gagal('id_jadwal', 'Jadwal ini sudah digantikan rekan.');
        }

        if ((string) $jadwal->tanggal_kerja <= $this->hariIni()->toDateString()) {
            $this->gagal('id_jadwal', 'Pengajuan backup shift paling lambat H-1 sebelum tanggal shift.');
        }

        if ((string) $jadwal->tanggal_kerja > $this->hariIni()->addDays(self::HARI_KE_DEPAN)->toDateString()) {
            $this->gagal('id_jadwal', 'Jadwal yang dipilih berada di luar batas pilihan tanggal.');
        }

        if (! $this->batasTanggapanRekan($jadwal)->isFuture()) {
            $this->gagal('id_jadwal', 'Waktu tanggapan rekan sudah habis ('.self::JAM_BATAS_TANGGAPAN_REKAN.' jam sebelum shift dimulai).');
        }
    }

    private function queryJadwalAktif(): Builder
    {
        return DB::table('tbl_jadwal_kerja as j')
            ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->where('p.status', 'Aktif')
            ->whereColumn('p.tgl_mulai', '<=', 'j.tanggal_kerja')
            ->where(fn (Builder $query) => $query->whereNull('p.tgl_selesai')->orWhereColumn('p.tgl_selesai', '>=', 'j.tanggal_kerja'))
            ->select(['j.*', 'p.id_karyawan', 'p.id_klien', 'k.id_jenis_layanan']);
    }

    private function ambilUntukDiproses(int $idPengajuan): object
    {
        $terkunci = DB::table('tbl_pertukaran_shift')->where('id', $idPengajuan)->lockForUpdate()->exists();
        $pengajuan = $terkunci ? $this->detail($idPengajuan) : null;

        if ($pengajuan === null) {
            $this->gagal('pengajuan', 'Pengajuan backup shift tidak ditemukan.');
        }

        if ($pengajuan->status !== self::STATUS_MENUNGGU) {
            $this->gagal('pengajuan', 'Pengajuan ini sudah '.strtolower(self::labelTahap($pengajuan)).' sebelumnya dan tidak dapat diproses lagi.');
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

    /** Contoh: "Sabtu, 21/11/2026 Pagi · 07:00–15:00". */
    private function ringkasJadwal(object $pengajuan): string
    {
        return CarbonImmutable::parse((string) $pengajuan->tanggal_kerja)->locale('id')->translatedFormat('l, d/m/Y')
            .' '.$this->labelShift($pengajuan->snapshot_pengaju);
    }

    private function durasiMenit(object $jadwal): int
    {
        return $jadwal->durasi_menit !== null
            ? (int) $jadwal->durasi_menit
            : $this->jadwalKerjaService->hitungDurasiMenit(substr((string) $jadwal->jam_masuk, 0, 5), substr((string) $jadwal->jam_pulang, 0, 5));
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
        return CarbonImmutable::now()->startOfDay();
    }

    private function gagal(string $kunci, string $pesan): never
    {
        throw ValidationException::withMessages([$kunci => $pesan]);
    }
}

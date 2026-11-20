<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Pengajuan Pertukaran Shift: karyawan menukar satu jadwalnya dengan satu jadwal rekan
 * pada klien yang sama (tanggal boleh berbeda). Rekan menyetujui lebih dulu (dinyatakan
 * pengaju saat mengajukan), lalu Operasional memberi persetujuan akhir.
 *
 * Saat disetujui, TANGGAL dan ISI SHIFT kedua jadwal ditukar, sedangkan id_penempatan
 * tetap milik karyawan masing-masing. Contoh: jadwal A (penempatan 425, 23/11 08–16) dan
 * jadwal B (penempatan 424, 25/11 16–00) menjadi A = 25/11 16–00, B = 23/11 08–16.
 *
 * Aturan:
 * - kedua tanggal paling lambat H-1 (harus setelah hari ini);
 * - kedua karyawan punya penempatan Aktif di klien yang sama yang berlaku pada tanggal asal
 *   dan tanggal tujuan;
 * - kedua karyawan memiliki jenis layanan yang sama;
 * - rekan menerima pengajuan lebih dulu (status_rekan 'Diterima'); bila rekan menolak, pengajuan langsung Ditolak;
 * - pengaju dapat membatalkan pengajuan selama masih Menunggu;
 * - belum ada absensi pada kedua tanggal dan belum ada pengajuan lain yang menunggu;
 * - tidak ada jadwal lain milik karyawan pada tanggal tujuan;
 * - saat disetujui, jadwal harus masih sama dengan saat pengajuan dan shift baru tidak
 *   boleh bertumpuk dengan shift hari sebelum/sesudahnya;
 * - pengajuan yang sudah diproses tidak dapat diproses lagi.
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
     * Pilihan form karyawan: jadwal mendatang miliknya beserta jadwal rekan (klien yang sama)
     * yang dapat diajak bertukar. Pasangan yang pasti gagal disaring sejak awal.
     *
     * @return list<array{id_jadwal: int, tanggal: string, shift: string, rekan: list<array{id_jadwal: int, id_karyawan: int, nama: string, nip: string, tanggal: string, shift: string}>}>
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
            ->get();

        // Semua jadwal (status apa pun, penempatan mana pun) para karyawan terkait dalam rentang
        // pilihan, untuk menyaring pasangan yang tanggal tujuannya sudah terisi jadwal lain.
        $jadwalTerisi = DB::table('tbl_jadwal_kerja as j')
            ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
            ->whereIn('p.id_karyawan', [$idKaryawan, ...$jadwalRekan->pluck('id_karyawan')->unique()->all()])
            ->whereBetween('j.tanggal_kerja', [$besok, $batas])
            ->get(['j.id', 'j.tanggal_kerja', 'p.id_karyawan'])
            ->groupBy('id_karyawan');

        $tanggalTujuanBebas = static fn (int $idPemilik, string $tanggal, array $kecualikan): bool => ! collect($jadwalTerisi->get($idPemilik, []))
            ->contains(static fn (object $item): bool => (string) $item->tanggal_kerja === $tanggal && ! in_array((int) $item->id, $kecualikan, true));

        $jadwalRekan = $jadwalRekan->groupBy('id_klien');
        $sedangDiajukan = $this->idJadwalMenunggu();
        $opsi = [];

        foreach ($jadwalSaya as $jadwal) {
            if (in_array((int) $jadwal->id, $sedangDiajukan, true)) {
                continue;
            }

            $rekan = collect($jadwalRekan->get($jadwal->id_klien, []))
                ->reject(function (object $item) use ($jadwal, $idKaryawan, $sedangDiajukan, $tanggalTujuanBebas): bool {
                    $kecualikan = [(int) $jadwal->id, (int) $item->id];

                    return in_array((int) $item->id, $sedangDiajukan, true)
                        || ! $this->layananSama($jadwal, $item)
                        || ! $this->pasanganBermakna($jadwal, $item)
                        || ! $this->jadwalKerjaService->tanggalBerlaku((string) $item->tanggal_kerja, $jadwal)
                        || ! $this->jadwalKerjaService->tanggalBerlaku((string) $jadwal->tanggal_kerja, $item)
                        || ! $tanggalTujuanBebas($idKaryawan, (string) $item->tanggal_kerja, $kecualikan)
                        || ! $tanggalTujuanBebas((int) $item->id_karyawan, (string) $jadwal->tanggal_kerja, $kecualikan);
                })
                ->map(fn (object $item): array => [
                    'id_jadwal' => (int) $item->id,
                    'id_karyawan' => (int) $item->id_karyawan,
                    'nama' => (string) $item->nama,
                    'nip' => (string) $item->nip,
                    'tanggal' => (string) $item->tanggal_kerja,
                    'shift' => $this->labelShift($this->jadwalKerjaService->snapshot($item)),
                ])
                ->values()
                ->all();

            if ($rekan === []) {
                continue;
            }

            $opsi[] = [
                'id_jadwal' => (int) $jadwal->id,
                'tanggal' => (string) $jadwal->tanggal_kerja,
                'shift' => $this->labelShift($this->jadwalKerjaService->snapshot($jadwal)),
                'rekan' => $rekan,
            ];
        }

        return $opsi;
    }

    /**
     * Membuat pengajuan berstatus Menunggu untuk menukar jadwal pengaju dengan jadwal rekan
     * (tanggal boleh sama atau berbeda).
     */
    public function ajukan(
        int $idKaryawan,
        int $idJadwalPengaju,
        int $idJadwalRekan,
        string $alasan,
    ): int {
        return DB::transaction(function () use (
            $idKaryawan,
            $idJadwalPengaju,
            $idJadwalRekan,
            $alasan,
        ): int {
            if ($idJadwalPengaju === $idJadwalRekan) {
                $this->gagal('id_jadwal_rekan', 'Pilih dua jadwal yang berbeda.');
            }

            $jadwalPengaju = $this->queryJadwalAktif()
                ->where('j.id', $idJadwalPengaju)
                ->where('p.id_karyawan', $idKaryawan)
                ->lockForUpdate()
                ->first();

            if ($jadwalPengaju === null) {
                $this->gagal('id_jadwal_pengaju', 'Jadwal Anda tidak ditemukan atau penempatan sudah tidak aktif.');
            }

            $jadwalRekan = $this->queryJadwalAktif()
                ->where('j.id', $idJadwalRekan)
                ->lockForUpdate()
                ->first();

            if ($jadwalRekan === null || (int) $jadwalRekan->id_karyawan === $idKaryawan) {
                $this->gagal('id_jadwal_rekan', 'Jadwal rekan tidak ditemukan atau tidak valid.');
            }

            $tanggalPengaju = (string) $jadwalPengaju->tanggal_kerja;
            $tanggalRekan = (string) $jadwalRekan->tanggal_kerja;

            $this->pastikanMinimalHMinusSatu($tanggalPengaju);
            $this->pastikanMinimalHMinusSatu($tanggalRekan);

            $batas = $this->hariIni()->addDays(self::HARI_KE_DEPAN)->toDateString();

            if ($tanggalPengaju > $batas || $tanggalRekan > $batas) {
                $this->gagal('id_jadwal_rekan', 'Jadwal yang dipilih berada di luar batas pilihan tanggal.');
            }

            if ((int) $jadwalPengaju->id_klien !== (int) $jadwalRekan->id_klien) {
                $this->gagal('id_jadwal_rekan', 'Pertukaran hanya dapat dilakukan dengan rekan pada klien yang sama.');
            }

            if (! $this->layananSama($jadwalPengaju, $jadwalRekan)) {
                $this->gagal('id_jadwal_rekan', 'Pertukaran hanya dapat dilakukan dengan rekan yang memiliki jenis layanan yang sama.');
            }

            if (! $this->pasanganBermakna($jadwalPengaju, $jadwalRekan)) {
                $this->gagal('id_jadwal_rekan', 'Shift Anda dan rekan sama, tidak ada yang perlu ditukar.');
            }

            $idJadwal = [$idJadwalPengaju, $idJadwalRekan];

            if (array_intersect($idJadwal, $this->idJadwalMenunggu()) !== []) {
                $this->gagal('id_jadwal_pengaju', 'Salah satu jadwal sudah memiliki pengajuan yang masih menunggu persetujuan.');
            }

            $idKaryawanRekan = (int) $jadwalRekan->id_karyawan;
            $idKaryawanTerlibat = [$idKaryawan, $idKaryawanRekan];

            if (
                $this->sudahAbsen($idKaryawanTerlibat, $tanggalPengaju)
                || $this->sudahAbsen($idKaryawanTerlibat, $tanggalRekan)
            ) {
                $this->gagal('id_jadwal_pengaju', 'Absensi pada salah satu tanggal sudah tercatat. Jadwal tidak dapat ditukar.');
            }

            if (
                ! $this->jadwalKerjaService->tanggalBerlaku($tanggalRekan, $jadwalPengaju)
                || ! $this->jadwalKerjaService->tanggalBerlaku($tanggalPengaju, $jadwalRekan)
            ) {
                $this->gagal('id_jadwal_rekan', 'Tanggal tujuan berada di luar masa berlaku penempatan Anda atau rekan.');
            }

            if (
                $this->adaJadwalLain($idKaryawan, $tanggalRekan, $idJadwal)
                || $this->adaJadwalLain($idKaryawanRekan, $tanggalPengaju, $idJadwal)
            ) {
                $this->gagal('id_jadwal_rekan', 'Anda atau rekan sudah memiliki jadwal lain pada tanggal tujuan pertukaran.');
            }

            return (int) DB::table('tbl_pertukaran_shift')->insertGetId([
                'id_karyawan_pengaju' => $idKaryawan,
                'id_karyawan_rekan' => $jadwalRekan->id_karyawan,
                'id_jadwal_pengaju' => $jadwalPengaju->id,
                'id_jadwal_rekan' => $jadwalRekan->id,
                'tanggal_kerja' => $tanggalPengaju,
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
     * Persetujuan akhir Operasional: tanggal dan isi shift kedua jadwal ditukar dalam satu
     * transaksi, id_penempatan tidak berubah, dan setiap perubahan tercatat di riwayat jadwal.
     */
    public function setujui(int $idPengajuan, int $idUser): void
    {
        try {
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

                $jadwalPengaju = $jadwal->get((int) $pengajuan->id_jadwal_pengaju);
                $jadwalRekan = $jadwal->get((int) $pengajuan->id_jadwal_rekan);
                $sumber = ['pertukaran_shift' => (int) $pengajuan->id];

                $this->jadwalKerjaService->tukarTanggalDanNilai(
                    $jadwalPengaju,
                    (string) $jadwalRekan->tanggal_kerja,
                    $this->nilaiJadwal($jadwalRekan),
                    $idUser,
                    $sumber,
                );
                $this->jadwalKerjaService->tukarTanggalDanNilai(
                    $jadwalRekan,
                    (string) $jadwalPengaju->tanggal_kerja,
                    $this->nilaiJadwal($jadwalPengaju),
                    $idUser,
                    $sumber,
                );

                $this->simpanKeputusan($idPengajuan, self::STATUS_DISETUJUI, $idUser, null);
            });
        } catch (UniqueConstraintViolationException) {
            // Jadwal lain sempat dibuat pada tanggal tujuan setelah pemeriksaan; transaksi sudah di-rollback.
            $this->gagal('pengajuan', 'Sudah ada jadwal lain pada tanggal tujuan salah satu karyawan. Muat ulang halaman lalu periksa kembali.');
        }
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

            if ($terima) {
                $masalah = $this->masalahJadwal($pengajuan);
                if ($masalah !== []) {
                    $this->gagal('pengajuan', $masalah[0]);
                }
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
        if ($pengajuan->status === self::STATUS_MENUNGGU
            && (string) $pengajuan->tanggal_kerja <= CarbonImmutable::now((string) config('app.timezone', 'UTC'))->toDateString()) {
            return 'Kedaluwarsa';
        }

        if ($pengajuan->status === self::STATUS_DITOLAK && ($pengajuan->catatan_keputusan ?? null) === self::CATATAN_DIBATALKAN) {
            return 'Dibatalkan';
        }

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

        if (($pengajuan->status_rekan ?? self::REKAN_DITERIMA) !== self::REKAN_DITERIMA) {
            return ['Rekan belum menerima pengajuan ini. Persetujuan Operasional baru dapat diberikan setelah rekan menerima.'];
        }

        return $this->masalahJadwal($pengajuan);
    }

    /**
     * Pemeriksaan kondisi jadwal kedua karyawan (tanpa memandang tahap persetujuan):
     * tanggal, penempatan, klien, jenis layanan, perubahan jadwal, absensi, dan bentrok shift.
     *
     * @return list<string>
     */
    private function masalahJadwal(object $pengajuan): array
    {
        $tanggalPengaju = (string) $pengajuan->tanggal_kerja;
        $tanggalRekan = $this->tanggalKerjaRekan($pengajuan);

        if ($this->pengajuanKedaluwarsa($pengajuan)) {
            return ['Salah satu tanggal shift sudah lewat atau sedang berlangsung.'];
        }

        $jadwal = DB::table('tbl_jadwal_kerja as j')
            ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->whereIn('j.id', [(int) $pengajuan->id_jadwal_pengaju, (int) $pengajuan->id_jadwal_rekan])
            ->get([
                'j.*',
                'p.id_karyawan',
                'p.id_klien',
                'p.status as status_penempatan',
                'p.tgl_mulai',
                'p.tgl_selesai',
                'k.id_jenis_layanan',
            ])
            ->keyBy('id');

        $jadwalPengaju = $jadwal->get((int) $pengajuan->id_jadwal_pengaju);
        $jadwalRekan = $jadwal->get((int) $pengajuan->id_jadwal_rekan);

        if (
            $jadwalPengaju === null
            || $jadwalRekan === null
            || (int) $jadwalPengaju->id_karyawan !== (int) $pengajuan->id_karyawan_pengaju
            || (int) $jadwalRekan->id_karyawan !== (int) $pengajuan->id_karyawan_rekan
            || (int) $jadwalPengaju->id_penempatan === (int) $jadwalRekan->id_penempatan
        ) {
            return ['Jadwal salah satu karyawan sudah tidak ditemukan atau tidak sesuai.'];
        }

        if ((string) $jadwalPengaju->tanggal_kerja !== $tanggalPengaju || (string) $jadwalRekan->tanggal_kerja !== $tanggalRekan) {
            return ['Tanggal salah satu jadwal sudah berubah setelah pengajuan dibuat. Tolak pengajuan ini dan minta karyawan mengajukan ulang.'];
        }

        if ($jadwalPengaju->status_penempatan !== 'Aktif' || $jadwalRekan->status_penempatan !== 'Aktif') {
            return ['Penempatan salah satu karyawan sudah tidak aktif.'];
        }

        if ((int) $jadwalPengaju->id_klien !== (int) $jadwalRekan->id_klien) {
            return ['Kedua karyawan sudah tidak ditempatkan pada klien yang sama.'];
        }

        // Setelah ditukar: jadwal pengaju pindah ke tanggal rekan, jadwal rekan pindah ke tanggal pengaju.
        if (
            ! $this->jadwalKerjaService->tanggalBerlaku($tanggalRekan, $jadwalPengaju)
            || ! $this->jadwalKerjaService->tanggalBerlaku($tanggalPengaju, $jadwalRekan)
        ) {
            return ['Tanggal tujuan berada di luar masa berlaku salah satu penempatan.'];
        }

        if (! $this->layananSama($jadwalPengaju, $jadwalRekan)) {
            return ['Kedua karyawan tidak memiliki jenis layanan yang sama.'];
        }

        $masalah = [];

        if (
            $this->jadwalKerjaService->snapshot($jadwalPengaju) != $this->snapshotTersimpan($pengajuan->snapshot_pengaju)
            || $this->jadwalKerjaService->snapshot($jadwalRekan) != $this->snapshotTersimpan($pengajuan->snapshot_rekan)
        ) {
            $masalah[] = 'Jadwal salah satu karyawan sudah diubah setelah pengajuan dibuat. Tolak pengajuan ini dan minta karyawan mengajukan ulang.';
        }

        $idKaryawanTerlibat = [(int) $pengajuan->id_karyawan_pengaju, (int) $pengajuan->id_karyawan_rekan];

        if ($this->sudahAbsen($idKaryawanTerlibat, $tanggalPengaju) || $this->sudahAbsen($idKaryawanTerlibat, $tanggalRekan)) {
            $masalah[] = 'Absensi pada salah satu tanggal sudah tercatat.';
        }

        $idJadwalDitukar = [(int) $jadwalPengaju->id, (int) $jadwalRekan->id];

        foreach ([
            [(int) $pengajuan->id_karyawan_pengaju, $tanggalRekan, $jadwalRekan, $pengajuan->nama_pengaju ?? 'Pengaju'],
            [(int) $pengajuan->id_karyawan_rekan, $tanggalPengaju, $jadwalPengaju, $pengajuan->nama_rekan ?? 'Rekan'],
        ] as [$idKaryawan, $tanggalTujuan, $jadwalBaru, $nama]) {
            // Jadwal apa pun (Kerja/Libur) pada tanggal tujuan akan melanggar unique id_penempatan + tanggal_kerja.
            if ($this->adaJadwalLain($idKaryawan, $tanggalTujuan, $idJadwalDitukar)) {
                $masalah[] = "Sudah ada jadwal lain untuk {$nama} pada ".CarbonImmutable::parse($tanggalTujuan)->format('d/m/Y').'.';
            }

            $bentrok = $this->bentrokDenganHariSekitar($idKaryawan, $tanggalTujuan, $jadwalBaru, $idJadwalDitukar);

            if ($bentrok !== null) {
                $masalah[] = "Shift baru {$nama} bertumpuk dengan jadwalnya pada {$bentrok}.";
            }
        }

        return $masalah;
    }

    /**
     * Tanggal asal jadwal rekan. Pengajuan lama (sebelum kolom ditambahkan) memakai tanggal yang sama.
     */
    public function tanggalKerjaRekan(object $pengajuan): string
    {
        return (string) ($pengajuan->tanggal_kerja_rekan ?? $pengajuan->tanggal_kerja);
    }

    /**
     * True bila salah satu tanggal dalam pengajuan sudah lewat atau hari ini.
     */
    public function pengajuanKedaluwarsa(object $pengajuan): bool
    {
        return $this->tanggalSudahLewat(min((string) $pengajuan->tanggal_kerja, $this->tanggalKerjaRekan($pengajuan)));
    }

    public function jumlahMenunggu(): int
    {
        return $this->tersedia()
            ? DB::table('tbl_pertukaran_shift')
                ->where('status', self::STATUS_MENUNGGU)
                ->where('tanggal_kerja', '>', $this->hariIni()->toDateString())
                ->count()
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
     * Jumlah pengajuan yang menunggu tanggapan karyawan ini sebagai rekan.
     */
    public function jumlahMenungguTanggapan(int $idKaryawan): int
    {
        return $this->tersedia()
            ? DB::table('tbl_pertukaran_shift')
                ->where('id_karyawan_rekan', $idKaryawan)
                ->where('status', self::STATUS_MENUNGGU)
                ->where('status_rekan', self::REKAN_MENUNGGU)
                ->where('tanggal_kerja', '>', $this->hariIni()->toDateString())
                ->count()
            : 0;
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
     * Jadwal dari penempatan Aktif yang berlaku pada tanggal jadwal itu. Masa berlaku penempatan
     * ikut dipilih agar tanggal tujuan pertukaran dapat diperiksa dengan tanggalBerlaku().
     */
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
     * Jadwal yang sedang ditukar dikecualikan karena posisinya ikut berpindah.
     *
     * @param  list<int>  $kecualikanIdJadwal
     */
    private function bentrokDenganHariSekitar(
        int $idKaryawan,
        string $tanggal,
        object $jadwalBaru,
        array $kecualikanIdJadwal = [],
    ): ?string {
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
            ->when($kecualikanIdJadwal !== [], fn (Builder $query) => $query->whereNotIn('j.id', $kecualikanIdJadwal))
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
     * True bila karyawan punya jadwal lain (status apa pun, penempatan mana pun) pada tanggal itu.
     *
     * @param  list<int>  $kecualikanIdJadwal
     */
    private function adaJadwalLain(int $idKaryawan, string $tanggal, array $kecualikanIdJadwal): bool
    {
        return DB::table('tbl_jadwal_kerja as j')
            ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
            ->where('p.id_karyawan', $idKaryawan)
            ->where('j.tanggal_kerja', $tanggal)
            ->whereNotIn('j.id', $kecualikanIdJadwal)
            ->exists();
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
     * Pertukaran bermakna bila tanggalnya berbeda (hari kerja berpindah) atau,
     * pada tanggal yang sama, shift keduanya berbeda.
     */
    private function pasanganBermakna(object $jadwalA, object $jadwalB): bool
    {
        return (string) $jadwalA->tanggal_kerja !== (string) $jadwalB->tanggal_kerja
            || ! $this->shiftSama($jadwalA, $jadwalB);
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
        return DB::table('tbl_pertukaran_shift')
            ->where('status', self::STATUS_MENUNGGU)
            ->where('tanggal_kerja', '>', $this->hariIni()->toDateString())
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
            $this->gagal('id_jadwal_pengaju', 'Pengajuan pertukaran shift paling lambat H-1 sebelum tanggal shift.');
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

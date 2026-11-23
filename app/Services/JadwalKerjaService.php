<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JadwalKerjaService
{
    public const string AKSI_KERJA = 'kerja';

    public const string AKSI_LIBUR = 'libur';

    public const string AKSI_JADIKAN_KERJA = 'jadikan_kerja';

    public const string KONFLIK_LEWATI = 'lewati';

    public const string KONFLIK_GANTI = 'ganti';

    /**
     * Durasi dihitung melingkar 24 jam: jam pulang yang lebih kecil dari jam masuk
     * berarti pulang pada hari berikutnya (mis. 20:00–04:00 = 480 menit).
     */
    public function hitungDurasiMenit(string $jamMasuk, string $jamPulang): int
    {
        $durasiMenit = ($this->menitDariJam($jamPulang) - $this->menitDariJam($jamMasuk) + 1440) % 1440;

        if ($durasiMenit === 0) {
            throw ValidationException::withMessages([
                'jam_pulang' => 'Jam pulang tidak boleh sama dengan jam masuk.',
            ]);
        }

        return $durasiMenit;
    }

    public function pulangHariBerikutnya(?string $jamMasuk, ?string $jamPulang): bool
    {
        if ($jamMasuk === null || $jamPulang === null) {
            return false;
        }

        return $this->menitDariJam($jamPulang) <= $this->menitDariJam($jamMasuk);
    }

    /**
     * Mengatur jadwal beberapa tanggal (berurutan maupun tidak) milik satu karyawan.
     * Setiap tanggal dipetakan ke penempatan Aktif karyawan yang berlaku pada tanggal itu.
     * Operasi bersifat atomik: bila satu tanggal tidak valid, tidak ada yang disimpan.
     *
     * @param  list<string>  $daftarTanggal
     * @param  array{id_template_shift?: int|string|null, jam_masuk?: string|null, jam_pulang?: string|null}  $data
     * @return array{disimpan: int, dilewati: list<string>}
     */
    public function aturTanggal(int $idKaryawan, array $daftarTanggal, string $aksi, array $data, int $idUser): array
    {
        return DB::transaction(function () use ($idKaryawan, $daftarTanggal, $aksi, $data, $idUser): array {
            $daftarTanggal = array_values(array_unique($daftarTanggal));
            sort($daftarTanggal);

            $penempatanAktif = DB::table('tbl_penempatan')
                ->where('id_karyawan', $idKaryawan)
                ->where('status', 'Aktif')
                ->lockForUpdate()
                ->get();
            $penempatanPerTanggal = [];
            $tanpaPenempatan = [];

            foreach ($daftarTanggal as $tanggal) {
                $penempatan = $penempatanAktif->first(fn (object $item): bool => $this->tanggalBerlaku($tanggal, $item));

                if ($penempatan === null) {
                    $tanpaPenempatan[] = CarbonImmutable::parse($tanggal)->format('d/m/Y');

                    continue;
                }

                $penempatanPerTanggal[$tanggal] = $penempatan;
            }

            if ($tanpaPenempatan !== []) {
                throw ValidationException::withMessages([
                    'tanggal' => 'Tanggal berikut berada di luar masa penempatan aktif karyawan: '.implode(', ', $tanpaPenempatan).'.',
                ]);
            }

            $jadwalAda = DB::table('tbl_jadwal_kerja')
                ->whereIn('id_penempatan', $penempatanAktif->pluck('id')->all())
                ->whereIn('tanggal_kerja', $daftarTanggal)
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (object $jadwal): string => $jadwal->id_penempatan.'|'.$jadwal->tanggal_kerja);

            $dilewati = [];
            $tanggalTarget = $daftarTanggal;

            if ($aksi === self::AKSI_JADIKAN_KERJA) {
                $tanggalTarget = array_values(array_filter(
                    $daftarTanggal,
                    static fn (string $tanggal): bool => $jadwalAda
                        ->get($penempatanPerTanggal[$tanggal]->id.'|'.$tanggal)?->status === 'Libur',
                ));
                $dilewati = array_values(array_diff($daftarTanggal, $tanggalTarget));

                if ($tanggalTarget === []) {
                    throw ValidationException::withMessages([
                        'tanggal' => 'Tidak ada tanggal berstatus Libur di antara tanggal yang dipilih.',
                    ]);
                }
            }

            foreach ($tanggalTarget as $tanggal) {
                $penempatan = $penempatanPerTanggal[$tanggal];
                $jadwalLama = $jadwalAda->get($penempatan->id.'|'.$tanggal);
                $nilaiJadwal = $aksi === self::AKSI_LIBUR
                    ? $this->nilaiLibur()
                    : $this->nilaiKerja(
                        $this->idTemplateAtauNull($data['id_template_shift'] ?? null),
                        $data['jam_masuk'] ?? null,
                        $data['jam_pulang'] ?? null,
                        $penempatan,
                        $jadwalLama?->id_template_shift === null ? null : (int) $jadwalLama->id_template_shift,
                    );

                $this->simpanNilaiJadwal($penempatan->id, $tanggal, $jadwalLama, $nilaiJadwal, $jadwalLama === null ? 'Dibuat' : 'Diubah', $idUser);
            }

            return ['disimpan' => count($tanggalTarget), 'dilewati' => $dilewati];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function buatJadwal(array $data, int $idUser): int
    {
        return DB::transaction(function () use ($data, $idUser): int {
            $penempatan = $this->ambilPenempatan((int) $data['id_penempatan'], true);
            $tanggalKerja = (string) $data['tanggal_kerja'];
            $this->pastikanTanggalDalamPenempatan($tanggalKerja, $penempatan);
            $this->pastikanPenempatanAktif($penempatan);

            $sudahAda = DB::table('tbl_jadwal_kerja')
                ->where('id_penempatan', $penempatan->id)
                ->where('tanggal_kerja', $tanggalKerja)
                ->lockForUpdate()
                ->exists();

            if ($sudahAda) {
                throw ValidationException::withMessages([
                    'tanggal_kerja' => 'Jadwal untuk penempatan dan tanggal tersebut sudah ada.',
                ]);
            }

            return $this->simpanNilaiJadwal(
                $penempatan->id,
                $tanggalKerja,
                null,
                $this->nilaiJadwal($data, $penempatan),
                'Dibuat',
                $idUser,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function ubahJadwal(int $idJadwal, array $data, int $idUser): void
    {
        DB::transaction(function () use ($idJadwal, $data, $idUser): void {
            $jadwalLama = DB::table('tbl_jadwal_kerja')
                ->where('id', $idJadwal)
                ->lockForUpdate()
                ->first();

            if ($jadwalLama === null) {
                throw ValidationException::withMessages([
                    'jadwal' => 'Jadwal yang akan diubah tidak ditemukan.',
                ]);
            }

            $penempatan = $this->ambilPenempatan((int) $jadwalLama->id_penempatan, true);
            $this->pastikanTanggalDalamPenempatan((string) $jadwalLama->tanggal_kerja, $penempatan);

            $this->simpanNilaiJadwal(
                $penempatan->id,
                (string) $jadwalLama->tanggal_kerja,
                $jadwalLama,
                $this->nilaiJadwal($data, $penempatan, $jadwalLama),
                'Diubah',
                $idUser,
            );
        });
    }

    /**
     * @return array{
     *     bulan_sumber: string,
     *     bulan_tujuan: string,
     *     penempatan: object,
     *     template_shift: Collection<int, object>,
     *     item: list<array<string, mixed>>,
     *     jumlah_konflik: int,
     *     jumlah_dilewati: int,
     *     jadwal_tujuan_tambahan: Collection<int, object>,
     *     jumlah_jadwal_tujuan: int
     * }
     */
    public function pratinjauSalinBulanan(int $idPenempatan, string $bulanTujuan, ?string $bulanSumber = null): array
    {
        $penempatan = $this->ambilPenempatan($idPenempatan);
        $this->pastikanPenempatanAktif($penempatan);
        $target = CarbonImmutable::parse($bulanTujuan.'-01');
        $sumber = $bulanSumber === null ? $target->subMonth() : CarbonImmutable::parse($bulanSumber.'-01');

        if ($sumber->format('Y-m') === $target->format('Y-m')) {
            throw ValidationException::withMessages([
                'bulan_sumber' => 'Bulan sumber dan bulan tujuan tidak boleh sama.',
            ]);
        }

        $jadwalSumber = DB::table('tbl_jadwal_kerja')
            ->where('id_penempatan', $idPenempatan)
            ->whereBetween('tanggal_kerja', [$sumber->startOfMonth()->toDateString(), $sumber->endOfMonth()->toDateString()])
            ->orderBy('tanggal_kerja')
            ->get();
        $jadwalTujuan = DB::table('tbl_jadwal_kerja')
            ->where('id_penempatan', $idPenempatan)
            ->whereBetween('tanggal_kerja', [$target->startOfMonth()->toDateString(), $target->endOfMonth()->toDateString()])
            ->get()
            ->keyBy('tanggal_kerja');

        $item = [];
        $templateIds = [];
        $jumlahKonflik = 0;
        $jumlahDilewati = 0;

        foreach ($jadwalSumber as $jadwal) {
            $nomorHari = (int) substr((string) $jadwal->tanggal_kerja, 8, 2);
            $baris = [
                'tanggal_sumber' => $jadwal->tanggal_kerja,
                'tanggal_kerja' => null,
                'status' => $jadwal->status,
                'id_template_shift' => $jadwal->id_template_shift,
                'nama_shift_snapshot' => $jadwal->nama_shift_snapshot,
                'jam_masuk' => $this->formatJam($jadwal->jam_masuk),
                'jam_pulang' => $this->formatJam($jadwal->jam_pulang),
                'durasi_menit' => $jadwal->durasi_menit,
                'dilewati' => false,
                'alasan_dilewati' => null,
                'konflik' => false,
                'jadwal_tujuan' => null,
            ];

            if ($nomorHari > $target->daysInMonth) {
                $baris['dilewati'] = true;
                $baris['alasan_dilewati'] = 'Tanggal tersebut tidak tersedia pada bulan tujuan.';
                $jumlahDilewati++;
                $item[] = $baris;

                continue;
            }

            $tanggalTujuan = $target->format('Y-m-').str_pad((string) $nomorHari, 2, '0', STR_PAD_LEFT);
            $baris['tanggal_kerja'] = $tanggalTujuan;

            if (! $this->tanggalBerlaku($tanggalTujuan, $penempatan)) {
                $baris['dilewati'] = true;
                $baris['alasan_dilewati'] = 'Tanggal tujuan berada di luar masa penempatan.';
                $jumlahDilewati++;
                $item[] = $baris;

                continue;
            }

            $jadwalAda = $jadwalTujuan->get($tanggalTujuan);
            if ($jadwalAda !== null) {
                $baris['konflik'] = true;
                $baris['jadwal_tujuan'] = $this->snapshot($jadwalAda);
                $jumlahKonflik++;
            }

            if ($jadwal->id_template_shift !== null) {
                $templateIds[] = (int) $jadwal->id_template_shift;
            }

            $item[] = $baris;
        }

        $templateShift = DB::table('tbl_template_shift')
            ->where('id_klien', $penempatan->id_klien)
            ->where(function ($query) use ($templateIds): void {
                $query->where('status', 'Aktif');

                if ($templateIds !== []) {
                    $query->orWhereIn('id', array_values(array_unique($templateIds)));
                }
            })
            ->orderBy('nama_shift')
            ->get(['id', 'nama_shift', 'jam_masuk', 'jam_pulang', 'durasi_menit', 'status']);
        $tanggalTujuanSumber = collect($item)
            ->filter(static fn (array $baris): bool => ! $baris['dilewati'])
            ->pluck('tanggal_kerja')
            ->all();
        $jadwalTujuanTambahan = $jadwalTujuan
            ->reject(static fn (object $jadwal, string $tanggal): bool => in_array($tanggal, $tanggalTujuanSumber, true))
            ->values();

        return [
            'bulan_sumber' => $sumber->format('Y-m'),
            'bulan_tujuan' => $target->format('Y-m'),
            'penempatan' => $penempatan,
            'template_shift' => $templateShift,
            'item' => $item,
            'jumlah_konflik' => $jumlahKonflik,
            'jumlah_dilewati' => $jumlahDilewati,
            'jadwal_tujuan_tambahan' => $jadwalTujuanTambahan,
            'jumlah_jadwal_tujuan' => $jadwalTujuan->count(),
        ];
    }

    /**
     * Menyimpan salinan jadwal. Tanggal yang berkonflik dilewati atau diganti sesuai
     * pilihan Operasional; jadwal tujuan lain dan data absensi tidak disentuh.
     *
     * @param  list<array<string, mixed>>  $item
     * @return array{disalin: int, diganti: int, dilewati: int}
     */
    public function simpanSalinanBulanan(
        int $idPenempatan,
        string $bulanTujuan,
        ?string $bulanSumber,
        array $item,
        ?string $modeKonflik,
        int $idUser,
    ): array {
        return DB::transaction(function () use ($idPenempatan, $bulanTujuan, $bulanSumber, $item, $modeKonflik, $idUser): array {
            $penempatan = $this->ambilPenempatan($idPenempatan, true);
            $pratinjau = $this->pratinjauSalinBulanan($idPenempatan, $bulanTujuan, $bulanSumber);
            $dapatDisalin = array_values(array_filter(
                $pratinjau['item'],
                static fn (array $baris): bool => ! $baris['dilewati'],
            ));
            $itemTerkirim = collect($item)->keyBy('tanggal_sumber');
            $tanggalSumberDiharapkan = collect($dapatDisalin)
                ->pluck('tanggal_sumber')
                ->sort()
                ->values()
                ->all();
            $tanggalSumberDikirim = $itemTerkirim->keys()->sort()->values()->all();

            if ($tanggalSumberDiharapkan !== $tanggalSumberDikirim) {
                throw ValidationException::withMessages([
                    'item' => 'Pratinjau jadwal berubah. Muat ulang pratinjau sebelum menyimpan.',
                ]);
            }

            $jadwalTujuan = DB::table('tbl_jadwal_kerja')
                ->where('id_penempatan', $idPenempatan)
                ->whereIn('tanggal_kerja', array_column($dapatDisalin, 'tanggal_kerja'))
                ->lockForUpdate()
                ->get()
                ->keyBy('tanggal_kerja');

            if ($jadwalTujuan->isNotEmpty() && ! in_array($modeKonflik, [self::KONFLIK_LEWATI, self::KONFLIK_GANTI], true)) {
                throw ValidationException::withMessages([
                    'mode_konflik' => 'Pilih apakah tanggal yang berkonflik dilewati atau diganti.',
                ]);
            }

            $hasil = ['disalin' => 0, 'diganti' => 0, 'dilewati' => 0];

            foreach ($dapatDisalin as $sumber) {
                $jadwalLama = $jadwalTujuan->get($sumber['tanggal_kerja']);

                if ($jadwalLama !== null && $modeKonflik !== self::KONFLIK_GANTI) {
                    $hasil['dilewati']++;

                    continue;
                }

                $nilai = $this->nilaiJadwalSalinan($itemTerkirim->get($sumber['tanggal_sumber']), $sumber, $penempatan);
                $this->simpanNilaiJadwal($idPenempatan, $sumber['tanggal_kerja'], $jadwalLama, $nilai, 'Disalin', $idUser);
                $hasil[$jadwalLama === null ? 'disalin' : 'diganti']++;
            }

            return $hasil;
        });
    }

    /**
     * Insert atau update satu baris jadwal beserta riwayatnya.
     * $sumberRiwayat hanya ditambahkan ke JSON riwayat (mis. template asal), bukan ke kolom jadwal.
     *
     * @param  array<string, mixed>  $nilaiJadwal
     * @param  array<string, mixed>  $sumberRiwayat
     */
    public function simpanNilaiJadwal(
        int $idPenempatan,
        string $tanggal,
        ?object $jadwalLama,
        array $nilaiJadwal,
        string $aksiRiwayat,
        int $idUser,
        array $sumberRiwayat = [],
    ): int {
        $dataRiwayat = $sumberRiwayat === [] ? $nilaiJadwal : [...$nilaiJadwal, 'sumber' => $sumberRiwayat];

        if ($jadwalLama === null) {
            $idJadwal = (int) DB::table('tbl_jadwal_kerja')->insertGetId([
                ...$nilaiJadwal,
                'id_penempatan' => $idPenempatan,
                'tanggal_kerja' => $tanggal,
                'dibuat_oleh' => $idUser,
                'diubah_oleh' => $idUser,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->catatRiwayat($idJadwal, $aksiRiwayat, null, $dataRiwayat, $idUser);

            return $idJadwal;
        }

        DB::table('tbl_jadwal_kerja')
            ->where('id', $jadwalLama->id)
            ->update([
                ...$nilaiJadwal,
                'diubah_oleh' => $idUser,
                'updated_at' => now(),
            ]);
        $this->catatRiwayat((int) $jadwalLama->id, $aksiRiwayat, $this->snapshot($jadwalLama), $dataRiwayat, $idUser);

        return (int) $jadwalLama->id;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function nilaiJadwal(array $data, object $penempatan, ?object $jadwalLama = null): array
    {
        if ($data['status'] === 'Libur') {
            return $this->nilaiLibur();
        }

        return $this->nilaiKerja(
            $this->idTemplateAtauNull($data['id_template_shift'] ?? null),
            $data['jam_masuk'] ?? null,
            $data['jam_pulang'] ?? null,
            $penempatan,
            $jadwalLama?->id_template_shift === null ? null : (int) $jadwalLama->id_template_shift,
        );
    }

    /**
     * Memindahkan satu jadwal ke tanggal baru sekaligus mengganti isi shift-nya, tanpa mengubah
     * id_penempatan. Dipakai pertukaran shift; pemanggil wajib memastikan tanggal baru belum
     * terisi jadwal lain pada penempatan yang sama (unique id_penempatan + tanggal_kerja).
     *
     * @param  array<string, mixed>  $nilaiJadwal
     * @param  array<string, mixed>  $sumberRiwayat
     */
    public function tukarTanggalDanNilai(
        object $jadwalLama,
        string $tanggalBaru,
        array $nilaiJadwal,
        int $idUser,
        array $sumberRiwayat = [],
    ): void {
        $sebelum = [
            'id_penempatan' => (int) $jadwalLama->id_penempatan,
            'tanggal_kerja' => (string) $jadwalLama->tanggal_kerja,
            ...$this->snapshot($jadwalLama),
        ];

        $sesudah = [
            'id_penempatan' => (int) $jadwalLama->id_penempatan,
            'tanggal_kerja' => $tanggalBaru,
            ...$nilaiJadwal,
        ];

        if ($sumberRiwayat !== []) {
            $sesudah['sumber'] = $sumberRiwayat;
        }

        DB::table('tbl_jadwal_kerja')
            ->where('id', $jadwalLama->id)
            ->update([
                ...$nilaiJadwal,
                'tanggal_kerja' => $tanggalBaru,
                'diubah_oleh' => $idUser,
                'updated_at' => now(),
            ]);

        $this->catatRiwayat(
            (int) $jadwalLama->id,
            'Diubah',
            $sebelum,
            $sesudah,
            $idUser,
        );
    }

    /**
     * @return array<string, null|string>
     */
    private function nilaiLibur(): array
    {
        return [
            'status' => 'Libur',
            'id_template_shift' => null,
            'nama_shift_snapshot' => null,
            'jam_masuk' => null,
            'jam_pulang' => null,
            'durasi_menit' => null,
        ];
    }

    /**
     * Template hanya mengisi nilai awal jam; jam yang dikirim manual selalu diutamakan.
     * Template nonaktif hanya diterima bila sudah tercatat pada jadwal yang sama sebelumnya.
     *
     * @return array<string, mixed>
     */
    private function nilaiKerja(
        ?int $idTemplateShift,
        ?string $jamMasuk,
        ?string $jamPulang,
        object $penempatan,
        ?int $idTemplateTerizinkan = null,
        ?string $kunciError = null,
    ): array {
        $template = null;

        if ($idTemplateShift !== null) {
            $template = DB::table('tbl_template_shift')
                ->where('id', $idTemplateShift)
                ->where('id_klien', $penempatan->id_klien)
                ->first();

            if ($template === null) {
                throw ValidationException::withMessages([
                    $kunciError ?? 'id_template_shift' => 'Jam tersimpan tidak sesuai dengan klien pada penempatan.',
                ]);
            }

            if ($template->status !== 'Aktif' && $idTemplateShift !== $idTemplateTerizinkan) {
                throw ValidationException::withMessages([
                    $kunciError ?? 'id_template_shift' => 'Jam tersimpan yang dipilih sudah nonaktif.',
                ]);
            }
        }

        $jamMasuk = $jamMasuk ?? $this->formatJam($template?->jam_masuk);
        $jamPulang = $jamPulang ?? $this->formatJam($template?->jam_pulang);

        if ($jamMasuk === null || $jamPulang === null) {
            throw ValidationException::withMessages([
                $kunciError ?? 'jam_masuk' => 'Pilih jam tersimpan atau isi jam masuk dan jam pulang terlebih dahulu.',
            ]);
        }

        return [
            'status' => 'Kerja',
            'id_template_shift' => $template?->id === null ? null : (int) $template->id,
            'nama_shift_snapshot' => $template?->nama_shift,
            'jam_masuk' => $this->formatJamDatabase($jamMasuk),
            'jam_pulang' => $this->formatJamDatabase($jamPulang),
            'durasi_menit' => $this->hitungDurasiMenit($jamMasuk, $jamPulang),
        ];
    }

    /**
     * @param  array<string, mixed>  $barisTerkirim
     * @param  array<string, mixed>  $sumber
     * @return array<string, mixed>
     */
    private function nilaiJadwalSalinan(array $barisTerkirim, array $sumber, object $penempatan): array
    {
        if ($barisTerkirim['status'] === 'Libur') {
            return $this->nilaiLibur();
        }

        $idTemplateSumber = $sumber['id_template_shift'] === null ? null : (int) $sumber['id_template_shift'];
        $idTemplateShift = $this->idTemplateAtauNull($barisTerkirim['id_template_shift'] ?? null);
        $nilai = $this->nilaiKerja(
            $idTemplateShift,
            $barisTerkirim['jam_masuk'] ?? null,
            $barisTerkirim['jam_pulang'] ?? null,
            $penempatan,
            $idTemplateSumber,
            'item',
        );

        if ($idTemplateShift !== null && $idTemplateShift === $idTemplateSumber && $sumber['nama_shift_snapshot'] !== null) {
            $nilai['nama_shift_snapshot'] = $sumber['nama_shift_snapshot'];
        }

        return $nilai;
    }

    private function idTemplateAtauNull(mixed $idTemplateShift): ?int
    {
        return $idTemplateShift === null || $idTemplateShift === '' ? null : (int) $idTemplateShift;
    }

    private function ambilPenempatan(int $idPenempatan, bool $kunci = false): object
    {
        $query = DB::table('tbl_penempatan as p')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->select('p.*', 'k.nip', 'k.nama as nama_karyawan', 'kl.nama_perusahaan')
            ->where('p.id', $idPenempatan);
        if ($kunci) {
            $query->lockForUpdate();
        }

        $penempatan = $query->first();

        if ($penempatan === null) {
            throw ValidationException::withMessages([
                'id_penempatan' => 'Penempatan tidak ditemukan.',
            ]);
        }

        return $penempatan;
    }

    private function pastikanTanggalDalamPenempatan(string $tanggal, object $penempatan): void
    {
        if (! $this->tanggalBerlaku($tanggal, $penempatan)) {
            throw ValidationException::withMessages([
                'tanggal_kerja' => 'Tanggal jadwal harus berada dalam masa penempatan.',
            ]);
        }
    }

    /**
     * Jadwal baru hanya boleh dibuat untuk penempatan yang masih Aktif
     * (bukan penempatan lama hasil Rotasi atau yang sudah Selesai).
     */
    private function pastikanPenempatanAktif(object $penempatan): void
    {
        if ($penempatan->status !== 'Aktif') {
            throw ValidationException::withMessages([
                'id_penempatan' => 'Jadwal baru hanya dapat dibuat untuk penempatan berstatus Aktif.',
            ]);
        }
    }

    public function tanggalBerlaku(string $tanggal, object $penempatan): bool
    {
        return $tanggal >= $penempatan->tgl_mulai
            && ($penempatan->tgl_selesai === null || $tanggal <= $penempatan->tgl_selesai);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(?object $jadwal): ?array
    {
        if ($jadwal === null) {
            return null;
        }

        return [
            'status' => $jadwal->status,
            'id_template_shift' => $jadwal->id_template_shift,
            'nama_shift_snapshot' => $jadwal->nama_shift_snapshot,
            'jam_masuk' => $this->formatJam($jadwal->jam_masuk),
            'jam_pulang' => $this->formatJam($jadwal->jam_pulang),
            'durasi_menit' => $jadwal->durasi_menit,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $sebelum
     * @param  array<string, mixed>  $sesudah
     */
    private function catatRiwayat(
        int $idJadwal,
        string $aksi,
        ?array $sebelum,
        array $sesudah,
        int $idUser,
    ): void {
        DB::table('tbl_jadwal_kerja_riwayat')->insert([
            'id_jadwal_kerja' => $idJadwal,
            'aksi' => $aksi,
            'data_sebelum' => $sebelum === null ? null : json_encode($sebelum, JSON_THROW_ON_ERROR),
            'data_sesudah' => json_encode($sesudah, JSON_THROW_ON_ERROR),
            'diubah_oleh' => $idUser,
            'created_at' => now(),
        ]);
    }

    private function menitDariJam(string $jam): int
    {
        return ((int) substr($jam, 0, 2) * 60) + (int) substr($jam, 3, 2);
    }

    private function formatJam(?string $jam): ?string
    {
        return $jam === null ? null : substr($jam, 0, 5);
    }

    private function formatJamDatabase(string $jam): string
    {
        return strlen($jam) === 5 ? $jam.':00' : $jam;
    }
}

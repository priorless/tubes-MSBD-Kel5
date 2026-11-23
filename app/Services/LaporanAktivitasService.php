<?php

namespace App\Services;

use App\Support\AbsensiDitolak;
use App\Support\JamServer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Dokumentasi selama sesi absensi, sesuai jenis layanan karyawan:
 *  - Berkala (Satpam, Cleaning Service): satu laporan foto per periode 1 jam dari jam mulai shift.
 *    Periode ditentukan SERVER dari waktu database, bukan dipilih pengguna.
 *  - Perjalanan (Driver): laporan per tahapan perjalanan, tanpa kewajiban per jam,
 *    ditambah P2H kendaraan saat check-in dan kondisi akhir kendaraan saat check-out.
 *
 * Setiap laporan maksimal 2 unggahan (utama + koreksi) dengan penghitung sendiri (jumlah_upload).
 * Foto check-in/check-out dan foto kendaraan P2H disimpan terpisah dan TIDAK memakai penghitung ini.
 * Semua laporan terikat pada sesi absensi aktif milik karyawan yang login.
 */
class LaporanAktivitasService
{
    public const string JENIS_BERKALA = 'Berkala';

    public const string JENIS_PERJALANAN = 'Perjalanan';

    /** @var array<string, string> nilai tersimpan => label tampilan */
    public const array TAHAPAN_PERJALANAN = [
        'Mulai Perjalanan' => 'Memulai perjalanan',
        'Tiba di Lokasi' => 'Tiba di lokasi tujuan',
        'Tugas di Lokasi' => 'Melakukan tugas di lokasi',
        'Pindah Lokasi' => 'Berpindah ke lokasi berikutnya',
        'Selesai Perjalanan' => 'Menyelesaikan tugas perjalanan',
    ];

    public const string TAHAPAN_SELESAI = 'Selesai Perjalanan';

    /** @var list<string> */
    public const array KONDISI = ['Baik', 'Perlu Perhatian', 'Rusak'];

    /** @var array<string, string> 9 komponen P2H: kunci => label */
    public const array KOMPONEN_P2H = [
        'oli_mesin' => 'Kondisi dan volume oli mesin',
        'air_radiator' => 'Air radiator/coolant',
        'minyak_rem_kopling' => 'Minyak rem dan kopling',
        'aki' => 'Kondisi aki',
        'lampu_klakson' => 'Lampu dan klakson',
        'wiper_washer' => 'Wiper dan air washer',
        'ban' => 'Tekanan dan kondisi fisik ban',
        'dashboard_sabuk' => 'Indikator dashboard dan sabuk pengaman',
        'bahan_bakar' => 'Kondisi bahan bakar',
    ];

    public function __construct(
        private FotoAbsensiService $foto,
        private JadwalAbsensiService $jadwal,
        private TenagaKerjaService $tenagaKerja,
        private JamServer $jam,
    ) {}

    public function tersedia(): bool
    {
        return Schema::hasTable('tbl_laporan_aktivitas') && Schema::hasTable('tbl_p2h');
    }

    /** 'Berkala' | 'Perjalanan' | null (jenis layanan tanpa laporan aktivitas). */
    public static function jenisMonitoring(?string $namaLayanan): ?string
    {
        $cocok = static fn (string $kunci): bool => in_array(
            mb_strtolower(trim((string) $namaLayanan)),
            array_map('mb_strtolower', (array) config("tenaga_kerja.absensi.$kunci", [])),
            true,
        );

        return match (true) {
            blank($namaLayanan) => null,
            $cocok('layanan_berkala') => self::JENIS_BERKALA,
            $cocok('layanan_perjalanan') => self::JENIS_PERJALANAN,
            default => null,
        };
    }

    public static function maksUpload(): int
    {
        return max(1, (int) config('tenaga_kerja.absensi.maks_upload', 2));
    }

    /* =====================================================================
     * Laporan aktivitas
     * ===================================================================== */

    /**
     * Laporan baru pada sesi aktif. Berkala: periode yang sedang dibuka; Perjalanan: tahapan yang dipilih.
     *
     * @throws AbsensiDitolak
     */
    public function buatLaporan(int $idKaryawan, ?string $namaLayanan, string $isiFoto, string $keterangan, ?string $tahapan): int
    {
        $jenis = self::jenisMonitoring($namaLayanan)
            ?? throw new AbsensiDitolak('Jenis layanan Anda tidak memerlukan laporan aktivitas.');

        if ($jenis === self::JENIS_PERJALANAN && ! array_key_exists((string) $tahapan, self::TAHAPAN_PERJALANAN)) {
            throw new AbsensiDitolak('Pilih tahapan perjalanan yang valid.');
        }

        $jpeg = $this->foto->siapkan($isiFoto);
        $path = null;

        try {
            return DB::transaction(function () use ($idKaryawan, $jenis, $keterangan, $tahapan, $jpeg, &$path): int {
                $absensi = $this->absensiAktifTerkunci($idKaryawan);
                $sekarang = $this->jam->sekarang();
                $data = [
                    'id_absensi' => $absensi->id,
                    'id_karyawan' => $idKaryawan,
                    'jenis' => $jenis,
                    'keterangan' => $keterangan,
                    'jumlah_upload' => 1,
                    'waktu_lapor' => $sekarang->format('Y-m-d H:i:s'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($jenis === self::JENIS_BERKALA) {
                    $periode = $this->periodeDibukaUntuk($absensi, $sekarang);

                    if (DB::table('tbl_laporan_aktivitas')->where('id_absensi', $absensi->id)->where('periode_ke', $periode['ke'])->exists()) {
                        throw new AbsensiDitolak("Laporan {$periode['ke']} sudah dikirim. Gunakan tombol Koreksi bila foto perlu diganti.");
                    }

                    $data += [
                        'periode_ke' => $periode['ke'],
                        'periode_mulai' => $periode['mulai']->format('Y-m-d H:i:s'),
                        'periode_selesai' => $periode['selesai']->format('Y-m-d H:i:s'),
                    ];
                } else {
                    $data['tahapan'] = $tahapan;
                }

                $path = $this->foto->pathBaru('laporan', $sekarang->toDateString());
                $data['foto_utama'] = $path;

                try {
                    $id = (int) DB::table('tbl_laporan_aktivitas')->insertGetId($data);
                } catch (QueryException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                        throw new AbsensiDitolak('Laporan periode ini sudah dikirim. Gunakan tombol Koreksi bila foto perlu diganti.');
                    }
                    throw $e;
                }

                $this->foto->simpan($path, $jpeg);

                return $id;
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                $this->foto->hapus($path);
            }
            throw $e;
        }
    }

    /**
     * Unggahan kedua (koreksi) untuk laporan yang sama. Setelah ini foto tidak dapat diganti lagi.
     *
     * @throws AbsensiDitolak
     */
    public function koreksiLaporan(int $idKaryawan, int $idLaporan, string $isiFoto, ?string $keterangan): void
    {
        $jpeg = $this->foto->siapkan($isiFoto);
        $path = null;

        try {
            DB::transaction(function () use ($idKaryawan, $idLaporan, $keterangan, $jpeg, &$path): void {
                $laporan = DB::table('tbl_laporan_aktivitas')
                    ->where('id', $idLaporan)
                    ->where('id_karyawan', $idKaryawan)
                    ->lockForUpdate()
                    ->first();

                if ($laporan === null) {
                    throw new AbsensiDitolak('Laporan tidak ditemukan.');
                }

                $absensi = $this->absensiAktifTerkunci($idKaryawan);
                if ((int) $absensi->id !== (int) $laporan->id_absensi) {
                    throw new AbsensiDitolak('Sesi absensi laporan ini sudah berakhir. Laporan tidak dapat dikoreksi.');
                }

                if ((int) $laporan->jumlah_upload >= self::maksUpload()) {
                    throw new AbsensiDitolak(sprintf('Batas unggahan laporan ini sudah habis (maksimal %d kali). Foto tidak dapat diganti lagi.', self::maksUpload()));
                }

                $sekarang = $this->jam->sekarang();
                if ($laporan->jenis === self::JENIS_BERKALA && $sekarang->gte(CarbonImmutable::parse((string) $laporan->periode_selesai))) {
                    throw new AbsensiDitolak("Periode laporan {$laporan->periode_ke} sudah ditutup. Laporan tidak dapat dikoreksi.");
                }

                $path = $this->foto->pathBaru('laporan', $sekarang->toDateString());
                $diperbarui = DB::table('tbl_laporan_aktivitas')
                    ->where('id', $laporan->id)
                    ->where('jumlah_upload', (int) $laporan->jumlah_upload)
                    ->update(array_filter([
                        'foto_koreksi' => $path,
                        'jumlah_upload' => (int) $laporan->jumlah_upload + 1,
                        'waktu_koreksi' => $sekarang->format('Y-m-d H:i:s'),
                        'keterangan' => filled($keterangan) ? $keterangan : null,
                        'updated_at' => now(),
                    ], fn ($nilai) => $nilai !== null));

                if ($diperbarui !== 1) {
                    $path = null;
                    throw new AbsensiDitolak('Batas unggahan laporan ini sudah habis. Foto tidak dapat diganti lagi.');
                }

                $this->foto->simpan($path, $jpeg);
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                $this->foto->hapus($path);
            }
            throw $e;
        }
    }

    /**
     * Laporan pada satu sesi absensi, terlama dahulu.
     *
     * @return Collection<int, object>
     */
    public function laporanAbsensi(int $idKaryawan, int $idAbsensi): Collection
    {
        if (! $this->tersedia()) {
            return collect();
        }

        return DB::table('tbl_laporan_aktivitas')
            ->where('id_karyawan', $idKaryawan)
            ->where('id_absensi', $idAbsensi)
            ->orderBy('waktu_lapor')
            ->orderBy('id')
            ->get();
    }

    /**
     * Periode monitoring berkala sebuah sesi beserta laporannya (untuk tampilan).
     *
     * @return list<array{ke: int, mulai: CarbonImmutable, selesai: CarbonImmutable, status: string, laporan: object|null}>
     */
    public function periodeDenganLaporan(object $absensi): array
    {
        $shift = $this->jadwal->shiftAbsensi($absensi);
        if ($shift === null) {
            return [];
        }

        $laporan = $this->laporanAbsensi((int) $absensi->id_karyawan, (int) $absensi->id)->keyBy('periode_ke');

        return array_map(
            fn (array $periode): array => $periode + ['laporan' => $laporan->get($periode['ke'])],
            $this->jadwal->periodeMonitoring($shift, $this->jadwal->waktuKeluar($absensi), $this->jam->sekarang()),
        );
    }

    /* =====================================================================
     * P2H kendaraan (Driver)
     * ===================================================================== */

    /**
     * Simpan P2H check-in. Dipanggil di dalam transaksi check-in; mengembalikan path foto yang ditulis.
     *
     * @param  array<string, array{kondisi: string, keterangan?: string|null}>  $item  9 komponen KOMPONEN_P2H
     */
    public function simpanP2hAwal(int $idAbsensi, int $idKaryawan, array $item, string $jpegKendaraan, object $waktu): string
    {
        if (array_diff(array_keys(self::KOMPONEN_P2H), array_keys($item)) !== []) {
            throw new AbsensiDitolak('Seluruh 9 komponen P2H wajib diperiksa sebelum check-in.');
        }

        $path = $this->foto->pathBaru('kendaraan-awal', $waktu->tgl);
        $idP2h = (int) DB::table('tbl_p2h')->insertGetId([
            'id_absensi' => $idAbsensi,
            'id_karyawan' => $idKaryawan,
            'foto_kendaraan_awal' => $path,
            'waktu_pemeriksaan' => $waktu->sekarang,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tbl_p2h_item')->insert(array_map(
            fn (string $komponen): array => [
                'id_p2h' => $idP2h,
                'komponen' => $komponen,
                'kondisi' => $item[$komponen]['kondisi'],
                'keterangan' => filled($item[$komponen]['keterangan'] ?? null) ? trim((string) $item[$komponen]['keterangan']) : null,
            ],
            array_keys(self::KOMPONEN_P2H),
        ));

        $this->foto->simpan($path, $jpegKendaraan);

        return $path;
    }

    /**
     * Simpan kondisi akhir kendaraan saat check-out. Dipanggil di dalam transaksi check-out.
     */
    public function simpanP2hAkhir(int $idAbsensi, int $idKaryawan, string $kondisi, ?string $catatan, string $jpegKendaraan, object $waktu): string
    {
        $path = $this->foto->pathBaru('kendaraan-akhir', $waktu->tgl);
        $nilai = [
            'kondisi_akhir' => $kondisi,
            'catatan_akhir' => filled($catatan) ? $catatan : null,
            'foto_kendaraan_akhir' => $path,
            'waktu_selesai' => $waktu->sekarang,
            'updated_at' => now(),
        ];

        $diperbarui = DB::table('tbl_p2h')->where('id_absensi', $idAbsensi)->whereNull('waktu_selesai')->update($nilai);

        if ($diperbarui === 0) {
            if (DB::table('tbl_p2h')->where('id_absensi', $idAbsensi)->exists()) {
                throw new AbsensiDitolak('Kondisi akhir kendaraan untuk sesi ini sudah tercatat.');
            }
            // Sesi yang check-in sebelum fitur P2H dipasang: catat kondisi akhir saja.
            DB::table('tbl_p2h')->insert($nilai + ['id_absensi' => $idAbsensi, 'id_karyawan' => $idKaryawan, 'created_at' => now()]);
        }

        $this->foto->simpan($path, $jpegKendaraan);

        return $path;
    }

    /**
     * Driver wajib menuntaskan laporan perjalanan sebelum check-out: bila sudah ada laporan,
     * laporan terakhir harus tahapan "Menyelesaikan tugas perjalanan".
     *
     * @throws AbsensiDitolak
     */
    public function pastikanPerjalananSelesai(int $idKaryawan, int $idAbsensi): void
    {
        $terakhir = $this->laporanAbsensi($idKaryawan, $idAbsensi)->last();

        if ($terakhir !== null && $terakhir->tahapan !== self::TAHAPAN_SELESAI) {
            throw new AbsensiDitolak('Laporan perjalanan belum diselesaikan. Kirim laporan tahapan "'
                .self::TAHAPAN_PERJALANAN[self::TAHAPAN_SELESAI].'" sebelum check-out.');
        }
    }

    /**
     * P2H sebuah sesi beserta itemnya, atau null.
     *
     * @return array{p2h: object, item: Collection<string, object>}|null
     */
    public function p2hAbsensi(int $idKaryawan, int $idAbsensi): ?array
    {
        if (! $this->tersedia()) {
            return null;
        }

        $p2h = DB::table('tbl_p2h')->where('id_absensi', $idAbsensi)->where('id_karyawan', $idKaryawan)->first();

        return $p2h === null ? null : [
            'p2h' => $p2h,
            'item' => DB::table('tbl_p2h_item')->where('id_p2h', $p2h->id)->get()->keyBy('komponen'),
        ];
    }

    /* =====================================================================
     * Sesi & periode
     * ===================================================================== */

    /**
     * Baris absensi aktif (sudah check-in berfoto, belum check-out) milik karyawan, dikunci FOR UPDATE.
     *
     * @throws AbsensiDitolak
     */
    private function absensiAktifTerkunci(int $idKaryawan): object
    {
        $aktif = $this->tenagaKerja->absensiHariIni($idKaryawan);
        $absensi = $aktif === null ? null : DB::table('tbl_absensi')
            ->where('id', $aktif->id)
            ->where('id_karyawan', $idKaryawan)
            ->lockForUpdate()
            ->first();

        if ($absensi === null || blank($absensi->jam_masuk) || blank($absensi->foto_masuk)) {
            throw new AbsensiDitolak('Anda belum check-in. Laporan aktivitas hanya dapat dikirim selama sesi absensi berlangsung.');
        }

        if (filled($absensi->jam_keluar)) {
            throw new AbsensiDitolak('Anda sudah check-out. Monitoring aktivitas untuk sesi ini sudah berhenti.');
        }

        return $absensi;
    }

    /**
     * @return array{ke: int, mulai: CarbonImmutable, selesai: CarbonImmutable, status: string}
     *
     * @throws AbsensiDitolak
     */
    private function periodeDibukaUntuk(object $absensi, CarbonImmutable $sekarang): array
    {
        $shift = $this->jadwal->shiftAbsensi($absensi)
            ?? throw new AbsensiDitolak('Jadwal shift untuk sesi absensi ini tidak ditemukan. Hubungi bagian Operasional.');

        $periode = $this->jadwal->periodeDibuka($shift, $sekarang);
        if ($periode !== null) {
            return $periode;
        }

        $berikutnya = collect($this->jadwal->periodeMonitoring($shift, null, $sekarang))
            ->firstWhere('status', JadwalAbsensiService::PERIODE_AKAN_DATANG);

        throw new AbsensiDitolak($berikutnya !== null
            ? "Belum ada periode laporan yang dibuka. Laporan {$berikutnya['ke']} dibuka pukul {$berikutnya['mulai']->format('H:i')}."
            : 'Tidak ada periode laporan yang sedang dibuka.');
    }
}

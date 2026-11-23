<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Template Jadwal Bulanan: pola jadwal per hari ke-1..31 yang dapat diterapkan berulang kali.
 *
 * - Pribadi : milik satu karyawan, hanya dapat diterapkan kepada pemiliknya.
 * - Bersama : milik satu klien (opsional satu jenis layanan), dapat diterapkan ke beberapa karyawan
 *             yang punya penempatan Aktif di klien tersebut pada bulan tujuan.
 *
 * Penerapan MENYALIN nilai pola ke tbl_jadwal_kerja; tidak ada tautan hidup, sehingga mengedit
 * template tidak mengubah jadwal yang sudah diterapkan dan sebaliknya. Hari tanpa pola (Belum Diatur)
 * tidak menyentuh jadwal tujuan.
 */
class TemplateJadwalService
{
    public const string JENIS_PRIBADI = 'Pribadi';

    public const string JENIS_BERSAMA = 'Bersama';

    public const string KONFLIK_LEWATI = 'Lewati';

    public const string KONFLIK_TIMPA = 'Timpa';

    public const string KONDISI_BARU = 'baru';

    public const string KONDISI_KONFLIK = 'konflik';

    public const string KONDISI_DI_LUAR_PENEMPATAN = 'di_luar_penempatan';

    public function __construct(private JadwalKerjaService $jadwalKerjaService) {}

    /**
     * @param  array{nama_template: string, jenis: string, id_karyawan?: int|null, id_klien?: int|null, id_jenis_layanan?: int|null, keterangan?: string|null}  $data
     * @param  array<int, array{status: string, jam_masuk?: string|null, jam_pulang?: string|null}>  $pola  dikunci dengan hari_ke
     */
    public function buatTemplate(array $data, array $pola, int $idUser): int
    {
        return DB::transaction(function () use ($data, $pola, $idUser): int {
            $pemilik = $this->validasiPemilik($data);
            $namaTemplate = $this->validasiNama((string) ($data['nama_template'] ?? ''));
            $detail = $this->normalisasiPola($pola);

            $idTemplate = (int) DB::table('tbl_template_jadwal')->insertGetId([
                'nama_template' => $namaTemplate,
                ...$pemilik,
                'keterangan' => $this->keteranganAtauNull($data['keterangan'] ?? null),
                'status' => 'Aktif',
                'dibuat_oleh' => $idUser,
                'diubah_oleh' => $idUser,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->simpanDetail($idTemplate, $detail);

            return $idTemplate;
        });
    }

    /**
     * Jenis dan pemilik (karyawan/klien) tidak dapat diubah setelah template dibuat.
     * Pola lama diganti seluruhnya; jadwal yang sudah pernah diterapkan tidak disentuh.
     *
     * @param  array{nama_template: string, keterangan?: string|null, id_jenis_layanan?: int|null, jenis?: string, id_karyawan?: int|null, id_klien?: int|null}  $data
     * @param  array<int, array{status: string, jam_masuk?: string|null, jam_pulang?: string|null}>  $pola
     */
    public function ubahTemplate(int $idTemplate, array $data, array $pola, int $idUser): void
    {
        DB::transaction(function () use ($idTemplate, $data, $pola, $idUser): void {
            $template = $this->ambilTemplate($idTemplate, true);
            $this->pastikanPemilikTidakBerubah($template, $data);
            $namaTemplate = $this->validasiNama((string) ($data['nama_template'] ?? ''), $idTemplate);
            $idJenisLayanan = $this->idAtauNull($data['id_jenis_layanan'] ?? null);

            if ($template->jenis === self::JENIS_PRIBADI && $idJenisLayanan !== null) {
                throw ValidationException::withMessages([
                    'id_jenis_layanan' => 'Jenis layanan hanya berlaku untuk Pola Bersama.',
                ]);
            }

            if ($idJenisLayanan !== null) {
                $this->pastikanAda('tbl_jenis_layanan', $idJenisLayanan, 'id_jenis_layanan', 'Jenis layanan tidak ditemukan.');
            }

            $detail = $this->normalisasiPola($pola);

            DB::table('tbl_template_jadwal')->where('id', $idTemplate)->update([
                'nama_template' => $namaTemplate,
                'id_jenis_layanan' => $idJenisLayanan,
                'keterangan' => $this->keteranganAtauNull($data['keterangan'] ?? null),
                'diubah_oleh' => $idUser,
                'updated_at' => now(),
            ]);
            DB::table('tbl_template_jadwal_detail')->where('id_template_jadwal', $idTemplate)->delete();
            $this->simpanDetail($idTemplate, $detail);
        });
    }

    /**
     * Mengganti pola saja (mis. dari "Simpan Jadwal sebagai Template" → perbarui template yang ada);
     * nama, keterangan, jenis, dan pemilik tetap.
     *
     * @param  array<int, array{status: string, jam_masuk?: string|null, jam_pulang?: string|null}>  $pola
     */
    public function gantiPolaTemplate(int $idTemplate, array $pola, int $idUser): void
    {
        $template = $this->ambilTemplate($idTemplate);

        $this->ubahTemplate($idTemplate, [
            'nama_template' => $template->nama_template,
            'keterangan' => $template->keterangan,
            'id_jenis_layanan' => $template->id_jenis_layanan,
        ], $pola, $idUser);
    }

    public function nonaktifkanTemplate(int $idTemplate, int $idUser): void
    {
        $this->ambilTemplate($idTemplate);

        DB::table('tbl_template_jadwal')->where('id', $idTemplate)->update([
            'status' => 'Nonaktif',
            'diubah_oleh' => $idUser,
            'updated_at' => now(),
        ]);
    }

    /**
     * Mengambil pola dari jadwal aktual satu karyawan pada satu bulan (untuk "Simpan Jadwal sebagai Template").
     *
     * @return array<int, array{status: string, jam_masuk: string|null, jam_pulang: string|null}>
     */
    public function polaDariJadwal(int $idKaryawan, string $bulan): array
    {
        $awalBulan = $this->awalBulan($bulan);
        $jadwal = DB::table('tbl_jadwal_kerja as j')
            ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
            ->where('p.id_karyawan', $idKaryawan)
            ->whereBetween('j.tanggal_kerja', [$awalBulan->toDateString(), $awalBulan->endOfMonth()->toDateString()])
            ->orderBy('j.tanggal_kerja')
            ->get(['j.tanggal_kerja', 'j.status', 'j.jam_masuk', 'j.jam_pulang']);

        if ($jadwal->isEmpty()) {
            throw ValidationException::withMessages([
                'bulan' => 'Karyawan belum memiliki jadwal pada bulan tersebut untuk dijadikan pola.',
            ]);
        }

        return $jadwal->mapWithKeys(fn (object $baris): array => [
            (int) substr((string) $baris->tanggal_kerja, 8, 2) => [
                'status' => $baris->status,
                'jam_masuk' => $this->formatJam($baris->jam_masuk),
                'jam_pulang' => $this->formatJam($baris->jam_pulang),
            ],
        ])->all();
    }

    /**
     * @param  list<int>  $daftarIdKaryawan  kosong pada Template Pribadi = pemilik template
     * @return array{
     *     template: object,
     *     bulan: string,
     *     hari_tidak_tersedia: list<int>,
     *     karyawan: list<array{
     *         id_karyawan: int,
     *         nip: string|null,
     *         nama: string|null,
     *         layak: bool,
     *         alasan: string|null,
     *         id_penempatan: int|null,
     *         tanggal: list<array<string, mixed>>,
     *         jumlah_baru: int,
     *         jumlah_konflik: int,
     *         jumlah_di_luar_penempatan: int
     *     }>
     * }
     */
    public function pratinjauPenerapan(int $idTemplate, string $bulan, array $daftarIdKaryawan): array
    {
        $template = $this->ambilTemplate($idTemplate);

        return $this->susunPratinjau($template, $this->awalBulan($bulan), $daftarIdKaryawan, false);
    }

    /**
     * Menerapkan template secara atomik. Bila ada karyawan yang tidak layak, tidak ada jadwal yang disimpan.
     *
     * @param  list<int>  $daftarIdKaryawan
     * @return array{
     *     hari_tidak_tersedia: list<int>,
     *     karyawan: list<array{id_karyawan: int, nama: string|null, dibuat: int, ditimpa: int, dilewati_konflik: int, dilewati_di_luar_penempatan: int}>
     * }
     */
    public function terapkan(int $idTemplate, string $bulan, array $daftarIdKaryawan, string $modeKonflik, int $idUser): array
    {
        if (! in_array($modeKonflik, [self::KONFLIK_LEWATI, self::KONFLIK_TIMPA], true)) {
            throw ValidationException::withMessages([
                'mode_konflik' => 'Pilih pertahankan atau ganti untuk jadwal yang sudah ada.',
            ]);
        }

        return DB::transaction(function () use ($idTemplate, $bulan, $daftarIdKaryawan, $modeKonflik, $idUser): array {
            $template = $this->ambilTemplate($idTemplate, true);

            if ($template->status !== 'Aktif') {
                throw ValidationException::withMessages([
                    'id_template_jadwal' => 'Pola yang dinonaktifkan tidak dapat dipakai.',
                ]);
            }

            $awalBulan = $this->awalBulan($bulan);
            $pratinjau = $this->susunPratinjau($template, $awalBulan, $daftarIdKaryawan, true);
            $tidakLayak = array_filter($pratinjau['karyawan'], static fn (array $baris): bool => ! $baris['layak']);

            if ($tidakLayak !== []) {
                throw ValidationException::withMessages([
                    'id_karyawan' => array_map(
                        static fn (array $baris): string => ($baris['nama'] ?? 'Karyawan #'.$baris['id_karyawan']).': '.$baris['alasan'],
                        array_values($tidakLayak),
                    ),
                ]);
            }

            $sumber = ['template_jadwal' => (int) $template->id, 'nama_template' => $template->nama_template];
            $hasil = [];

            foreach ($pratinjau['karyawan'] as $karyawan) {
                $jumlah = ['dibuat' => 0, 'ditimpa' => 0, 'dilewati_konflik' => 0, 'dilewati_di_luar_penempatan' => 0];

                foreach ($karyawan['tanggal'] as $baris) {
                    if ($baris['kondisi'] === self::KONDISI_DI_LUAR_PENEMPATAN) {
                        $jumlah['dilewati_di_luar_penempatan']++;

                        continue;
                    }

                    if ($baris['kondisi'] === self::KONDISI_KONFLIK && $modeKonflik === self::KONFLIK_LEWATI) {
                        $jumlah['dilewati_konflik']++;

                        continue;
                    }

                    $this->jadwalKerjaService->simpanNilaiJadwal(
                        $baris['id_penempatan'],
                        $baris['tanggal'],
                        $baris['jadwal_lama'],
                        $this->nilaiJadwal($baris['pola']),
                        $baris['jadwal_lama'] === null ? 'Dibuat' : 'Diubah',
                        $idUser,
                        [...$sumber, 'hari_ke' => $baris['hari_ke']],
                    );
                    $jumlah[$baris['jadwal_lama'] === null ? 'dibuat' : 'ditimpa']++;
                }

                DB::table('tbl_template_jadwal_penerapan')->insert([
                    'id_template_jadwal' => $template->id,
                    'id_karyawan' => $karyawan['id_karyawan'],
                    'id_penempatan' => $karyawan['id_penempatan'],
                    'bulan_tujuan' => $awalBulan->toDateString(),
                    'mode_konflik' => $modeKonflik,
                    'jumlah_dibuat' => $jumlah['dibuat'],
                    'jumlah_ditimpa' => $jumlah['ditimpa'],
                    'jumlah_dilewati' => $jumlah['dilewati_konflik'] + $jumlah['dilewati_di_luar_penempatan']
                        + count($pratinjau['hari_tidak_tersedia']),
                    'diterapkan_oleh' => $idUser,
                    'created_at' => now(),
                ]);

                $hasil[] = ['id_karyawan' => $karyawan['id_karyawan'], 'nama' => $karyawan['nama'], ...$jumlah];
            }

            return ['hari_tidak_tersedia' => $pratinjau['hari_tidak_tersedia'], 'karyawan' => $hasil];
        });
    }

    /**
     * @param  list<int>  $daftarIdKaryawan
     * @return array<string, mixed>
     */
    private function susunPratinjau(object $template, CarbonImmutable $awalBulan, array $daftarIdKaryawan, bool $kunci): array
    {
        $daftarIdKaryawan = array_values(array_unique(array_map('intval', $daftarIdKaryawan)));

        if ($daftarIdKaryawan === [] && $template->jenis === self::JENIS_PRIBADI) {
            $daftarIdKaryawan = [(int) $template->id_karyawan];
        }

        if ($daftarIdKaryawan === []) {
            throw ValidationException::withMessages([
                'id_karyawan' => 'Pilih minimal satu karyawan penerima pola.',
            ]);
        }

        $akhirBulan = $awalBulan->endOfMonth()->startOfDay();
        $pola = DB::table('tbl_template_jadwal_detail')
            ->where('id_template_jadwal', $template->id)
            ->orderBy('hari_ke')
            ->get();
        $hariTidakTersedia = $pola
            ->filter(static fn (object $baris): bool => (int) $baris->hari_ke > $awalBulan->daysInMonth)
            ->map(static fn (object $baris): int => (int) $baris->hari_ke)
            ->values()
            ->all();
        $polaBerlaku = $pola->reject(static fn (object $baris): bool => (int) $baris->hari_ke > $awalBulan->daysInMonth);
        $karyawan = DB::table('tbl_karyawan')
            ->whereIn('id', $daftarIdKaryawan)
            ->get(['id', 'nip', 'nama', 'id_jenis_layanan'])
            ->keyBy('id');
        $queryPenempatan = DB::table('tbl_penempatan')
            ->whereIn('id_karyawan', $daftarIdKaryawan)
            ->where('status', 'Aktif')
            ->where('tgl_mulai', '<=', $akhirBulan->toDateString())
            ->where(fn ($query) => $query->whereNull('tgl_selesai')->orWhere('tgl_selesai', '>=', $awalBulan->toDateString()))
            ->when($template->jenis === self::JENIS_BERSAMA, fn ($query) => $query->where('id_klien', $template->id_klien))
            ->orderBy('tgl_mulai');

        if ($kunci) {
            $queryPenempatan->lockForUpdate();
        }

        $penempatanPerKaryawan = $queryPenempatan->get()->groupBy('id_karyawan');
        $tanggalPola = $polaBerlaku
            ->map(static fn (object $baris): string => $awalBulan->addDays((int) $baris->hari_ke - 1)->toDateString())
            ->values()
            ->all();
        $queryJadwal = DB::table('tbl_jadwal_kerja')
            ->whereIn('id_penempatan', $penempatanPerKaryawan->flatten()->pluck('id')->all())
            ->whereIn('tanggal_kerja', $tanggalPola);

        if ($kunci) {
            $queryJadwal->lockForUpdate();
        }

        $jadwalAda = $queryJadwal->get()->keyBy(static fn (object $baris): string => $baris->id_penempatan.'|'.$baris->tanggal_kerja);
        $hasilKaryawan = [];

        foreach ($daftarIdKaryawan as $idKaryawan) {
            $dataKaryawan = $karyawan->get($idKaryawan);
            $penempatan = $penempatanPerKaryawan->get($idKaryawan, collect());
            $alasan = $this->alasanTidakLayak($template, $idKaryawan, $dataKaryawan, $penempatan);
            $baris = [
                'id_karyawan' => $idKaryawan,
                'nip' => $dataKaryawan?->nip,
                'nama' => $dataKaryawan?->nama,
                'layak' => $alasan === null,
                'alasan' => $alasan,
                'id_penempatan' => $penempatan->first()?->id === null ? null : (int) $penempatan->first()->id,
                'tanggal' => [],
                'jumlah_baru' => 0,
                'jumlah_konflik' => 0,
                'jumlah_di_luar_penempatan' => 0,
            ];

            if ($alasan === null) {
                foreach ($polaBerlaku as $hari) {
                    $tanggal = $awalBulan->addDays((int) $hari->hari_ke - 1)->toDateString();
                    $penempatanHari = $penempatan->first(
                        fn (object $item): bool => $this->jadwalKerjaService->tanggalBerlaku($tanggal, $item),
                    );
                    $jadwalLama = $penempatanHari === null ? null : $jadwalAda->get($penempatanHari->id.'|'.$tanggal);
                    $kondisi = match (true) {
                        $penempatanHari === null => self::KONDISI_DI_LUAR_PENEMPATAN,
                        $jadwalLama !== null => self::KONDISI_KONFLIK,
                        default => self::KONDISI_BARU,
                    };

                    $baris['tanggal'][] = [
                        'hari_ke' => (int) $hari->hari_ke,
                        'tanggal' => $tanggal,
                        'pola' => $hari,
                        'kondisi' => $kondisi,
                        'id_penempatan' => $penempatanHari === null ? null : (int) $penempatanHari->id,
                        'jadwal_lama' => $jadwalLama,
                    ];
                    $baris[match ($kondisi) {
                        self::KONDISI_BARU => 'jumlah_baru',
                        self::KONDISI_KONFLIK => 'jumlah_konflik',
                        default => 'jumlah_di_luar_penempatan',
                    }]++;
                }
            }

            $hasilKaryawan[] = $baris;
        }

        return [
            'template' => $template,
            'bulan' => $awalBulan->format('Y-m'),
            'hari_tidak_tersedia' => $hariTidakTersedia,
            'karyawan' => $hasilKaryawan,
        ];
    }

    /**
     * @param  Collection<int, object>  $penempatan  penempatan Aktif yang beririsan dengan bulan tujuan
     */
    private function alasanTidakLayak(object $template, int $idKaryawan, ?object $karyawan, Collection $penempatan): ?string
    {
        if ($karyawan === null) {
            return 'Karyawan tidak ditemukan.';
        }

        if ($template->jenis === self::JENIS_PRIBADI && $idKaryawan !== (int) $template->id_karyawan) {
            return 'Pola Pribadi hanya dapat dipakai untuk karyawan yang bersangkutan.';
        }

        if ($template->jenis === self::JENIS_BERSAMA
            && $template->id_jenis_layanan !== null
            && (int) $karyawan->id_jenis_layanan !== (int) $template->id_jenis_layanan) {
            return 'Jenis layanan karyawan tidak sesuai dengan pola.';
        }

        if ($penempatan->isEmpty()) {
            return $template->jenis === self::JENIS_BERSAMA
                ? 'Tidak memiliki penempatan Aktif di klien pola ini pada bulan tujuan.'
                : 'Tidak memiliki penempatan Aktif pada bulan tujuan.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{jenis: string, id_karyawan: int|null, id_klien: int|null, id_jenis_layanan: int|null}
     */
    private function validasiPemilik(array $data): array
    {
        $jenis = $data['jenis'] ?? null;
        $idKaryawan = $this->idAtauNull($data['id_karyawan'] ?? null);
        $idKlien = $this->idAtauNull($data['id_klien'] ?? null);
        $idJenisLayanan = $this->idAtauNull($data['id_jenis_layanan'] ?? null);

        if ($jenis === self::JENIS_PRIBADI) {
            if ($idKaryawan === null) {
                throw ValidationException::withMessages(['id_karyawan' => 'Pola Pribadi wajib memilih karyawannya.']);
            }

            if ($idKlien !== null || $idJenisLayanan !== null) {
                throw ValidationException::withMessages(['id_klien' => 'Pola Pribadi tidak terikat pada klien atau jenis layanan.']);
            }

            $this->pastikanAda('tbl_karyawan', $idKaryawan, 'id_karyawan', 'Karyawan tidak ditemukan.');
        } elseif ($jenis === self::JENIS_BERSAMA) {
            if ($idKlien === null) {
                throw ValidationException::withMessages(['id_klien' => 'Pola Bersama wajib memilih klien.']);
            }

            if ($idKaryawan !== null) {
                throw ValidationException::withMessages(['id_karyawan' => 'Pola Bersama tidak terikat pada satu karyawan.']);
            }

            $this->pastikanAda('tbl_klien', $idKlien, 'id_klien', 'Klien tidak ditemukan.');

            if ($idJenisLayanan !== null) {
                $this->pastikanAda('tbl_jenis_layanan', $idJenisLayanan, 'id_jenis_layanan', 'Jenis layanan tidak ditemukan.');
            }
        } else {
            throw ValidationException::withMessages(['jenis' => 'Jenis pola harus Pribadi atau Bersama.']);
        }

        return ['jenis' => $jenis, 'id_karyawan' => $idKaryawan, 'id_klien' => $idKlien, 'id_jenis_layanan' => $idJenisLayanan];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pastikanPemilikTidakBerubah(object $template, array $data): void
    {
        $berubah = (array_key_exists('jenis', $data) && $data['jenis'] !== $template->jenis)
            || (array_key_exists('id_karyawan', $data) && $this->idAtauNull($data['id_karyawan']) !== $this->idAtauNull($template->id_karyawan))
            || (array_key_exists('id_klien', $data) && $this->idAtauNull($data['id_klien']) !== $this->idAtauNull($template->id_klien));

        if ($berubah) {
            throw ValidationException::withMessages([
                'jenis' => 'Jenis pola dan karyawan/klien terkait tidak dapat diubah. Buat pola baru bila diperlukan.',
            ]);
        }
    }

    private function validasiNama(string $namaTemplate, ?int $kecualiId = null): string
    {
        $namaTemplate = trim($namaTemplate);

        if ($namaTemplate === '' || mb_strlen($namaTemplate) > 100) {
            throw ValidationException::withMessages(['nama_template' => 'Nama pola wajib diisi, maksimal 100 karakter.']);
        }

        $sudahDipakai = DB::table('tbl_template_jadwal')
            ->where('nama_template', $namaTemplate)
            ->when($kecualiId !== null, fn ($query) => $query->where('id', '!=', $kecualiId))
            ->exists();

        if ($sudahDipakai) {
            throw ValidationException::withMessages(['nama_template' => 'Nama pola sudah digunakan.']);
        }

        return $namaTemplate;
    }

    /**
     * @param  array<int|string, mixed>  $pola
     * @return array<int, array{status: string, jam_masuk: string|null, jam_pulang: string|null, durasi_menit: int|null}>
     */
    private function normalisasiPola(array $pola): array
    {
        if ($pola === []) {
            throw ValidationException::withMessages(['pola' => 'Isi minimal satu tanggal pada pola.']);
        }

        $detail = [];

        foreach ($pola as $hariKe => $hari) {
            if (! is_numeric($hariKe) || (int) $hariKe < 1 || (int) $hariKe > 31) {
                throw ValidationException::withMessages(['pola' => 'Hari pada pola harus antara 1 dan 31.']);
            }

            $hariKe = (int) $hariKe;
            $status = is_array($hari) ? ($hari['status'] ?? null) : null;

            if ($status === 'Libur') {
                $detail[$hariKe] = ['status' => 'Libur', 'jam_masuk' => null, 'jam_pulang' => null, 'durasi_menit' => null];

                continue;
            }

            if ($status !== 'Kerja') {
                throw ValidationException::withMessages(['pola' => "Status hari ke-{$hariKe} harus Kerja atau Libur."]);
            }

            $jamMasuk = $hari['jam_masuk'] ?? null;
            $jamPulang = $hari['jam_pulang'] ?? null;

            if (! $this->formatJamValid($jamMasuk) || ! $this->formatJamValid($jamPulang)) {
                throw ValidationException::withMessages(['pola' => "Isi jam masuk dan jam pulang (JJ:MM) untuk hari ke-{$hariKe}."]);
            }

            try {
                $durasiMenit = $this->jadwalKerjaService->hitungDurasiMenit($jamMasuk, $jamPulang);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['pola' => "Jam pulang hari ke-{$hariKe} tidak boleh sama dengan jam masuk."]);
            }

            $detail[$hariKe] = [
                'status' => 'Kerja',
                'jam_masuk' => substr($jamMasuk, 0, 5).':00',
                'jam_pulang' => substr($jamPulang, 0, 5).':00',
                'durasi_menit' => $durasiMenit,
            ];
        }

        ksort($detail);

        return $detail;
    }

    /**
     * @param  array<int, array{status: string, jam_masuk: string|null, jam_pulang: string|null, durasi_menit: int|null}>  $detail
     */
    private function simpanDetail(int $idTemplate, array $detail): void
    {
        DB::table('tbl_template_jadwal_detail')->insert(array_map(
            static fn (int $hariKe, array $hari): array => ['id_template_jadwal' => $idTemplate, 'hari_ke' => $hariKe, ...$hari],
            array_keys($detail),
            array_values($detail),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function nilaiJadwal(object $pola): array
    {
        return [
            'status' => $pola->status,
            'id_template_shift' => null,
            'nama_shift_snapshot' => null,
            'jam_masuk' => $pola->status === 'Kerja' ? $pola->jam_masuk : null,
            'jam_pulang' => $pola->status === 'Kerja' ? $pola->jam_pulang : null,
            'durasi_menit' => $pola->status === 'Kerja' ? (int) $pola->durasi_menit : null,
        ];
    }

    private function ambilTemplate(int $idTemplate, bool $kunci = false): object
    {
        $query = DB::table('tbl_template_jadwal')->where('id', $idTemplate);

        if ($kunci) {
            $query->lockForUpdate();
        }

        $template = $query->first();

        if ($template === null) {
            throw ValidationException::withMessages(['id_template_jadwal' => 'Pola jadwal tidak ditemukan.']);
        }

        return $template;
    }

    private function awalBulan(string $bulan): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan) !== 1) {
            throw ValidationException::withMessages(['bulan' => 'Format bulan tujuan tidak valid.']);
        }

        return CarbonImmutable::parse($bulan.'-01')->startOfDay();
    }

    private function pastikanAda(string $tabel, int $id, string $kunci, string $pesan): void
    {
        if (! DB::table($tabel)->where('id', $id)->exists()) {
            throw ValidationException::withMessages([$kunci => $pesan]);
        }
    }

    private function idAtauNull(mixed $nilai): ?int
    {
        return $nilai === null || $nilai === '' ? null : (int) $nilai;
    }

    private function keteranganAtauNull(mixed $keterangan): ?string
    {
        $keterangan = trim((string) $keterangan);

        return $keterangan === '' ? null : $keterangan;
    }

    private function formatJamValid(mixed $jam): bool
    {
        return is_string($jam) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/', $jam) === 1;
    }

    private function formatJam(?string $jam): ?string
    {
        return $jam === null ? null : substr($jam, 0, 5);
    }
}

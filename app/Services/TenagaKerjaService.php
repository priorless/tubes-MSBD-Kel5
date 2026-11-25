<?php

namespace App\Services;

use App\Support\JamServer;
use App\Support\KolomTidakDitemukan;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Akses database untuk modul Tenaga Kerja (Orang 4).
 *
 * Prinsip:
 *  - Hanya SELECT / INSERT / UPDATE sederhana. Seluruh aturan bisnis
 *    (tolak absensi ganda, hitung gaji, dsb.) tetap di trigger, view,
 *    procedure, dan function database.
 *  - Setiap query SELALU difilter dengan id_karyawan milik pengguna aktif.
 *  - Waktu diambil dari server database (CURDATE/CURTIME/NOW) agar sama
 *    dengan waktu yang dipakai trigger.
 */
class TenagaKerjaService
{
    /** @var array<string, array<string, string>> cache: tabel => [nama_kolom => tipe] */
    private array $kolomCache = [];

    public function __construct(private FotoAbsensiService $foto, private JamServer $jam) {}

    /* =====================================================================
     * Utilitas kolom & error
     * ===================================================================== */

    public function tabel(string $kunci): string
    {
        return config("tenaga_kerja.tabel.$kunci");
    }

    /** Daftar kolom aktual sebuah tabel/view beserta tipe datanya. */
    public function kolomTabel(string $tabel): array
    {
        if (! isset($this->kolomCache[$tabel])) {
            $this->kolomCache[$tabel] = collect(Schema::getColumns($tabel))
                ->mapWithKeys(fn ($k) => [$k['name'] => strtolower($k['type_name'])])
                ->all();
        }

        return $this->kolomCache[$tabel];
    }

    /** Kandidat pertama yang benar-benar ada di tabel, atau null. */
    public function kolom(string $tabel, string $kunci): ?string
    {
        $ada = $this->kolomTabel($tabel);
        foreach ((array) config("tenaga_kerja.kolom.$kunci", []) as $kandidat) {
            if (array_key_exists($kandidat, $ada)) {
                return $kandidat;
            }
        }

        return null;
    }

    /** Sama seperti kolom(), tetapi wajib ada. */
    public function kolomWajib(string $tabel, string $kunci): string
    {
        return $this->kolom($tabel, $kunci) ?? throw new KolomTidakDitemukan(
            "Kolom \"$kunci\" tidak ditemukan di $tabel. Tambahkan nama kolom yang benar di config/tenaga_kerja.php."
        );
    }

    /** True jika kolom bertipe tanggal+waktu (bukan TIME/DATE murni). */
    private function bertipeDatetime(string $tabel, string $kolom): bool
    {
        return in_array($this->kolomTabel($tabel)[$kolom] ?? '', ['datetime', 'timestamp'], true);
    }

    /**
     * Ubah QueryException menjadi pesan yang aman untuk pengguna.
     * SQLSTATE 45000 = pesan dari SIGNAL di trigger/procedure → tampilkan apa adanya.
     * (Pola yang sama dengan CeoController agar konsisten.)
     */
    public static function pesanError(QueryException $e): string
    {
        // SIGNAL SQLSTATE '45000': kode bisa ada di getCode() atau hanya di errorInfo (tergantung driver).
        // 1062 = UNIQUE uq_absensi_karyawan_tgl: satu absensi per karyawan per tanggal (pengganti trg_cegah_absen_ganda).
        if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_absensi_karyawan_tgl')) {
            return 'Karyawan sudah absen pada tanggal ini.';
        }

        if ((string) $e->getCode() === '45000' || ($e->errorInfo[0] ?? null) === '45000') {
            if (is_string($e->errorInfo[2] ?? null) && $e->errorInfo[2] !== '') {
                return $e->errorInfo[2];
            }

            $pesan = explode(' (Connection:', $e->getMessage())[0];

            return trim(preg_replace('/^.*?1644\s+/s', '', $pesan));
        }

        report($e);

        return 'Terjadi kesalahan pada database. Silakan coba lagi beberapa saat lagi.';
    }

    /* =====================================================================
     * Waktu server & profil
     * ===================================================================== */

    /** Waktu server database saat ini — dipakai untuk menyinkronkan jam digital. */
    public function waktuServer(): string
    {
        return $this->jam->sekarang()->format('Y-m-d H:i:s');
    }

    /**
     * Satu titik waktu database untuk satu transaksi absensi (tanggal, jam, dan folder foto).
     *
     * @return object{sekarang: string, tgl: string, jam: string}
     */
    private function waktuTransaksi(): object
    {
        $sekarang = $this->jam->sekarang();

        return (object) [
            'sekarang' => $sekarang->format('Y-m-d H:i:s'),
            'tgl' => $sekarang->toDateString(),
            'jam' => $sekarang->format('H:i:s'),
        ];
    }

    public function karyawan(int $idKaryawan): ?object
    {
        $tabel = $this->tabel('karyawan');

        // tbl_karyawan memakai primary key `id` (bukan id_karyawan)
        return DB::table($tabel)
            ->where($this->kolomWajib($tabel, 'pk_karyawan'), $idKaryawan)
            ->first();
    }

    /** Jenis layanan karyawan (Satpam, Driver, Cleaning Service, ...); null untuk staf Internal. */
    public function namaLayanan(int $idKaryawan): ?string
    {
        return DB::table($this->tabel('karyawan').' as k')
            ->join('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
            ->where('k.id', $idKaryawan)
            ->value('jl.nama_layanan');
    }

    /* =====================================================================
     * Absensi
     * ===================================================================== */

    /** Kolom yang menyimpan tanggal absensi (kolom tanggal, atau jam_masuk jika bertipe DATETIME). */
    private function kolomTanggalAbsensi(): string
    {
        $tabel = $this->tabel('absensi');

        return $this->kolom($tabel, 'tanggal') ?? $this->kolomWajib($tabel, 'jam_masuk');
    }

    /**
     * Baris absensi yang sedang berlaku: hari ini, atau shift malam kemarin yang belum
     * check-out (aturannya di function database fn_id_absensi_aktif).
     * Jika function belum dipasang (migrasi belum dijalankan), kembali ke aturan lama: hari ini saja.
     */
    public function absensiHariIni(int $idKaryawan): ?object
    {
        $tabel = $this->tabel('absensi');
        $kolomId = $this->kolom($tabel, 'id_absensi');
        $idAktif = $kolomId ? $this->idAbsensiAktif($idKaryawan) : false;

        if ($idAktif !== false) {
            return $idAktif === null ? null : DB::table($tabel)
                ->where($kolomId, $idAktif)
                ->where($this->kolomWajib($tabel, 'id_karyawan'), $idKaryawan)
                ->first();
        }

        return DB::table($tabel)
            ->where($this->kolomWajib($tabel, 'id_karyawan'), $idKaryawan)
            ->whereRaw('DATE(`'.$this->kolomTanggalAbsensi().'`) = CURDATE()')
            ->when($kolomId, fn ($q) => $q->orderByDesc($kolomId))
            ->first();
    }

    /** Satu baris absensi berdasarkan id, tetap dibatasi milik karyawan ini. */
    public function absensiMilik(int $idKaryawan, int $idAbsensi): ?object
    {
        $tabel = $this->tabel('absensi');
        $kolomId = $this->kolom($tabel, 'id_absensi');

        return $kolomId ? DB::table($tabel)
            ->where($kolomId, $idAbsensi)
            ->where($this->kolomWajib($tabel, 'id_karyawan'), $idKaryawan)
            ->first() : null;
    }

    /** Id absensi aktif dari fn_id_absensi_aktif; false jika function belum ada di database. */
    private function idAbsensiAktif(int $idKaryawan): int|false|null
    {
        try {
            $id = DB::selectOne('SELECT fn_id_absensi_aktif(?) AS id', [$idKaryawan])?->id;
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1305) { // FUNCTION does not exist
                return false;
            }
            throw $e;
        }

        return $id === null ? null : (int) $id;
    }

    /**
     * Check-in berfoto: foto unggahan disiapkan lebih dulu (gagal dibaca = absensi tidak disentuh),
     * lalu INSERT baris baru dengan waktu server database dan simpan foto dalam satu transaksi.
     * Penolakan absensi ganda, penempatan, dan status terlambat SENGAJA tidak dicek di sini —
     * itu tugas trigger database. Pesan SIGNAL-nya akan ditangkap controller.
     *
     * @param  string|null  $tanggalShift  tanggal shift (Y-m-d) yang divalidasi JadwalAbsensiService; null = hari ini
     * @param  int|null  $idPenempatan  penempatan shift (milik sendiri, atau milik rekan untuk backup shift); null = diisi trigger
     * @param  (Closure(int, object): void)|null  $lanjutan  dokumentasi tambahan (mis. P2H) di dalam transaksi yang sama,
     *                                                       menerima id absensi baru dan waktu transaksi
     */
    public function checkIn(int $idKaryawan, string $isiFoto, ?string $tanggalShift = null, ?Closure $lanjutan = null, ?int $idPenempatan = null): void
    {
        $jpeg = $this->foto->siapkan($isiFoto);
        $tabel = $this->tabel('absensi');
        $kolomId = $this->kolomWajib($tabel, 'id_karyawan');
        $kolomMasuk = $this->kolomWajib($tabel, 'jam_masuk');
        $kolomTanggal = $this->kolom($tabel, 'tanggal');
        $kolomFoto = $this->kolomWajib($tabel, 'foto_masuk');
        $path = null;

        try {
            DB::transaction(function () use ($tabel, $kolomId, $kolomMasuk, $kolomTanggal, $kolomFoto, $idKaryawan, $jpeg, $tanggalShift, $lanjutan, $idPenempatan, &$path): void {
                $waktu = $this->waktuTransaksi();
                $waktu->tgl_shift = $tanggalShift ?? $waktu->tgl;
                $path = $this->foto->pathBaru('masuk', $waktu->tgl);

                $data = [
                    $kolomId => $idKaryawan,
                    $kolomMasuk => $this->bertipeDatetime($tabel, $kolomMasuk) ? $waktu->sekarang : $waktu->jam,
                    $kolomFoto => $path,
                ];
                if ($idPenempatan !== null && Schema::hasColumn($tabel, 'id_penempatan')) {
                    $data['id_penempatan'] = $idPenempatan;
                }
                if ($kolomTanggal && $kolomTanggal !== $kolomMasuk) {
                    $data[$kolomTanggal] = $this->bertipeDatetime($tabel, $kolomTanggal) ? $waktu->sekarang : $waktu->tgl_shift;
                }

                $idAbsensi = (int) DB::table($tabel)->insertGetId($data);
                if ($lanjutan !== null) {
                    $lanjutan($idAbsensi, $waktu);
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
     * Check-out berfoto: UPDATE jam_keluar + foto pada baris absensi aktif yang belum check-out.
     * Minimal durasi & lembur diperiksa trigger trg_auto_hitung_lembur.
     * Mengembalikan jumlah baris yang ter-update (0 = tidak ada yang bisa di-check-out; foto tidak disimpan).
     *
     * @param  (Closure(int, object): void)|null  $lanjutan  dokumentasi tambahan (mis. kondisi akhir kendaraan) di dalam
     *                                                       transaksi yang sama, menerima id absensi dan waktu transaksi
     */
    public function checkOut(int $idKaryawan, string $isiFoto, ?Closure $lanjutan = null): int
    {
        $jpeg = $this->foto->siapkan($isiFoto);
        $tabel = $this->tabel('absensi');
        $kolomId = $this->kolomWajib($tabel, 'id_karyawan');
        $kolomKeluar = $this->kolomWajib($tabel, 'jam_keluar');
        $kolomPk = $this->kolom($tabel, 'id_absensi');
        $kolomFoto = $this->kolomWajib($tabel, 'foto_keluar');
        $path = null;

        try {
            return DB::transaction(function () use ($tabel, $kolomId, $kolomKeluar, $kolomPk, $kolomFoto, $idKaryawan, $jpeg, $lanjutan, &$path): int {
                $idAktif = $kolomPk ? $this->idAbsensiAktif($idKaryawan) : false;
                if ($idAktif === null) {
                    return 0;
                }

                $waktu = $this->waktuTransaksi();
                $query = DB::table($tabel)->where($kolomId, $idKaryawan)->whereNull($kolomKeluar);
                $query = $idAktif !== false
                    ? $query->where($kolomPk, $idAktif)
                    : $query->whereRaw('DATE(`'.$this->kolomTanggalAbsensi().'`) = ?', [$waktu->tgl]);

                $path = $this->foto->pathBaru('keluar', $waktu->tgl);
                $jumlah = $query->update([
                    $kolomKeluar => $this->bertipeDatetime($tabel, $kolomKeluar) ? $waktu->sekarang : $waktu->jam,
                    $kolomFoto => $path,
                ]);

                if ($jumlah === 0) {
                    $path = null;

                    return 0;
                }

                if ($lanjutan !== null && $kolomPk) {
                    $idBaris = $idAktif !== false ? $idAktif : (int) DB::table($tabel)->where($kolomId, $idKaryawan)
                        ->where($kolomFoto, $path)->value($kolomPk);
                    $lanjutan((int) $idBaris, $waktu);
                }

                $this->foto->simpan($path, $jpeg);

                return $jumlah;
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                $this->foto->hapus($path);
            }
            throw $e;
        }
    }

    /**
     * Riwayat perubahan jadwal yang menyangkut karyawan (jadwal miliknya atau shift yang ia gantikan) sejak tanggal tertentu,
     * terbaru dahulu. data_sebelum/data_sesudah berupa JSON dari tbl_jadwal_kerja_riwayat.
     *
     * @return Collection<int, object>
     */
    public function perubahanJadwal(int $idKaryawan, string $sejak, int $batas = 30): Collection
    {
        if (! Schema::hasTable('tbl_jadwal_kerja_riwayat')) {
            return collect();
        }

        return DB::table('tbl_jadwal_kerja_riwayat as r')
            ->join('tbl_jadwal_kerja as j', 'j.id', '=', 'r.id_jadwal_kerja')
            ->join('tbl_penempatan as p', 'p.id', '=', 'j.id_penempatan')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->where('r.aksi', 'Diubah')
            ->where('r.created_at', '>=', $sejak)
            ->where(fn ($q) => $q->where('p.id_karyawan', $idKaryawan)->orWhere('j.id_karyawan_pengganti', $idKaryawan))
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id')
            ->limit($batas)
            ->get(['r.id', 'r.created_at', 'r.data_sebelum', 'r.data_sesudah', 'j.tanggal_kerja', 'p.id_karyawan as id_pemilik', 'k.nama as nama_pemilik']);
    }

    /**
     * Penempatan aktif hari ini + jadwal kerja efektif (v_penugasan_aktif).
     * Null jika belum ditempatkan atau view belum dipasang.
     */
    public function penugasanAktif(int $idKaryawan): ?object
    {
        $view = $this->tabel('penugasan_aktif');
        if (! $view || ! Schema::hasView($view)) {
            return null;
        }

        return DB::table($view)
            ->where('id_karyawan', $idKaryawan)
            ->orderByDesc('tgl_mulai')
            ->orderByDesc('id_penempatan')
            ->first();
    }

    /** Detail absensi per hari untuk satu bulan (v_absensi_harian), hanya milik karyawan ini. */
    public function absensiHarian(int $idKaryawan, int $tahun, int $bulan): Collection
    {
        $view = $this->tabel('absensi_harian');
        if (! $view || ! Schema::hasView($view)) {
            return collect();
        }

        return DB::table($view)
            ->where('id_karyawan', $idKaryawan)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->orderByDesc('tgl')
            ->get();
    }

    /* =====================================================================
     * Riwayat absensi (v_rekap_absensi_pribadi — rekap PER BULAN)
     * ===================================================================== */

    /** Query dasar: selalu difilter id_karyawan milik pengguna aktif, opsional tahun/bulan. */
    private function queryRiwayat(int $idKaryawan, ?int $tahun, ?int $bulan)
    {
        $view = $this->tabel('rekap_absensi');

        return DB::table($view)
            ->where($this->kolomWajib($view, 'id_karyawan'), $idKaryawan)
            ->when($tahun, fn ($q) => $q->where($this->kolomWajib($view, 'periode_tahun'), $tahun))
            ->when($bulan, fn ($q) => $q->where($this->kolomWajib($view, 'periode_bulan'), $bulan));
    }

    /** Urutkan periode terbaru lebih dulu (tahun, lalu bulan) jika kolomnya ada. */
    private function urutkanPeriode($query, string $view)
    {
        $tahun = $this->kolom($view, 'periode_tahun');
        $bulan = $this->kolom($view, 'periode_bulan');

        return $query
            ->when($tahun, fn ($q) => $q->orderByDesc($tahun))
            ->when($bulan, fn ($q) => $q->orderByDesc($bulan));
    }

    public function riwayatAbsensi(int $idKaryawan, ?int $tahun, ?int $bulan, int $perHalaman): LengthAwarePaginator
    {
        return $this->urutkanPeriode($this->queryRiwayat($idKaryawan, $tahun, $bulan), $this->tabel('rekap_absensi'))
            ->paginate($perHalaman)
            ->withQueryString();
    }

    /**
     * Total kolom rekap (hadir, terlambat, tidak hadir, jam lembur) untuk periode yang difilter.
     * Hanya SUM tampilan atas angka yang sudah dihitung view — bukan logika bisnis.
     * Kolom yang tidak ada di view dilewati.
     *
     * @return array<string, array{kolom: string, jumlah: float}> kunci = rekap_hadir, dst.
     */
    public function ringkasanRekap(int $idKaryawan, ?int $tahun, ?int $bulan): array
    {
        $view = $this->tabel('rekap_absensi');
        $kolom = collect(['rekap_hadir', 'rekap_terlambat', 'rekap_tidak_hadir', 'rekap_lembur'])
            ->mapWithKeys(fn ($kunci) => [$kunci => $this->kolom($view, $kunci)])
            ->filter();

        if ($kolom->isEmpty()) {
            return [];
        }

        $baris = $this->queryRiwayat($idKaryawan, $tahun, $bulan)
            ->selectRaw($kolom->map(fn ($k, $kunci) => "COALESCE(SUM(`$k`), 0) AS `$kunci`")->implode(', '))
            ->first();

        return $kolom->map(fn ($k, $kunci) => ['kolom' => $k, 'jumlah' => (float) ($baris->{$kunci} ?? 0)])->all();
    }

    /* =====================================================================
     * Slip gaji (v_slip_gaji_detail)
     * ===================================================================== */

    /**
     * Daftar slip milik karyawan aktif.
     *
     * v_slip_gaji_detail TIDAK memiliki kolom id_karyawan; kolom `id`-nya adalah
     * id PAYROLL, sehingga TIDAK BOLEH dipakai sebagai identitas karyawan.
     * Identitas yang dipakai: NIP yang dibaca dari tbl_karyawan berdasarkan
     * id_karyawan pengguna login (NIP UNIQUE + NOT NULL, dan view men-JOIN
     * tbl_payroll ke tbl_karyawan, jadi NIP di view = NIP pemilik payroll).
     * Jika suatu saat view menyediakan id_karyawan, kolom itu dipakai.
     */
    public function slipGaji(int $idKaryawan): Collection
    {
        $view = $this->tabel('slip_gaji');
        $tahun = $this->kolom($view, 'periode_tahun');
        $bulan = $this->kolom($view, 'periode_bulan');
        $periode = $this->kolom($view, 'periode');

        if ($kolomIdKaryawan = $this->kolom($view, 'id_karyawan')) {
            $filter = [$kolomIdKaryawan, $idKaryawan];
        } else {
            $karyawan = $this->karyawan($idKaryawan);
            $nip = $karyawan?->{$this->kolomWajib($this->tabel('karyawan'), 'nip')};

            // Tanpa identitas yang valid, jangan pernah query view tanpa filter.
            if ($nip === null || trim((string) $nip) === '') {
                return collect();
            }
            $filter = [$this->kolomWajib($view, 'nip'), (string) $nip];
        }

        return DB::table($view)
            ->where(...$filter)
            ->when($tahun, fn ($q) => $q->orderByDesc($tahun))
            ->when($bulan, fn ($q) => $q->orderByDesc($bulan))
            ->when(! $tahun && $periode, fn ($q) => $q->orderByDesc($periode))
            ->get();
    }
}

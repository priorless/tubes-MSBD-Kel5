<?php

namespace App\Services;

use App\Support\KolomTidakDitemukan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        if ((string) $e->getCode() === '45000') {
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
        return DB::selectOne('SELECT NOW() AS sekarang')->sekarang;
    }

    public function karyawan(int $idKaryawan): ?object
    {
        $tabel = $this->tabel('karyawan');

        return DB::table($tabel)
            ->where($this->kolomWajib($tabel, 'id_karyawan'), $idKaryawan)
            ->first();
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

    /** Baris absensi milik karyawan untuk HARI INI (menurut jam server database). */
    public function absensiHariIni(int $idKaryawan): ?object
    {
        $tabel = $this->tabel('absensi');
        $kolomTanggal = $this->kolomTanggalAbsensi();
        $kolomId = $this->kolom($tabel, 'id_absensi');

        return DB::table($tabel)
            ->where($this->kolomWajib($tabel, 'id_karyawan'), $idKaryawan)
            ->whereRaw('DATE(`'.$kolomTanggal.'`) = CURDATE()')
            ->when($kolomId, fn ($q) => $q->orderByDesc($kolomId))
            ->first();
    }

    /**
     * Check-in: INSERT baris baru dengan waktu masuk saat ini.
     * Penolakan absensi ganda SENGAJA tidak dicek di sini — itu tugas trigger
     * database. Pesan SIGNAL-nya akan ditangkap controller.
     */
    public function checkIn(int $idKaryawan): void
    {
        $tabel = $this->tabel('absensi');
        $kolomId = $this->kolomWajib($tabel, 'id_karyawan');
        $kolomMasuk = $this->kolomWajib($tabel, 'jam_masuk');
        $kolomTanggal = $this->kolom($tabel, 'tanggal');

        $kolom = ["`$kolomId`", "`$kolomMasuk`"];
        $nilai = ['?', $this->bertipeDatetime($tabel, $kolomMasuk) ? 'NOW()' : 'CURTIME()'];

        if ($kolomTanggal && $kolomTanggal !== $kolomMasuk) {
            $kolom[] = "`$kolomTanggal`";
            $nilai[] = $this->bertipeDatetime($tabel, $kolomTanggal) ? 'NOW()' : 'CURDATE()';
        }

        DB::insert(
            "INSERT INTO `$tabel` (".implode(', ', $kolom).') VALUES ('.implode(', ', $nilai).')',
            [$idKaryawan]
        );
    }

    /**
     * Check-out: UPDATE jam_keluar pada baris absensi hari ini yang belum check-out.
     * Mengembalikan jumlah baris yang ter-update (0 = tidak ada yang bisa di-check-out).
     */
    public function checkOut(int $idKaryawan): int
    {
        $tabel = $this->tabel('absensi');
        $kolomId = $this->kolomWajib($tabel, 'id_karyawan');
        $kolomKeluar = $this->kolomWajib($tabel, 'jam_keluar');
        $kolomTanggal = $this->kolomTanggalAbsensi();
        $waktu = $this->bertipeDatetime($tabel, $kolomKeluar) ? 'NOW()' : 'CURTIME()';

        return DB::update(
            "UPDATE `$tabel` SET `$kolomKeluar` = $waktu
             WHERE `$kolomId` = ? AND DATE(`$kolomTanggal`) = CURDATE() AND `$kolomKeluar` IS NULL",
            [$idKaryawan]
        );
    }

    /* =====================================================================
     * Riwayat absensi (v_rekap_absensi_pribadi)
     * ===================================================================== */

    private function queryRiwayat(int $idKaryawan, ?string $dari, ?string $sampai)
    {
        $view = $this->tabel('rekap_absensi');
        $kolomTanggal = $this->kolom($view, 'tanggal');

        if (($dari || $sampai) && ! $kolomTanggal) {
            throw new KolomTidakDitemukan('Filter tanggal tidak tersedia karena view riwayat tidak memiliki kolom tanggal.');
        }

        return DB::table($view)
            ->where($this->kolomWajib($view, 'id_karyawan'), $idKaryawan)
            ->when($dari, fn ($q) => $q->whereRaw("DATE(`$kolomTanggal`) >= ?", [$dari]))
            ->when($sampai, fn ($q) => $q->whereRaw("DATE(`$kolomTanggal`) <= ?", [$sampai]));
    }

    public function riwayatAbsensi(int $idKaryawan, ?string $dari, ?string $sampai, int $perHalaman): LengthAwarePaginator
    {
        $kolomTanggal = $this->kolom($this->tabel('rekap_absensi'), 'tanggal');

        return $this->queryRiwayat($idKaryawan, $dari, $sampai)
            ->when($kolomTanggal, fn ($q) => $q->orderByDesc($kolomTanggal))
            ->paginate($perHalaman)
            ->withQueryString();
    }

    /** Semua baris (untuk ekspor CSV) — tetap hanya milik karyawan ini. */
    public function semuaRiwayatAbsensi(int $idKaryawan, ?string $dari, ?string $sampai): Collection
    {
        $kolomTanggal = $this->kolom($this->tabel('rekap_absensi'), 'tanggal');

        return $this->queryRiwayat($idKaryawan, $dari, $sampai)
            ->when($kolomTanggal, fn ($q) => $q->orderByDesc($kolomTanggal))
            ->get();
    }

    /**
     * Jumlah baris per nilai status (hanya COUNT tampilan, bukan logika bisnis).
     * Mengembalikan koleksi kosong jika view tidak punya kolom status.
     */
    public function ringkasanStatus(int $idKaryawan, ?string $dari, ?string $sampai): Collection
    {
        $kolomStatus = $this->kolom($this->tabel('rekap_absensi'), 'status');
        if (! $kolomStatus) {
            return collect();
        }

        return $this->queryRiwayat($idKaryawan, $dari, $sampai)
            ->selectRaw("`$kolomStatus` AS status, COUNT(*) AS jumlah")
            ->groupBy($kolomStatus)
            ->orderByDesc('jumlah')
            ->pluck('jumlah', 'status');
    }

    /* =====================================================================
     * Slip gaji (v_slip_gaji_detail)
     * ===================================================================== */

    public function slipGaji(int $idKaryawan): Collection
    {
        $view = $this->tabel('slip_gaji');
        $tahun = $this->kolom($view, 'periode_tahun');
        $bulan = $this->kolom($view, 'periode_bulan');
        $periode = $this->kolom($view, 'periode');

        return DB::table($view)
            ->where($this->kolomWajib($view, 'id_karyawan'), $idKaryawan)
            ->when($tahun, fn ($q) => $q->orderByDesc($tahun))
            ->when($bulan, fn ($q) => $q->orderByDesc($bulan))
            ->when(! $tahun && $periode, fn ($q) => $q->orderByDesc($periode))
            ->get();
    }
}

<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Menjalankan test pada database MySQL db_sji (trigger, procedure, view asli) di dalam transaksi
 * yang selalu di-rollback, seperti HrdModulTest. Test dilewati bila db_sji tidak tersedia.
 *
 * Catatan: procedure yang punya START TRANSACTION/COMMIT sendiri (penempatan, rotasi, payroll,
 * selesai penempatan, invoice) meng-commit transaksi test. Karena itu semua data yang dibuat helper
 * dicatat dan dihapus lagi di tearDownDatabaseSji() (termasuk baris turunan dari trigger/procedure).
 */
trait MemakaiDatabaseSji
{
    /** Prefix NIP/kode/nomor untuk semua data uji agar mudah dibersihkan. */
    protected string $prefixUji = 'TSTX';

    /** @var array{karyawan: list<int>, klien: list<int>, user: list<int>} */
    private array $dataUji = ['karyawan' => [], 'klien' => [], 'user' => []];

    /** @var list<array{int, int}> periode [bulan, tahun] yang payroll-nya dihapus di tearDown */
    private array $periodeUji = [];

    /** id log aktivitas terakhir sebelum test; log yang lebih baru (hasil test) dihapus di tearDown. */
    private ?int $batasLogAktivitas = null;

    /** false bila test dilewati karena db_sji tidak tersedia (tearDown tidak menyentuh database). */
    private bool $databaseSjiSiap = false;

    protected function setUpDatabaseSji(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'db_sji',
        ]);
        DB::purge('mysql');

        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('Database MySQL db_sji tidak tersedia.');
        }

        if (Schema::hasTable('tbl_log_aktivitas')) {
            $this->batasLogAktivitas = (int) DB::table('tbl_log_aktivitas')->max('id');
        }

        $this->databaseSjiSiap = true;
        DB::beginTransaction();
    }

    protected function tearDownDatabaseSji(): void
    {
        if (! $this->databaseSjiSiap) {
            return;
        }

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->hapusDataUji();

        // Log dari procedure yang ter-commit dan dari pembersihan di atas.
        if ($this->batasLogAktivitas !== null) {
            DB::table('tbl_log_aktivitas')->where('id', '>', $this->batasLogAktivitas)->delete();
        }

        DB::statement('SET @app_user_id = NULL');
    }

    /**
     * Hapus data uji yang mungkin ter-commit oleh procedure (urutan mengikuti foreign key).
     */
    private function hapusDataUji(): void
    {
        $karyawan = $this->dataUji['karyawan'];
        $klien = $this->dataUji['klien'];
        $user = $this->dataUji['user'];

        // sp_generate_payroll_bulanan memproses semua karyawan: hapus seluruh payroll periode uji.
        foreach ($this->periodeUji as [$bulan, $tahun]) {
            DB::table('tbl_payroll')->where('periode_bulan', $bulan)->where('periode_tahun', $tahun)->delete();
        }
        $this->periodeUji = [];

        if ($karyawan === [] && $klien === [] && $user === []) {
            return;
        }

        $penempatan = DB::table('tbl_penempatan')
            ->where(fn ($q) => $q->whereIn('id_karyawan', $karyawan ?: [0])->orWhereIn('id_klien', $klien ?: [0]))
            ->pluck('id')->all();

        DB::table('tbl_absensi')->whereIn('id_karyawan', $karyawan ?: [0])->delete();
        DB::table('tbl_payroll')->whereIn('id_karyawan', $karyawan ?: [0])->delete();
        DB::table('tbl_invoice')->whereIn('id_klien', $klien ?: [0])->delete();
        DB::table('tbl_sertifikat')->whereIn('id_karyawan', $karyawan ?: [0])->delete();
        DB::table('tbl_kontrak')->whereIn('id_penempatan', $penempatan ?: [0])->delete();
        DB::table('tbl_penempatan')->whereIn('id', $penempatan ?: [0])->delete();
        DB::table('tbl_users')->whereIn('id_karyawan', $karyawan ?: [0])->orWhereIn('id', $user ?: [0])->delete();
        DB::table('tbl_karyawan')->whereIn('id', $karyawan ?: [0])->delete();
        DB::table('tbl_klien')->whereIn('id', $klien ?: [0])->delete();

        $this->dataUji = ['karyawan' => [], 'klien' => [], 'user' => []];
    }

    /**
     * Daftarkan periode uji; semua payroll periode ini dihapus di tearDown. Pakai periode yang tidak
     * berisi data asli (mis. tahun 2099), karena procedure payroll memproses semua karyawan.
     */
    protected function catatPeriodeUji(int $bulan, int $tahun): void
    {
        $this->periodeUji[] = [$bulan, $tahun];
    }

    /**
     * @param  array<string, mixed>  $timpa
     */
    protected function buatAbsensi(int $idKaryawan, string $tgl, string $status = 'Hadir', array $timpa = []): int
    {
        return DB::table('tbl_absensi')->insertGetId([
            'id_karyawan' => $idKaryawan,
            'tgl' => $tgl,
            'status_kehadiran' => $status,
            ...$timpa,
        ]);
    }

    /** Tanggal server database (zona waktu DB), bukan jam PHP. */
    protected function hariIniDb(int $selisihHari = 0): string
    {
        return DB::selectOne('SELECT DATE_ADD(CURDATE(), INTERVAL ? DAY) AS tgl', [$selisihHari])->tgl;
    }

    protected function loginSebagai(string $namaRole): User
    {
        $user = User::factory()->withRole($namaRole)->create();
        $this->dataUji['user'][] = $user->id;
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $timpa
     */
    protected function buatKaryawan(string $kategori = 'Lapangan', array $timpa = []): int
    {
        static $urutan = 0;
        $urutan++;
        $kode = $this->prefixUji.'-'.$urutan.'-'.random_int(1000, 9999);

        $data = [
            'nip' => $kode,
            'nama' => 'Karyawan Uji '.$urutan,
            'kategori' => $kategori,
            'no_ktp' => str_pad((string) random_int(1, 999999999), 16, '9', STR_PAD_LEFT),
            'tgl_lahir' => '1995-05-17',
            'jenis_kelamin' => 'L',
            'no_telp' => '081234567890',
            'tgl_masuk' => '2026-01-05',
        ];

        if ($kategori === 'Lapangan') {
            $data['id_jenis_layanan'] = DB::table('tbl_jenis_layanan')->where('nama_layanan', 'Driver')->value('id');
        } else {
            $data['gaji_pokok'] = 5000000;
        }

        $id = DB::table('tbl_karyawan')->insertGetId([...$data, ...$timpa]);
        $this->dataUji['karyawan'][] = $id;

        return $id;
    }

    /**
     * @param  array<string, mixed>  $timpa
     */
    protected function buatKlien(array $timpa = []): int
    {
        return $this->dataUji['klien'][] = DB::table('tbl_klien')->insertGetId([
            'kode_klien' => substr($this->prefixUji.random_int(100, 999), 0, 10),
            'nama_perusahaan' => 'Klien Uji',
            'alamat' => 'Jl. Uji',
            'pic_nama' => 'PIC Uji',
            'pic_telp' => '0800',
            'status' => 'Aktif',
            ...$timpa,
        ]);
    }

    /**
     * Penempatan + kontrak langsung (tanpa procedure, agar tetap di dalam transaksi test).
     *
     * @param  array<string, mixed>  $timpa
     */
    protected function buatPenempatan(int $idKaryawan, int $idKlien, string $tglMulai, ?string $tglSelesai = null, array $timpa = [], float $gaji = 3000000, int $hariKerja = 25): int
    {
        $idPenempatan = DB::table('tbl_penempatan')->insertGetId([
            'id_karyawan' => $idKaryawan,
            'id_klien' => $idKlien,
            'lokasi_kerja' => 'Lokasi Uji',
            'jam_datang' => '08:00:00',
            'jam_pulang' => '16:00:00',
            'tgl_mulai' => $tglMulai,
            'tgl_selesai' => $tglSelesai,
            'status' => 'Aktif',
            ...$timpa,
        ]);

        DB::table('tbl_kontrak')->insert([
            'id_penempatan' => $idPenempatan,
            'gaji_pokok' => $gaji,
            'hari_kerja_per_bulan' => $hariKerja,
            'status' => ($timpa['status'] ?? 'Aktif') === 'Aktif' ? 'Aktif' : 'Selesai',
        ]);

        return $idPenempatan;
    }
}

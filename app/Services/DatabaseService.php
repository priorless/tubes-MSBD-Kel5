<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pintu tunggal untuk memanggil Stored Procedure, Function, dan View di db_sji.
 *
 * Semua modul (HRD, Operasional, Keuangan, CEO, Tenaga Kerja) memanggil logika database lewat kelas ini,
 * bukan lewat DB::statement langsung di controller.
 *
 * Penamaan:
 * - call*  : menjalankan Stored Procedure (mengubah data).
 * - get*   : membaca View atau memanggil Function (hanya baca).
 *
 * Error SIGNAL SQLSTATE '45000' dari database dilempar sebagai QueryException dan otomatis ditampilkan
 * sebagai flash message oleh handler di bootstrap/app.php. Untuk menanganinya manual di controller,
 * gunakan DatabaseService::isPesanBisnis() dan DatabaseService::pesanError().
 */
class DatabaseService
{
    private const string SQLSTATE_PESAN_BISNIS = '45000';

    /*
    |--------------------------------------------------------------------------
    | Penanganan Error
    |--------------------------------------------------------------------------
    */

    /**
     * Apakah exception berasal dari SIGNAL SQLSTATE '45000' (pesan aturan bisnis dari database).
     */
    public static function isPesanBisnis(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? (string) $exception->getCode()) === self::SQLSTATE_PESAN_BISNIS;
    }

    /**
     * Ubah QueryException menjadi pesan ramah untuk ditampilkan ke pengguna.
     */
    public static function pesanError(QueryException $exception): string
    {
        if (self::isPesanBisnis($exception)) {
            $pesan = $exception->errorInfo[2] ?? null;

            if (is_string($pesan) && $pesan !== '') {
                return $pesan;
            }

            return 'Permintaan ditolak oleh aturan sistem.';
        }

        return 'Terjadi kesalahan pada database. Silakan coba lagi atau hubungi administrator.';
    }

    /*
    |--------------------------------------------------------------------------
    | Modul HRD
    |--------------------------------------------------------------------------
    */

    /**
     * View v_pool_karyawan_hrd: karyawan lapangan berstatus Tersedia.
     *
     * @return Collection<int, object>
     */
    public static function getPoolKaryawan(): Collection
    {
        return DB::table('v_pool_karyawan_hrd')->orderBy('nama')->get();
    }

    /**
     * Procedure sp_input_ketidakhadiran: HRD mencatat Izin/Sakit/Alpha.
     *
     * @throws QueryException SQLSTATE 45000 jika status/tanggal tidak valid, karyawan Nonaktif,
     *                        sudah ada absensi, atau karyawan Lapangan tanpa penempatan pada tanggal itu.
     */
    public static function callInputKetidakhadiran(int $idKaryawan, string $tgl, string $status, ?string $keterangan): void
    {
        DB::statement('CALL sp_input_ketidakhadiran(?, ?, ?, ?)', [$idKaryawan, $tgl, $status, $keterangan]);
    }

    /**
     * Function fn_sisa_hari_dokumen: sisa hari sebelum dokumen kadaluarsa (minimal 0).
     */
    public static function getSisaHariDokumen(string $tglKadaluarsa): int
    {
        $result = DB::selectOne('SELECT fn_sisa_hari_dokumen(?) AS sisa_hari', [$tglKadaluarsa]);

        return (int) ($result->sisa_hari ?? 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Modul Operasional
    |--------------------------------------------------------------------------
    */

    /**
     * Procedure sp_assign_penempatan (8 parameter, sama dengan OperasionalController::storePenempatan).
     *
     * @throws QueryException SQLSTATE 45000 jika karyawan/klien tidak valid, klien Nonaktif,
     *                        atau tanggal selesai sebelum tanggal mulai.
     */
    public static function callAssignPenempatan(
        int $idKaryawan,
        int $idKlien,
        string $tglMulai,
        ?string $tglSelesai,
        string $lokasiKerja,
        string $jamDatang,
        string $jamPulang,
        int $idUserPembuat,
    ): void {
        DB::statement('CALL sp_assign_penempatan(?, ?, ?, ?, ?, ?, ?, ?)', [
            $idKaryawan, $idKlien, $tglMulai, $tglSelesai, $lokasiKerja, $jamDatang, $jamPulang, $idUserPembuat,
        ]);
    }

    /**
     * Procedure sp_rotasi_karyawan (8 parameter).
     * Tanggal selesai baru null berarti kontrak baru tidak memiliki batas akhir.
     * Jam datang/pulang null = tetap memakai jadwal penempatan lama.
     *
     * @throws QueryException SQLSTATE 45000 jika data rotasi tidak memenuhi aturan database.
     */
    public static function callRotasiKaryawan(
        int $idPenempatanLama,
        int $idKlienBaru,
        string $tglMulaiBaru,
        ?string $tglSelesaiBaru,
        string $lokasiBaru,
        ?string $jamDatangBaru,
        ?string $jamPulangBaru,
        int $idUserPembuat,
    ): void {
        DB::statement('CALL sp_rotasi_karyawan(?, ?, ?, ?, ?, ?, ?, ?)', [
            $idPenempatanLama, $idKlienBaru, $tglMulaiBaru, $tglSelesaiBaru, $lokasiBaru,
            $jamDatangBaru, $jamPulangBaru, $idUserPembuat,
        ]);
    }

    /**
     * Procedure sp_selesai_penempatan: penempatan & kontrak menjadi Selesai, karyawan kembali Tersedia.
     *
     * @throws QueryException SQLSTATE 45000 jika penempatan tidak ada, bukan Aktif, atau tanggal tidak valid.
     */
    public static function callSelesaiPenempatan(int $idPenempatan, string $tglSelesai): void
    {
        DB::statement('CALL sp_selesai_penempatan(?, ?)', [$idPenempatan, $tglSelesai]);
    }

    /**
     * View v_monitoring_sebaran_operasional.
     *
     * @return Collection<int, object>
     */
    public static function getMonitoringSebaranOperasional(): Collection
    {
        return DB::table('v_monitoring_sebaran_operasional')->orderBy('sisa_hari_kontrak')->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Modul Keuangan
    |--------------------------------------------------------------------------
    */

    /**
     * Procedure sp_generate_payroll_bulanan.
     */
    public static function callGeneratePayrollBulanan(int $bulan, int $tahun): void
    {
        DB::statement('CALL sp_generate_payroll_bulanan(?, ?)', [$bulan, $tahun]);
    }

    /**
     * Procedure sp_generate_invoice_klien.
     *
     * @throws QueryException SQLSTATE 45000 jika invoice sudah ada / tidak ada tenaga aktif.
     */
    public static function callGenerateInvoiceKlien(int $idKlien, int $bulan, int $tahun): void
    {
        DB::statement('CALL sp_generate_invoice_klien(?, ?, ?)', [$idKlien, $bulan, $tahun]);
    }

    /**
     * Procedure sp_update_status_bayar. Jenis: 'INVOICE' atau 'PAYROLL'.
     *
     * @throws QueryException SQLSTATE 45000 jika jenis tidak valid.
     */
    public static function callUpdateStatusBayar(string $jenis, int $id, string $tanggal): void
    {
        DB::statement('CALL sp_update_status_bayar(?, ?, ?)', [$jenis, $id, $tanggal]);
    }

    /**
     * View v_slip_gaji_detail.
     *
     * @return Collection<int, object>
     */
    public static function getSlipGajiDetail(?int $bulan = null, ?int $tahun = null): Collection
    {
        return DB::table('v_slip_gaji_detail')
            ->when($bulan !== null, fn ($query) => $query->where('periode_bulan', $bulan))
            ->when($tahun !== null, fn ($query) => $query->where('periode_tahun', $tahun))
            ->orderBy('nama')
            ->get();
    }

    /**
     * Function fn_hitung_lembur.
     */
    public static function getHitungLembur(int $jamLembur): float
    {
        $result = DB::selectOne('SELECT fn_hitung_lembur(?) AS nominal', [$jamLembur]);

        return (float) ($result->nominal ?? 0);
    }

    /**
     * Function fn_hitung_potongan_absen.
     */
    public static function getHitungPotonganAbsen(float $gajiPokok, int $hariKerja, int $jumlahAbsen): float
    {
        $result = DB::selectOne('SELECT fn_hitung_potongan_absen(?, ?, ?) AS nominal', [$gajiPokok, $hariKerja, $jumlahAbsen]);

        return (float) ($result->nominal ?? 0);
    }

    /**
     * Function fn_total_piutang: total invoice berstatus Belum Lunas.
     */
    public static function getTotalPiutang(): float
    {
        $result = DB::selectOne('SELECT fn_total_piutang() AS total');

        return (float) ($result->total ?? 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Modul Tenaga Kerja
    |--------------------------------------------------------------------------
    */

    /**
     * View v_rekap_absensi_pribadi untuk satu karyawan.
     *
     * @return Collection<int, object>
     */
    public static function getRekapAbsensiPribadi(int $idKaryawan): Collection
    {
        return DB::table('v_rekap_absensi_pribadi')
            ->where('id_karyawan', $idKaryawan)
            ->orderByDesc('tahun')
            ->orderByDesc('bulan')
            ->get();
    }

    /**
     * Function fn_hitung_jam_kerja.
     */
    public static function getHitungJamKerja(string $jamMasuk, string $jamKeluar): int
    {
        $result = DB::selectOne('SELECT fn_hitung_jam_kerja(?, ?) AS jam', [$jamMasuk, $jamKeluar]);

        return (int) ($result->jam ?? 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Modul CEO
    |--------------------------------------------------------------------------
    */

    /**
     * View v_dashboard_eksekutif (selalu satu baris).
     *
     * @return object{lapangan_bertugas: int, lapangan_siap: int, internal_aktif: int, total_piutang: string, profit_bulan_berjalan: string}|null
     */
    public static function getDashboardEksekutif(): ?object
    {
        return DB::table('v_dashboard_eksekutif')->first();
    }

    /**
     * View v_arus_kas_bulanan, urut kronologis. Batasi ke N bulan terakhir jika $jumlahBulan diisi.
     *
     * @return Collection<int, object{tahun: int, bulan: int, total_masuk: string, total_keluar: string, selisih: string}>
     */
    public static function getArusKasBulanan(?int $jumlahBulan = null): Collection
    {
        return DB::table('v_arus_kas_bulanan')
            ->orderByDesc('tahun')
            ->orderByDesc('bulan')
            ->when($jumlahBulan !== null, fn ($query) => $query->limit($jumlahBulan))
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Function fn_profit_klien untuk satu klien.
     */
    public static function getProfitKlien(int $idKlien, int $bulan, int $tahun): float
    {
        $result = DB::selectOne('SELECT fn_profit_klien(?, ?, ?) AS profit', [$idKlien, $bulan, $tahun]);

        return (float) ($result->profit ?? 0);
    }

    /**
     * Function fn_profit_klien untuk seluruh klien aktif dalam satu query, urut dari profit tertinggi.
     *
     * @return Collection<int, object{id: int, kode_klien: string, nama_perusahaan: string, profit: string}>
     */
    public static function getProfitSemuaKlien(int $bulan, int $tahun): Collection
    {
        return collect(DB::select(
            'SELECT id, kode_klien, nama_perusahaan, fn_profit_klien(id, ?, ?) AS profit
             FROM tbl_klien
             WHERE status = ?
             ORDER BY profit DESC, nama_perusahaan',
            [$bulan, $tahun, 'Aktif'],
        ));
    }
}

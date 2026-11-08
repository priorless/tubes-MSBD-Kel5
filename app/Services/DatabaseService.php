<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DatabaseService
{


    public static function getDashboardEksekutif()
    {
        // Membaca view ringkasan eksekutif
        return DB::table('v_dashboard_eksekutif')->first() 
            ?? DB::table('v_dashboard_eksekutif')->get();
    }

    public static function getArusKasBulanan()
    {
        // Membaca view arus kas untuk Chart.js
        return DB::table('v_arus_kas_bulanan')->get();
    }

    public static function getProfitKlien(int $idKlien, int $bulan, int $tahun): float
    {
       
        $result = DB::selectOne(
            'SELECT fn_profit_klien(?, ?, ?) AS profit',
            [$idKlien, $bulan, $tahun]
        );
        return (float) ($result->profit ?? 0);
    }

   
    public static function getPoolKaryawan() {
        return DB::table('v_pool_karyawan_hrd')->get();
    }
    public static function getSisaHariDokumen(int $idSertifikat): int {
        $result = DB::selectOne('SELECT fn_sisa_hari_dokumen(?) AS sisa_hari', [$idSertifikat]);
        return (int) ($result->sisa_hari ?? 0);
    }

  
    public static function assignPenempatan(int $idKaryawan, int $idKlien, string $tglMulai, string $tglSelesai, string $lokasi, int $idUserLogin) {
        return DB::statement('CALL sp_assign_penempatan(?, ?, ?, ?, ?, ?)', [$idKaryawan, $idKlien, $tglMulai, $tglSelesai, $lokasi, $idUserLogin]);
    }
    public static function rotasiKaryawan(int $idPenempatan, int $idKlienBaru, string $tglMulai, int $idUserLogin) {
        return DB::statement('CALL sp_rotasi_karyawan(?, ?, ?, ?)', [$idPenempatan, $idKlienBaru, $tglMulai, $idUserLogin]);
    }
    public static function getMonitoringSebaranOperasional() {
        return DB::table('v_monitoring_sebaran_operasional')->get();
    }

    
    public static function generatePayrollBulanan(int $bulan, int $tahun) {
        return DB::statement('CALL sp_generate_payroll_bulanan(?, ?)', [$bulan, $tahun]);
    }
    public static function getSlipGajiDetail() {
        return DB::table('v_slip_gaji_detail')->get();
    }
    public static function generateInvoiceKlien(int $idKlien, int $bulan, int $tahun) {
        return DB::statement('CALL sp_generate_invoice_klien(?, ?, ?)', [$idKlien, $bulan, $tahun]);
    }
    public static function updateStatusBayar(string $tipe, int $id, string $tanggal) {
        return DB::statement('CALL sp_update_status_bayar(?, ?, ?)', [$tipe, $id, $tanggal]);
    }

    public static function getRekapAbsensiPribadi(int $idKaryawan) {
        return DB::table('v_rekap_absensi_pribadi')->where('id_karyawan', $idKaryawan)->get();
    }
}
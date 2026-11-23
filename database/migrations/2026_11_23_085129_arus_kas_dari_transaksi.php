<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v_arus_kas_bulanan dan profit_bulan_berjalan (v_dashboard_eksekutif) dihitung dari invoice Lunas dan payroll
 * Sudah Lunas, ditambah baris lama tbl_mutasi_keuangan (sql/Komponen/21_arus_kas_dari_transaksi.sql [TAHAP 1]).
 * CREATE OR REPLACE VIEW membuat up()/down() aman diulang.
 */
return new class extends Migration
{
    private const string FILE = 'sql/Komponen/21_arus_kas_dari_transaksi.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/21_arus_kas_dari_transaksi_rollback.sql';

    public function up(): void
    {
        $this->jalankan(self::FILE);
    }

    public function down(): void
    {
        $this->jalankan(self::FILE_ROLLBACK);
    }

    private function jalankan(string $file): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable('tbl_mutasi_keuangan')) {
            return;
        }

        SqlKomponen::jalankanBlok($file, 1);
        SqlKomponen::pasangHakAkses();
    }
};

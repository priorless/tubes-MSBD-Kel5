<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji pokok karyawan Lapangan diinput Keuangan di Komponen Gaji (sql/Komponen/19_gaji_pokok_komponen.sql).
 *
 * - tbl_komponen_gaji: + gaji_pokok DECIMAL(12,2) NULL = gaji pokok sebulan yang mulai berlaku pada periode itu.
 * - fn_gaji_pokok_berlaku + sp_generate_payroll_bulanan memakai gaji tersebut; tanpa input tetap gaji kontrak.
 * Payroll yang sudah ada tidak dihitung ulang.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/19_gaji_pokok_komponen.sql';

    /** Versi sp_generate_payroll_bulanan sebelum perubahan ini. */
    private const string FILE_SEBELUMNYA = 'sql/Komponen/18_finalisasi_database.sql';

    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (! Schema::hasColumn('tbl_komponen_gaji', 'gaji_pokok')) {
            DB::statement('ALTER TABLE tbl_komponen_gaji ADD COLUMN gaji_pokok DECIMAL(12,2) NULL DEFAULT NULL AFTER periode_tahun');
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 1);
        SqlKomponen::pasangHakAkses();
    }

    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        // [TAHAP 2] memasang sp_generate_payroll_bulanan lama beserta fungsi roster versi awal; [TAHAP 10] lalu
        // memasang kembali perbaikan fungsi roster yang menggantikannya, sehingga hasilnya sama dengan sebelum migration ini.
        SqlKomponen::jalankanBlok(self::FILE_SEBELUMNYA, 2);
        SqlKomponen::jalankanBlok(self::FILE_SEBELUMNYA, 10);
        DB::unprepared('DROP FUNCTION IF EXISTS fn_gaji_pokok_berlaku');

        if (Schema::hasColumn('tbl_komponen_gaji', 'gaji_pokok')) {
            DB::statement('ALTER TABLE tbl_komponen_gaji DROP COLUMN gaji_pokok');
        }

        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_komponen_gaji')
            && Schema::hasColumn('tbl_payroll', 'pph21');
    }
};

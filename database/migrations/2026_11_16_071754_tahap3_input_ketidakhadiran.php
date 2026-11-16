<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 3 (sql/Komponen/11_lengkapi_fitur.sql): procedure sp_input_ketidakhadiran untuk HRD
 * (Izin/Sakit/Alpha), beserta GRANT EXECUTE ke sji_role_hrd (file 10).
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/11_lengkapi_fitur.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/11_lengkapi_fitur_rollback.sql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 3);
        SqlKomponen::pasangHakAkses();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 3);
        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_absensi')
            && Schema::hasColumn('tbl_absensi', 'id_penempatan');
    }
};

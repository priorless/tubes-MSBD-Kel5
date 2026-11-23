<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finalisasi Tahap 10 (perbaikan dari uji akhir): fn_hari_tanpa_jadwal_klien mengabaikan baris jadwal yang berada
 * di luar masa penempatannya, sama dengan fn_hari_kerja_roster_klien (sql/Komponen/18_finalisasi_database.sql
 * [TAHAP 10]). DROP ... IF EXISTS membuat up()/down() aman diulang.
 */
return new class extends Migration
{
    private const string FILE = 'sql/Komponen/18_finalisasi_database.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/18_finalisasi_database_rollback.sql';

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
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable('tbl_jadwal_kerja')) {
            return;
        }

        SqlKomponen::jalankanBlok($file, 10);
        SqlKomponen::pasangHakAkses();
    }
};

<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 4 (sql/Komponen/11_lengkapi_fitur.sql): aturan status karyawan di trigger BEFORE UPDATE
 * dan sinkron status akun login di trigger AFTER UPDATE tbl_karyawan.
 * (Tombol "Akhiri Penempatan" memakai sp_selesai_penempatan yang sudah ada sejak file 09.)
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

        SqlKomponen::jalankanBlok(self::FILE_SQL, 4);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 4);
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_karyawan')
            && Schema::hasTable('tbl_penempatan')
            && Schema::hasTable('tbl_users');
    }
};

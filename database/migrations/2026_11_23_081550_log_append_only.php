<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tbl_log_aktivitas menjadi append-only: trigger BEFORE UPDATE/DELETE menolak perubahan dengan SIGNAL 45000
 * (sql/Komponen/19_log_append_only.sql [TAHAP 1]). DROP TRIGGER IF EXISTS membuat up()/down() aman diulang.
 */
return new class extends Migration
{
    private const string FILE = 'sql/Komponen/19_log_append_only.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/19_log_append_only_rollback.sql';

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
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable('tbl_log_aktivitas')) {
            return;
        }

        SqlKomponen::jalankanBlok($file, 1);
    }
};

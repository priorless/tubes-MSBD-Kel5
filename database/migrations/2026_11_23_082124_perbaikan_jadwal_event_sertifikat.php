<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jam mulai ev_update_status_sertifikat dikembalikan ke 00:05 setiap hari
 * (sql/Komponen/20_perbaikan_jadwal_event_sertifikat.sql [TAHAP 1]). ALTER EVENT aman diulang.
 */
return new class extends Migration
{
    private const string FILE = 'sql/Komponen/20_perbaikan_jadwal_event_sertifikat.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/20_perbaikan_jadwal_event_sertifikat_rollback.sql';

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
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! $this->eventAda()) {
            return;
        }

        SqlKomponen::jalankanBlok($file, 1);
    }

    private function eventAda(): bool
    {
        return DB::table('information_schema.EVENTS')
            ->where('EVENT_SCHEMA', DB::getDatabaseName())
            ->where('EVENT_NAME', 'ev_update_status_sertifikat')
            ->exists();
    }
};

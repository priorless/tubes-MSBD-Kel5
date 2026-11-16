<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Tahap 7 (sql/Komponen/11_lengkapi_fitur.sql): EVENT ev_update_status_sertifikat (setiap hari 00:05)
 * menandai sertifikat yang lewat tanggal kedaluwarsa sebagai 'Kadaluarsa'. Migrasi juga langsung
 * menjalankan UPDATE yang sama sekali. Bila event_scheduler OFF, migrasi hanya memberi peringatan.
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

        SqlKomponen::jalankanBlok(self::FILE_SQL, 7);

        DB::table('tbl_sertifikat')
            ->where('status', '<>', 'Kadaluarsa')
            ->whereRaw('tgl_kadaluarsa < CURDATE()')
            ->update(['status' => 'Kadaluarsa']);

        $penjadwal = strtoupper((string) DB::selectOne('SELECT @@event_scheduler AS nilai')->nilai);

        if ($penjadwal !== 'ON') {
            $pesan = 'PERINGATAN: event_scheduler MySQL sedang '.$penjadwal.'. Event ev_update_status_sertifikat sudah dibuat '
                .'tetapi tidak akan berjalan. Aktifkan dengan: SET GLOBAL event_scheduler = ON; '
                .'(dan event_scheduler=ON di my.ini agar tetap aktif setelah restart). Tampilan sertifikat tetap benar '
                .'karena status dihitung dari tanggal.';
            Log::warning($pesan);
            (new ConsoleOutput)->writeln("<comment>{$pesan}</comment>");
        }
    }

    /**
     * Reverse the migrations. Status sertifikat yang sudah diubah tidak dikembalikan.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 7);
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_sertifikat')
            && Schema::hasColumn('tbl_sertifikat', 'status');
    }
};

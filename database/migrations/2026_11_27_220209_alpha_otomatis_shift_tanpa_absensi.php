<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Alpha otomatis (sql/Komponen/24_alpha_otomatis.sql): karyawan yang tidak check-in sampai shift Kerja-nya berakhir
 * dicatat Alpha oleh event ev_tandai_alpha_otomatis (tiap 15 menit). Alpha tanpa check-in dapat diganti
 * Izin/Sakit/Cuti lewat pengajuan yang disetujui atau input ketidakhadiran HRD.
 *
 * Hanya untuk MySQL/MariaDB: tabel inti tidak dibuat di SQLite.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/24_alpha_otomatis.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/24_alpha_otomatis_rollback.sql';

    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 1);
        SqlKomponen::jalankanBlok(self::FILE_SQL, 2);
        SqlKomponen::pasangHakAkses();

        $penjadwal = strtoupper((string) DB::selectOne('SELECT @@event_scheduler AS nilai')->nilai);

        if ($penjadwal !== 'ON') {
            $pesan = 'PERINGATAN: event_scheduler MySQL sedang '.$penjadwal.'. Event ev_tandai_alpha_otomatis sudah dibuat '
                .'tetapi tidak akan berjalan sehingga Alpha otomatis tidak tercatat. Aktifkan dengan: SET GLOBAL event_scheduler = ON; '
                .'(dan event_scheduler=ON di my.ini agar tetap aktif setelah restart).';
            Log::warning($pesan);
            (new ConsoleOutput)->writeln("<comment>{$pesan}</comment>");
        }
    }

    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 2);
        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 1);
        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_jadwal_kerja')
            && Schema::hasTable('tbl_pengajuan_ketidakhadiran');
    }
};

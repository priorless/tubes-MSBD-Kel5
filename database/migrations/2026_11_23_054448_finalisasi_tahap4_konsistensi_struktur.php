<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finalisasi Tahap 4: konsistensi struktur (sql/Komponen/18_finalisasi_database.sql [TAHAP 4]).
 *
 * - Database dan tabel tbl_* yang masih utf8mb4_general_ci menjadi utf8mb4_unicode_ci.
 * - Routine, view, trigger, dan event dibuat ulang dari definisinya sendiri (sql_mode asli) agar collation
 *   yang tersimpan bersamanya ikut berubah; isi logikanya tidak diubah.
 * - FOREIGN KEY kolom audit ke tbl_users(id). tbl_log_aktivitas.id_user sengaja tanpa FK.
 */
return new class extends Migration
{
    private const string FILE = 'sql/Komponen/18_finalisasi_database.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/18_finalisasi_database_rollback.sql';

    public function up(): void
    {
        if (! $this->mysql()) {
            return;
        }

        $this->tanpaPemeriksaanFk(fn () => $this->jalankanBlokIdempoten(self::FILE));
        $this->buatUlangObjekProgram();
        SqlKomponen::pasangHakAkses();
    }

    public function down(): void
    {
        if (! $this->mysql()) {
            return;
        }

        $this->tanpaPemeriksaanFk(fn () => $this->jalankanBlokIdempoten(self::FILE_ROLLBACK));
        $this->buatUlangObjekProgram();
        SqlKomponen::pasangHakAkses();
    }

    private function mysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && Schema::hasTable('tbl_users');
    }

    /**
     * Jalankan blok [TAHAP 4]; ADD CONSTRAINT yang sudah ada dan DROP FOREIGN KEY yang sudah tidak ada dilewati,
     * sehingga migration aman diulang walau sebelumnya berhenti di tengah. CONVERT/ALTER DATABASE memang idempoten.
     */
    private function jalankanBlokIdempoten(string $file): void
    {
        foreach (SqlKomponen::blokTahap($file, 4) as $sql) {
            if (preg_match('/ADD CONSTRAINT (\w+) FOREIGN KEY/', $sql, $cocok) && $this->fkAda($cocok[1])) {
                continue;
            }
            if (preg_match('/DROP FOREIGN KEY (\w+)/', $sql, $cocok) && ! $this->fkAda($cocok[1])) {
                continue;
            }

            DB::unprepared($sql);
        }
    }

    private function fkAda(string $nama): bool
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $nama)
            ->exists();
    }

    private function tanpaPemeriksaanFk(callable $aksi): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $aksi();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Routine, view, trigger, dan event menyimpan collation database saat dibuat; buat ulang dari SHOW CREATE.
     */
    private function buatUlangObjekProgram(): void
    {
        $database = DB::getDatabaseName();
        $sqlModeAwal = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;

        try {
            foreach (DB::select('SELECT ROUTINE_NAME AS nama, ROUTINE_TYPE AS jenis FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?', [$database]) as $routine) {
                $jenis = $routine->jenis === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
                $definisi = (array) DB::selectOne("SHOW CREATE {$jenis} `{$routine->nama}`");
                $this->jalankanDenganSqlMode($definisi['sql_mode'], [
                    "DROP {$jenis} IF EXISTS `{$routine->nama}`",
                    $definisi['Create '.ucfirst(strtolower($jenis))],
                ]);
            }

            foreach (DB::select('SELECT TABLE_NAME AS nama FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$database]) as $view) {
                $definisi = (array) DB::selectOne("SHOW CREATE VIEW `{$view->nama}`");
                DB::unprepared(preg_replace('/^CREATE /', 'CREATE OR REPLACE ', $definisi['Create View']));
            }

            $trigger = DB::select('SELECT TRIGGER_NAME AS nama FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?
                ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER', [$database]);
            foreach ($trigger as $baris) {
                $definisi = (array) DB::selectOne("SHOW CREATE TRIGGER `{$baris->nama}`");
                $this->jalankanDenganSqlMode($definisi['sql_mode'], [
                    "DROP TRIGGER IF EXISTS `{$baris->nama}`",
                    $definisi['SQL Original Statement'],
                ]);
            }

            foreach (DB::select('SELECT EVENT_NAME AS nama FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?', [$database]) as $event) {
                $definisi = (array) DB::selectOne("SHOW CREATE EVENT `{$event->nama}`");
                $this->jalankanDenganSqlMode($definisi['sql_mode'], [
                    "DROP EVENT IF EXISTS `{$event->nama}`",
                    $definisi['Create Event'],
                ]);
            }
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$sqlModeAwal]);
        }
    }

    /**
     * @param  list<string>  $pernyataan
     */
    private function jalankanDenganSqlMode(string $sqlMode, array $pernyataan): void
    {
        DB::statement('SET SESSION sql_mode = ?', [$sqlMode]);

        foreach ($pernyataan as $sql) {
            DB::unprepared($sql);
        }
    }
};

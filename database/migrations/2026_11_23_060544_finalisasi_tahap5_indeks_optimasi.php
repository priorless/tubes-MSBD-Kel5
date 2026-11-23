<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finalisasi Tahap 5: indeks optimasi (sql/Komponen/18_finalisasi_database.sql [TAHAP 5]).
 *
 * tbl_penempatan (id_karyawan, status), tbl_penempatan (id_klien, status, tgl_mulai),
 * tbl_sertifikat (tgl_kadaluarsa, status). MySQL membuang indeks FK otomatis fk_pen_karyawan/fk_pen_klien yang
 * tercakup indeks komposit; down() membuatnya kembali sebelum menghapus indeks komposit.
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

    /**
     * CREATE INDEX dilewati bila indeks sudah ada, DROP INDEX dilewati bila indeks tidak ada.
     */
    private function jalankan(string $file): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable('tbl_sertifikat')) {
            return;
        }

        foreach (SqlKomponen::blokTahap($file, 5) as $sql) {
            preg_match('/INDEX (\w+) ON (\w+)/', $sql, $cocok);

            if (Schema::hasIndex($cocok[2], $cocok[1]) === str_starts_with($sql, 'CREATE')) {
                continue;
            }

            DB::unprepared($sql);
        }
    }
};

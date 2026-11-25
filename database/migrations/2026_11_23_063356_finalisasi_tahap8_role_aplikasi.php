<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finalisasi Tahap 8: role aplikasi sji_role_app (sql/Komponen/18_finalisasi_database.sql [TAHAP 8]).
 *
 * Role untuk user koneksi aplikasi (sji_app): DML + EXECUTE pada database aplikasi, tanpa DDL/GRANT.
 * Hak role dipasang dari 10_hak_akses_database.sql. Bila user database tidak punya hak CREATE ROLE
 * (shared hosting), migrasi tidak gagal, sama seperti SqlKomponen::pasangHakAkses().
 */
return new class extends Migration
{
    private const string FILE = 'sql/Komponen/18_finalisasi_database.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/18_finalisasi_database_rollback.sql';

    /** 1044/1045/1142/1227 = tidak punya hak CREATE ROLE/GRANT. */
    private const array KODE_TANPA_HAK = [1044, 1045, 1142, 1227];

    public function up(): void
    {
        if ($this->jalankan(self::FILE)) {
            SqlKomponen::pasangHakAkses();
        }
    }

    public function down(): void
    {
        $this->jalankan(self::FILE_ROLLBACK);
    }

    private function jalankan(string $file): bool
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }

        try {
            SqlKomponen::jalankanBlok($file, 8);
        } catch (QueryException $exception) {
            if (! in_array((int) ($exception->errorInfo[1] ?? 0), self::KODE_TANPA_HAK, true)) {
                throw $exception;
            }

            Log::warning('Role sji_role_app tidak dibuat/dihapus: user database tidak punya hak CREATE ROLE.', ['pesan' => $exception->getMessage()]);

            return false;
        }

        return true;
    }
};

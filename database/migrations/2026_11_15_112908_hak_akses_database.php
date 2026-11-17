
<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hak akses MySQL per bagian (sql/Komponen/10_hak_akses_database.sql):
 * ROLE + GRANT, tanpa user & password.
 *
 * Idempoten: file SQL mencabut semua hak role lalu memberinya ulang.
 * Hanya jalan di MySQL/MariaDB.
 *
 * Dijalankan lewat SqlKomponen::pasangHakAkses():
 * - bila user database tidak punya hak CREATE ROLE/GRANT, migrasi dilewati
 *   dengan peringatan di log;
 * - GRANT untuk objek yang belum dibuat dilewati, karena setiap migrasi
 *   tahap memasang ulang hak akses setelah objeknya dibuat.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const ROLE = [
        'sji_role_hrd',
        'sji_role_operasional',
        'sji_role_keuangan',
        'sji_role_ceo',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::pasangHakAkses(true);
    }

    /**
     * Reverse the migrations.
     * User yang dibuat lewat sji:buat-user-db tetap ada,
     * tetapi kehilangan role-nya.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        DB::unprepared(
            'DROP ROLE IF EXISTS '.implode(
                ', ',
                array_map(fn (string $role) => "'$role'", self::ROLE)
            )
        );
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_karyawan')
            && Schema::hasColumn('tbl_penempatan', 'jam_datang');
    }
};

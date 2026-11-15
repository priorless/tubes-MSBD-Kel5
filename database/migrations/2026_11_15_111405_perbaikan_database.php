<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perbaikan bug database lintas modul (sql/Komponen/09_perbaikan_database.sql).
 *
 * - sp_generate_payroll_bulanan: cursor tidak lagi berhenti saat gaji karyawan tidak ditemukan.
 * - sp_selesai_penempatan (baru), validasi sp_assign_penempatan & sp_rotasi_karyawan.
 * - trg_update_rating juga untuk UPDATE/DELETE, fn_profit_klien tanpa gaji ganda,
 *   tanggal buku kas tidak NULL, dan chk_rating dipastikan ada.
 *
 * Idempoten (DROP ... IF EXISTS + CREATE, constraint dicek dulu) dan hanya jalan di MySQL db_sji.
 * Tidak mengubah atau menghapus data.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/09_perbaikan_database.sql';

    private const string FILE_SQL_ROLLBACK = 'sql/Komponen/09_perbaikan_database_rollback.sql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        foreach ($this->pernyataanRutin(self::FILE_SQL) as $sql) {
            DB::unprepared($sql);
        }

        if (! $this->chkRatingAda()) {
            DB::statement('ALTER TABLE tbl_evaluasi_kinerja ADD CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)');
        }
    }

    /**
     * Reverse the migrations: kembali ke definisi routine sebelum perbaikan.
     * chk_rating tidak dihapus karena sudah menjadi bagian schema_sji.sql.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        foreach ($this->pernyataanRutin(self::FILE_SQL_ROLLBACK) as $sql) {
            DB::unprepared($sql);
        }
    }

    /** Khusus skema MySQL db_sji yang sudah memakai jadwal penempatan (procedure 8 & 7 parameter). */
    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_evaluasi_kinerja')
            && Schema::hasTable('tbl_mutasi_keuangan')
            && Schema::hasColumn('tbl_penempatan', 'jam_datang');
    }

    private function chkRatingAda(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'tbl_evaluasi_kinerja')
            ->where('CONSTRAINT_NAME', 'chk_rating')
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();
    }

    /**
     * Pernyataan di antara "DELIMITER //" dan "DELIMITER ;", dipisah per "//".
     *
     * @return list<string>
     */
    private function pernyataanRutin(string $file): array
    {
        $isi = file_get_contents(base_path($file));

        if (! preg_match('/^DELIMITER \/\/\s*$(.*?)^DELIMITER ;\s*$/ms', $isi, $cocok)) {
            throw new RuntimeException('Bagian DELIMITER // tidak ditemukan di '.$file);
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\/\/[ \t]*(?:\r?\n|$)/', $cocok[1])),
            fn (string $sql) => preg_match('/^\s*(CREATE|DROP)\b/mi', $sql) === 1
        ));
    }
};

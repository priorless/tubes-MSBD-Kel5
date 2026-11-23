<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finalisasi Tahap 7: teknologi basis data lanjutan (sql/Komponen/18_finalisasi_database.sql [TAHAP 7]).
 *
 * - JSON: kolom generated STORED tbl_jadwal_kerja_riwayat.status_sesudah (+ indeks), view v_riwayat_jadwal_kerja
 *   dan v_pertukaran_shift_snapshot (JSON_EXTRACT/JSON_UNQUOTE).
 * - Partisi tbl_log_aktivitas per tahun (RANGE UNIX_TIMESTAMP(waktu)), PRIMARY KEY (id, waktu).
 * - FULLTEXT tbl_karyawan(nama) dan tbl_klien(nama_perusahaan).
 * Setiap pernyataan dilewati bila hasilnya sudah ada (up) / sudah tidak ada (down), jadi aman diulang.
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

    private function jalankan(string $file): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable('tbl_log_aktivitas')) {
            return;
        }

        foreach (SqlKomponen::blokTahap($file, 7) as $sql) {
            if ($this->sudahSesuai($sql)) {
                continue;
            }

            DB::unprepared($sql);
        }

        SqlKomponen::pasangHakAkses();
    }

    private function sudahSesuai(string $sql): bool
    {
        return match (true) {
            str_contains($sql, 'ADD COLUMN status_sesudah') => Schema::hasColumn('tbl_jadwal_kerja_riwayat', 'status_sesudah'),
            str_contains($sql, 'DROP COLUMN status_sesudah') => ! Schema::hasColumn('tbl_jadwal_kerja_riwayat', 'status_sesudah'),
            (bool) preg_match('/ALTER TABLE (\w+) ADD (?:FULLTEXT )?INDEX (\w+)/', $sql, $cocok) => Schema::hasIndex($cocok[1], $cocok[2]),
            (bool) preg_match('/ALTER TABLE (\w+) DROP INDEX (\w+)/', $sql, $cocok) => ! Schema::hasIndex($cocok[1], $cocok[2]),
            str_contains($sql, 'ADD PRIMARY KEY (id, waktu)') => $this->jumlahKolomPrimaryLog() === 2,
            str_contains($sql, 'ADD PRIMARY KEY (id)') => $this->jumlahKolomPrimaryLog() === 1,
            str_contains($sql, 'PARTITION BY RANGE') => $this->logBerpartisi(),
            str_contains($sql, 'REMOVE PARTITIONING') => ! $this->logBerpartisi(),
            default => false,
        };
    }

    private function jumlahKolomPrimaryLog(): int
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'tbl_log_aktivitas')
            ->where('CONSTRAINT_NAME', 'PRIMARY')
            ->count();
    }

    private function logBerpartisi(): bool
    {
        return DB::table('information_schema.PARTITIONS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'tbl_log_aktivitas')
            ->whereNotNull('PARTITION_NAME')
            ->exists();
    }
};

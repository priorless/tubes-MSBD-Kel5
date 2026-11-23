<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perbaikan minimal (Option B): hanya membuat sp_selesai_penempatan untuk tombol "Akhiri Penempatan".
 *
 * Definisi procedure DIAMBIL LANGSUNG dari sql/Komponen/09_perbaikan_database.sql (bagian b), sehingga sama
 * persis dengan migration 2026_11_15_111405_perbaikan_database. Routine lain di file 09 (payroll, assign,
 * rotasi, rating, profit klien, buku kas) dan GRANT sengaja TIDAK disentuh. Tidak ada data yang diubah.
 *
 * Catatan urutan: 2026_11_15_111405_perbaikan_database juga menimpa sp_rotasi_karyawan dengan versi lama
 * (tgl_selesai = CURDATE()), lalu tahap 2 (2026_11_16_071548) dan 2026_11_16_153307_align_sp_rotasi_karyawan_contract_dates
 * memasang ulang versi sql/Komponen/12_rotasi_tanggal_kontrak.sql. Karena Laravel menjalankan migration pending
 * berurutan menurut nama file, satu kali `php artisan migrate` berakhir pada versi rotasi yang benar.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/09_perbaikan_database.sql';

    private const string PROCEDURE = 'sp_selesai_penempatan';

    private const string MIGRATION_PEMILIK = '2026_11_15_111405_perbaikan_database';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if ($this->procedureAda()) {
            return;
        }

        foreach ($this->pernyataanProcedure() as $sql) {
            DB::unprepared($sql);
        }
    }

    /**
     * Reverse the migrations. Procedure dibiarkan bila sudah menjadi milik migration file 09.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (DB::table('migrations')->where('migration', self::MIGRATION_PEMILIK)->exists()) {
            return;
        }

        DB::unprepared('DROP PROCEDURE IF EXISTS '.self::PROCEDURE);
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_penempatan')
            && Schema::hasTable('tbl_kontrak')
            && Schema::hasTable('tbl_karyawan');
    }

    private function procedureAda(): bool
    {
        return DB::table('information_schema.ROUTINES')
            ->where('ROUTINE_SCHEMA', DB::getDatabaseName())
            ->where('ROUTINE_TYPE', 'PROCEDURE')
            ->where('ROUTINE_NAME', self::PROCEDURE)
            ->exists();
    }

    /**
     * DROP + CREATE sp_selesai_penempatan dari bagian "DELIMITER //" … "DELIMITER ;" file 09.
     * Dihentikan sebelum menjalankan apa pun bila jumlahnya bukan tepat dua pernyataan.
     *
     * @return list<string>
     */
    private function pernyataanProcedure(): array
    {
        $isi = (string) file_get_contents(base_path(self::FILE_SQL));

        if (! preg_match('/^DELIMITER \/\/\s*$(.*?)^DELIMITER ;\s*$/ms', $isi, $cocok)) {
            throw new RuntimeException('Blok DELIMITER tidak ditemukan di '.self::FILE_SQL);
        }

        $pernyataan = array_values(array_filter(
            array_map('trim', preg_split('/\/\/[ \t]*(?:\r?\n|$)/', $cocok[1])),
            static fn (string $sql): bool => preg_match('/^(?:--[^\n]*\n\s*)*(DROP|CREATE)\s+PROCEDURE\s+(IF\s+EXISTS\s+)?'.self::PROCEDURE.'\b/i', $sql) === 1,
        ));

        if (count($pernyataan) !== 2) {
            throw new RuntimeException('Definisi '.self::PROCEDURE.' di '.self::FILE_SQL.' tidak lengkap; migration dihentikan.');
        }

        return array_map(static fn (string $sql): string => (string) preg_replace('/^(?:--[^\n]*\n\s*)+/', '', $sql), $pernyataan);
    }
};

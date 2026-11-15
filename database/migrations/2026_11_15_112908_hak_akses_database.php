<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Hak akses MySQL per bagian (sql/Komponen/10_hak_akses_database.sql): ROLE + GRANT, tanpa user & password.
 *
 * Idempoten: file SQL mencabut semua hak role lalu memberinya ulang. Hanya jalan di MySQL.
 * Bila user database tidak punya hak CREATE ROLE/GRANT (umum di shared hosting), migrasi dilewati
 * dengan peringatan di log agar deploy tidak gagal; jalankan file SQL-nya manual oleh admin database.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/10_hak_akses_database.sql';

    /** @var list<string> */
    private const array ROLE = ['sji_role_hrd', 'sji_role_operasional', 'sji_role_keuangan', 'sji_role_ceo'];

    /** 1044/1045/1142/1227 = akses ditolak / butuh hak lebih tinggi. */
    private const array KODE_TANPA_HAK = [1044, 1045, 1142, 1227];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        try {
            foreach ($this->pernyataan() as $sql) {
                DB::unprepared($sql);
            }
        } catch (QueryException $exception) {
            if (! in_array((int) ($exception->errorInfo[1] ?? 0), self::KODE_TANPA_HAK, true)) {
                throw $exception;
            }

            Log::warning('Hak akses database tidak dipasang: user database tidak punya hak CREATE ROLE/GRANT. '
                .'Jalankan '.self::FILE_SQL.' secara manual sebagai admin database.', ['pesan' => $exception->getMessage()]);
        }
    }

    /**
     * Reverse the migrations. User yang dibuat lewat sji:buat-user-db tetap ada, tetapi kehilangan role-nya.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        DB::unprepared('DROP ROLE IF EXISTS '.implode(', ', array_map(fn (string $role) => "'$role'", self::ROLE)));
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_karyawan')
            && Schema::hasColumn('tbl_penempatan', 'jam_datang');
    }

    /**
     * Pernyataan SQL dari file (komentar "--" dibuang, dipisah per ";" di akhir pernyataan).
     *
     * @return list<string>
     */
    private function pernyataan(): array
    {
        $isi = preg_replace('/^\s*--.*$/m', '', file_get_contents(base_path(self::FILE_SQL)));

        return array_values(array_filter(
            array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $isi)),
            fn (string $sql) => $sql !== ''
        ));
    }
};

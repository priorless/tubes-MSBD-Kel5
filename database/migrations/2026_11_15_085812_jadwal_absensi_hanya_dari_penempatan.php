<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal kerja absensi HANYA dari tbl_penempatan.jam_datang / jam_pulang.
 *
 * Memasang function/trigger/view dari BAGIAN B sql/Komponen/08_komponen_orang4_jadwal_penempatan.sql
 * (tanpa fallback tbl_jenis_layanan, tanpa jadwal bawaan 8 jam untuk lembur), lalu membuang
 * tbl_jenis_layanan.jam_masuk / jam_keluar yang hanya dibuat migrasi geofence 2026_11_14_161048.
 * Data absensi yang sudah ada tidak dihitung ulang.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/08_komponen_orang4_jadwal_penempatan.sql';

    private const string FILE_SQL_SEBELUMNYA = 'sql/Komponen/07_komponen_orang4_absensi_foto.sql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaAbsensiFotoTersedia()) {
            return;
        }

        // Routine baru dipasang dulu agar tidak ada view/trigger yang masih merujuk kolom jadwal jenis layanan.
        foreach ($this->pernyataanRutin(self::FILE_SQL) as $sql) {
            DB::unprepared($sql);
        }

        $kolomJadwal = array_values(array_filter(
            ['jam_masuk', 'jam_keluar'],
            fn (string $kolom) => Schema::hasColumn('tbl_jenis_layanan', $kolom)
        ));
        if ($kolomJadwal !== []) {
            Schema::table('tbl_jenis_layanan', fn (Blueprint $table) => $table->dropColumn($kolomJadwal));
        }
    }

    /**
     * Reverse the migrations: kembali ke jadwal penempatan + fallback jenis layanan (file 07).
     */
    public function down(): void
    {
        if (! $this->skemaAbsensiFotoTersedia()) {
            return;
        }

        if (! Schema::hasColumn('tbl_jenis_layanan', 'jam_masuk')) {
            Schema::table('tbl_jenis_layanan', function (Blueprint $table) {
                $table->time('jam_masuk')->default('08:00:00');
                $table->time('jam_keluar')->default('16:00:00');
            });
            DB::table('tbl_jenis_layanan')->whereIn('nama_layanan', ['Satpam', 'Cleaning Service'])
                ->update(['jam_masuk' => '07:00:00', 'jam_keluar' => '15:00:00']);
        }

        foreach ($this->pernyataanRutin(self::FILE_SQL_SEBELUMNYA) as $sql) {
            DB::unprepared($sql);
        }
    }

    /** Khusus skema MySQL db_sji yang sudah memakai absensi foto (migrasi 2026_11_15_064609). */
    private function skemaAbsensiFotoTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_jenis_layanan')
            && Schema::hasColumn('tbl_penempatan', 'jam_datang')
            && Schema::hasColumn('tbl_absensi', 'foto_masuk');
    }

    /**
     * Pernyataan BAGIAN B (antara "DELIMITER //" dan "DELIMITER ;"), dipisah per "//".
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

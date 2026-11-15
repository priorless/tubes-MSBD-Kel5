<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Absensi Tenaga Kerja beralih dari GPS/geofence ke foto berwatermark.
 *
 * Migrasi geofence 2026_11_14_161048 sengaja DIPERTAHANKAN; migrasi ini membuang kolom
 * GPS/geofence yang tidak dipakai lagi (hanya dipakai trigger & view modul absensi itu sendiri),
 * menambah kolom foto, lalu memasang trigger/view baru dari BAGIAN B
 * sql/Komponen/07_komponen_orang4_absensi_foto.sql.
 *
 * Jadwal keterlambatan memakai tbl_penempatan.jam_datang/jam_pulang (migrasi 2026_11_15_052355)
 * dengan fallback tbl_jenis_layanan.jam_masuk/jam_keluar — kolom itu tetap dipertahankan.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/07_komponen_orang4_absensi_foto.sql';

    private const string FILE_SQL_GEOFENCE = 'sql/Komponen/06_komponen_orang4_lanjutan.sql';

    /** @var list<string> */
    private const array KOLOM_GPS_ABSENSI = [
        'lat_masuk', 'lng_masuk', 'akurasi_masuk', 'jarak_masuk_meter', 'status_lokasi_masuk', 'alasan_lokasi_masuk',
        'lat_keluar', 'lng_keluar', 'akurasi_keluar', 'jarak_keluar_meter', 'status_lokasi_keluar', 'alasan_lokasi_keluar',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaSjiTersedia()) {
            return;
        }

        // Trigger, view, dan function lama merujuk kolom GPS — dilepas sebelum kolomnya dihapus.
        DB::unprepared('DROP TRIGGER IF EXISTS trg_validasi_absen_masuk');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_auto_hitung_lembur');
        DB::unprepared('DROP VIEW IF EXISTS v_penugasan_aktif');
        DB::unprepared('DROP VIEW IF EXISTS v_absensi_harian');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_jarak_meter');

        $kolomGps = array_values(array_filter(self::KOLOM_GPS_ABSENSI, fn (string $kolom) => Schema::hasColumn('tbl_absensi', $kolom)));
        if ($kolomGps !== []) {
            Schema::table('tbl_absensi', fn (Blueprint $table) => $table->dropColumn($kolomGps));
        }

        Schema::table('tbl_absensi', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_absensi', 'foto_masuk')) {
                $table->string('foto_masuk')->nullable();
            }
            if (! Schema::hasColumn('tbl_absensi', 'foto_keluar')) {
                $table->string('foto_keluar')->nullable();
            }
        });

        if (Schema::hasColumn('tbl_klien', 'latitude')) {
            foreach (['chk_klien_koordinat', 'chk_klien_radius'] as $constraint) {
                if ($this->checkConstraintAda($constraint)) {
                    DB::statement("ALTER TABLE tbl_klien DROP CHECK $constraint");
                }
            }
            Schema::table('tbl_klien', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude', 'radius_meter']));
        }

        $kolomLayanan = array_values(array_filter(
            ['toleransi_terlambat_menit', 'mode_lokasi'],
            fn (string $kolom) => Schema::hasColumn('tbl_jenis_layanan', $kolom)
        ));
        if ($kolomLayanan !== []) {
            Schema::table('tbl_jenis_layanan', fn (Blueprint $table) => $table->dropColumn($kolomLayanan));
        }

        foreach ($this->pernyataanRutin(self::FILE_SQL) as $sql) {
            DB::unprepared($sql);
        }
    }

    /**
     * Reverse the migrations: kembali ke skema geofence (migrasi 2026_11_14_161048).
     */
    public function down(): void
    {
        if (! $this->skemaSjiTersedia()) {
            return;
        }

        if (! Schema::hasColumn('tbl_jenis_layanan', 'mode_lokasi')) {
            Schema::table('tbl_jenis_layanan', function (Blueprint $table) {
                $table->unsignedSmallInteger('toleransi_terlambat_menit')->default(30);
                $table->enum('mode_lokasi', ['Tetap', 'Bergerak'])->default('Tetap');
            });
            DB::table('tbl_jenis_layanan')->where('nama_layanan', 'Driver')->update(['mode_lokasi' => 'Bergerak']);
        }

        if (! Schema::hasColumn('tbl_klien', 'latitude')) {
            Schema::table('tbl_klien', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->unsignedSmallInteger('radius_meter')->default(200);
            });
            DB::statement('ALTER TABLE tbl_klien
                ADD CONSTRAINT chk_klien_koordinat CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180),
                ADD CONSTRAINT chk_klien_radius CHECK (radius_meter BETWEEN 25 AND 5000)');
        }

        if (! Schema::hasColumn('tbl_absensi', 'lat_masuk')) {
            Schema::table('tbl_absensi', function (Blueprint $table) {
                foreach (['masuk', 'keluar'] as $jenis) {
                    $table->decimal("lat_$jenis", 10, 7)->nullable();
                    $table->decimal("lng_$jenis", 10, 7)->nullable();
                    $table->unsignedInteger("akurasi_$jenis")->nullable();
                    $table->unsignedInteger("jarak_{$jenis}_meter")->nullable();
                    $table->enum("status_lokasi_$jenis", $jenis === 'masuk'
                        ? ['Di Area', 'Luar Area', 'Tanpa Titik']
                        : ['Di Area', 'Luar Area', 'Tanpa Titik', 'Bebas'])->nullable();
                    $table->string("alasan_lokasi_$jenis")->nullable();
                }
            });
        }

        // Pasang kembali trigger/view/function versi geofence (menimpa versi foto).
        foreach ($this->pernyataanRutin(self::FILE_SQL_GEOFENCE) as $sql) {
            DB::unprepared($sql);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS fn_jadwal_absensi');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_menit_terlambat');

        $kolomFoto = array_values(array_filter(['foto_masuk', 'foto_keluar'], fn (string $kolom) => Schema::hasColumn('tbl_absensi', $kolom)));
        if ($kolomFoto !== []) {
            Schema::table('tbl_absensi', fn (Blueprint $table) => $table->dropColumn($kolomFoto));
        }
    }

    /** Migrasi ini khusus skema MySQL db_sji (trigger & function tidak ada di SQLite pengujian). */
    private function skemaSjiTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_absensi')
            && Schema::hasTable('tbl_klien')
            && Schema::hasTable('tbl_jenis_layanan')
            && Schema::hasColumn('tbl_penempatan', 'jam_datang');
    }

    private function checkConstraintAda(string $nama): bool
    {
        return DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $nama)
            ->exists();
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengembangan absensi modul Tenaga Kerja (Orang 4):
 * lokasi GPS disimpan, penandaan absensi di luar area kerja klien (wajib alasan),
 * jadwal shift per jenis layanan, status terlambat otomatis, minimal durasi kerja,
 * dan shift lewat tengah malam. Tidak ada tabel baru — hanya kolom tambahan.
 *
 * Struktur kolom ditambahkan di sini secara idempoten (aman dijalankan pada database
 * yang dibuat dari sql/schema_sji.sql). Function, trigger, dan view diambil dari
 * BAGIAN B sql/Komponen/06_komponen_orang4_lanjutan.sql agar hanya ada satu sumber.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/06_komponen_orang4_lanjutan.sql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaSjiTersedia()) {
            return;
        }

        Schema::table('tbl_jenis_layanan', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_jenis_layanan', 'jam_masuk')) {
                $table->time('jam_masuk')->default('08:00:00');
                $table->time('jam_keluar')->default('16:00:00');
                $table->unsignedSmallInteger('toleransi_terlambat_menit')->default(30);
                $table->enum('mode_lokasi', ['Tetap', 'Bergerak'])->default('Tetap');
            }
        });

        DB::table('tbl_jenis_layanan')->whereIn('nama_layanan', ['Satpam', 'Cleaning Service'])
            ->update(['jam_masuk' => '07:00:00', 'jam_keluar' => '15:00:00', 'toleransi_terlambat_menit' => 30]);
        DB::table('tbl_jenis_layanan')->where('nama_layanan', 'Driver')
            ->update(['jam_masuk' => '08:00:00', 'jam_keluar' => '16:00:00', 'toleransi_terlambat_menit' => 30, 'mode_lokasi' => 'Bergerak']);

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
                $table->integer('id_penempatan')->nullable()->after('id_karyawan');
                $table->unsignedSmallInteger('menit_terlambat')->default(0)->after('status_kehadiran');
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
                $table->foreign('id_penempatan', 'fk_absensi_penempatan')->references('id')->on('tbl_penempatan');
            });
        }

        foreach ($this->pernyataanRutin() as $sql) {
            DB::unprepared($sql);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->skemaSjiTersedia()) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_validasi_absen_masuk');
        DB::unprepared('DROP VIEW IF EXISTS v_penugasan_aktif');
        DB::unprepared('DROP VIEW IF EXISTS v_absensi_harian');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_id_absensi_aktif');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_jarak_meter');

        // Kembalikan trigger, function, dan view ke versi 04_komponen_orang4.sql
        DB::unprepared('DROP TRIGGER IF EXISTS trg_auto_hitung_lembur');
        DB::unprepared('CREATE TRIGGER trg_auto_hitung_lembur BEFORE UPDATE ON tbl_absensi FOR EACH ROW
            BEGIN
              IF NEW.jam_keluar IS NOT NULL AND OLD.jam_keluar IS NULL THEN
                SET NEW.jam_lembur = GREATEST(0, TIMESTAMPDIFF(HOUR, NEW.jam_masuk, NEW.jam_keluar) - 8);
              END IF;
            END');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_hitung_jam_kerja');
        DB::unprepared('CREATE FUNCTION fn_hitung_jam_kerja(p_jam_masuk TIME, p_jam_keluar TIME) RETURNS INT DETERMINISTIC
            BEGIN
              RETURN GREATEST(0, TIMESTAMPDIFF(HOUR, p_jam_masuk, p_jam_keluar));
            END');
        DB::unprepared("CREATE OR REPLACE VIEW v_rekap_absensi_pribadi AS
            SELECT k.id AS id_karyawan, k.nama, MONTH(a.tgl) AS bulan, YEAR(a.tgl) AS tahun,
                   COUNT(*) AS total_baris,
                   SUM(a.status_kehadiran = 'Hadir') AS hari_hadir,
                   SUM(a.status_kehadiran = 'Terlambat') AS hari_terlambat,
                   SUM(a.status_kehadiran IN ('Izin','Sakit','Alpha')) AS hari_tidak_hadir,
                   SUM(a.jam_lembur) AS total_jam_lembur
            FROM tbl_absensi a JOIN tbl_karyawan k ON a.id_karyawan = k.id
            GROUP BY k.id, k.nama, MONTH(a.tgl), YEAR(a.tgl)");

        if (Schema::hasColumn('tbl_absensi', 'lat_masuk')) {
            Schema::table('tbl_absensi', function (Blueprint $table) {
                $table->dropForeign('fk_absensi_penempatan');
                $table->dropColumn([
                    'id_penempatan', 'menit_terlambat',
                    'lat_masuk', 'lng_masuk', 'akurasi_masuk', 'jarak_masuk_meter', 'status_lokasi_masuk', 'alasan_lokasi_masuk',
                    'lat_keluar', 'lng_keluar', 'akurasi_keluar', 'jarak_keluar_meter', 'status_lokasi_keluar', 'alasan_lokasi_keluar',
                ]);
            });
        }

        if (Schema::hasColumn('tbl_klien', 'latitude')) {
            DB::statement('ALTER TABLE tbl_klien DROP CHECK chk_klien_koordinat, DROP CHECK chk_klien_radius');
            Schema::table('tbl_klien', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude', 'radius_meter']));
        }

        if (Schema::hasColumn('tbl_jenis_layanan', 'jam_masuk')) {
            Schema::table('tbl_jenis_layanan', fn (Blueprint $table) => $table->dropColumn([
                'jam_masuk', 'jam_keluar', 'toleransi_terlambat_menit', 'mode_lokasi',
            ]));
        }
    }

    /** Migrasi ini khusus skema MySQL db_sji (trigger & function tidak ada di SQLite pengujian). */
    private function skemaSjiTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_absensi')
            && Schema::hasTable('tbl_klien')
            && Schema::hasTable('tbl_jenis_layanan');
    }

    /**
     * Pernyataan BAGIAN B (antara "DELIMITER //" dan "DELIMITER ;"), dipisah per "//".
     *
     * @return list<string>
     */
    private function pernyataanRutin(): array
    {
        $isi = file_get_contents(base_path(self::FILE_SQL));

        if (! preg_match('/^DELIMITER \/\/\s*$(.*?)^DELIMITER ;\s*$/ms', $isi, $cocok)) {
            throw new RuntimeException('Bagian DELIMITER // tidak ditemukan di '.self::FILE_SQL);
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\/\/[ \t]*(?:\r?\n|$)/', $cocok[1])),
            fn (string $sql) => preg_match('/^\s*(CREATE|DROP)\b/mi', $sql) === 1
        ));
    }
};

<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 2 (sql/Komponen/11_lengkapi_fitur.sql): bersihkan kolom/tabel yang tidak terpakai.
 *
 * - tbl_absensi.foto_checkin (sisa migrasi 2026_11_15_000000; absensi memakai foto_masuk/foto_keluar).
 * - Kolom tunjangan di tbl_karyawan, tbl_kontrak, tbl_payroll (gaji tanpa tunjangan sejak 2026_11_15_071700);
 *   sp_assign_penempatan & sp_rotasi_karyawan tidak lagi menulis tunjangan.
 * - Tabel users bawaan Laravel (login memakai tbl_users lewat App\Models\User).
 *
 * Semua nilai tunjangan & foto_checkin bernilai 0/NULL saat migrasi dibuat; tabel users hanya berisi
 * "Test User" dari seeder bawaan. down() mengembalikan struktur (tanpa data yang sudah dihapus).
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/11_lengkapi_fitur.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/11_lengkapi_fitur_rollback.sql';

    /** @var list<string> */
    private const array TABEL_TUNJANGAN = ['tbl_karyawan', 'tbl_kontrak', 'tbl_payroll'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        // Procedure tanpa tunjangan dipasang dulu agar tidak ada routine yang menulis kolom yang dihapus.
        SqlKomponen::jalankanBlok(self::FILE_SQL, 2);

        if (Schema::hasColumn('tbl_absensi', 'foto_checkin')) {
            Schema::table('tbl_absensi', fn (Blueprint $table) => $table->dropColumn('foto_checkin'));
        }

        foreach (self::TABEL_TUNJANGAN as $tabel) {
            if (Schema::hasColumn($tabel, 'tunjangan')) {
                Schema::table($tabel, fn (Blueprint $table) => $table->dropColumn('tunjangan'));
            }
        }

        Schema::dropIfExists('users');

        SqlKomponen::pasangHakAkses();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (! Schema::hasColumn('tbl_absensi', 'foto_checkin')) {
            DB::statement('ALTER TABLE tbl_absensi ADD COLUMN foto_checkin VARCHAR(255) NULL AFTER keterangan');
        }

        if (! Schema::hasColumn('tbl_karyawan', 'tunjangan')) {
            DB::statement('ALTER TABLE tbl_karyawan ADD COLUMN tunjangan DECIMAL(12,2) NULL DEFAULT 0.00 AFTER gaji_pokok');
        }

        foreach (['tbl_kontrak', 'tbl_payroll'] as $tabel) {
            if (! Schema::hasColumn($tabel, 'tunjangan')) {
                DB::statement("ALTER TABLE {$tabel} ADD COLUMN tunjangan DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER gaji_pokok");
            }
        }

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 2);
        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_karyawan')
            && Schema::hasTable('tbl_kontrak')
            && Schema::hasTable('tbl_payroll');
    }
};

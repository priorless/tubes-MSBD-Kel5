<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 6 (sql/Komponen/11_lengkapi_fitur.sql): persetujuan lembur.
 *
 * - Kolom tbl_absensi.status_lembur ('Tidak Ada','Menunggu','Disetujui','Ditolak'); data lama dengan
 *   jam_lembur > 0 menjadi 'Menunggu'.
 * - trg_auto_hitung_lembur mengisi status_lembur saat check-out; sp_putuskan_lembur untuk HRD.
 * - sp_generate_payroll_bulanan hanya menghitung lembur yang Disetujui; v_absensi_harian memuat status_lembur.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/11_lengkapi_fitur.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/11_lengkapi_fitur_rollback.sql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (! Schema::hasColumn('tbl_absensi', 'status_lembur')) {
            DB::statement("ALTER TABLE tbl_absensi ADD COLUMN status_lembur ENUM('Tidak Ada','Menunggu','Disetujui','Ditolak') NOT NULL DEFAULT 'Tidak Ada' AFTER jam_lembur");
            // UPDATE ini melewati trg_auto_hitung_lembur karena jam_keluar tidak berubah.
            DB::table('tbl_absensi')->where('jam_lembur', '>', 0)->update(['status_lembur' => 'Menunggu']);
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 6);
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

        // Trigger, procedure, dan view lama dipasang dulu agar tidak ada yang merujuk kolom yang dihapus.
        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 6);

        if (Schema::hasColumn('tbl_absensi', 'status_lembur')) {
            DB::statement('ALTER TABLE tbl_absensi DROP COLUMN status_lembur');
        }

        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_absensi')
            && Schema::hasColumn('tbl_absensi', 'jam_lembur')
            && Schema::hasColumn('tbl_payroll', 'hari_wajib');
    }
};

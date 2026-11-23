<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finalisasi Tahap 1: hapus tuntas persetujuan lembur (sql/Komponen/18_finalisasi_database.sql [TAHAP 1]).
 *
 * - Hapus sp_putuskan_lembur dan kolom tbl_absensi.status_lembur.
 * - trg_auto_hitung_lembur tetap menghitung jam_lembur, tanpa mengisi status.
 * - v_absensi_harian tanpa kolom status_lembur.
 * Payroll sudah membayar semua lembur valid, jadi tidak ada rumus yang berubah.
 */
return new class extends Migration
{
    private const string FILE = 'sql/Komponen/18_finalisasi_database.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/18_finalisasi_database_rollback.sql';

    public function up(): void
    {
        if (! $this->mysql() || ! Schema::hasColumn('tbl_absensi', 'status_lembur')) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE, 1);
        SqlKomponen::pasangHakAkses();
    }

    public function down(): void
    {
        if (! $this->mysql() || ! Schema::hasTable('tbl_absensi') || Schema::hasColumn('tbl_absensi', 'status_lembur')) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 1);
        SqlKomponen::pasangHakAkses();
    }

    private function mysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};

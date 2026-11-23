<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finalisasi Tahap 3: aturan jadwal kerja di level database (sql/Komponen/18_finalisasi_database.sql [TAHAP 3]).
 *
 * Trigger BEFORE INSERT/UPDATE tbl_jadwal_kerja (lewat sp_cek_jadwal_kerja) menolak dengan SQLSTATE 45000 bila
 * tanggal di luar masa penempatan, jadwal Kerja tanpa jam, atau jam Kerja bertabrakan dengan jadwal Kerja karyawan
 * yang sama di penempatan lain (termasuk shift lewat tengah malam). Riwayat tetap ditulis JadwalKerjaService.
 */
return new class extends Migration
{
    private const string FILE = 'sql/Komponen/18_finalisasi_database.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/18_finalisasi_database_rollback.sql';

    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE, 3);
        SqlKomponen::pasangHakAkses();
    }

    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 3);
        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_jadwal_kerja')
            && Schema::hasTable('tbl_penempatan');
    }
};

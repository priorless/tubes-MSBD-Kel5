<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finalisasi Tahap 2: payroll & invoice membaca roster (sql/Komponen/18_finalisasi_database.sql [TAHAP 2]).
 *
 * - Fungsi baru fn_hari_kerja_roster, fn_hari_tanpa_jadwal (+ varian _klien), fn_status_roster,
 *   fn_hari_kerja_kontrak, fn_hari_wajib_roster, fn_total_hari_wajib_roster.
 * - sp_generate_payroll_bulanan: hari_wajib dari roster (fallback prorata untuk hari tanpa jadwal); kerja di hari
 *   Libur dibayar lewat upah_backup dan tidak dihitung hari masuk biasa.
 * - sp_generate_invoice_klien: porsi per klien dari hari wajib roster. Rumus fee/PPN tidak berubah.
 * Setiap pernyataan diawali DROP ... IF EXISTS, jadi up() aman dijalankan ulang.
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

        SqlKomponen::jalankanBlok(self::FILE, 2);
        SqlKomponen::pasangHakAkses();
    }

    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 2);
        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_jadwal_kerja')
            && Schema::hasColumn('tbl_payroll', 'upah_backup');
    }
};

<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 5 (sql/Komponen/11_lengkapi_fitur.sql): payroll & invoice prorata berdasarkan hari penempatan.
 *
 * - Kolom tbl_payroll.hari_wajib, hari_masuk, hari_absen (NULL untuk payroll lama).
 * - fn_hari_penempatan (baru), sp_generate_payroll_bulanan, sp_generate_invoice_klien, fn_profit_klien
 *   ditulis ulang; v_slip_gaji_detail memuat rincian hari; v_dashboard_eksekutif memakai rentang tanggal.
 * Payroll/invoice yang sudah ada TIDAK dihitung ulang.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/11_lengkapi_fitur.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/11_lengkapi_fitur_rollback.sql';

    /** @var list<string> */
    private const array KOLOM_HARI = ['hari_wajib', 'hari_masuk', 'hari_absen'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        $sebelumnya = 'potongan_absen';
        foreach (self::KOLOM_HARI as $kolom) {
            if (! Schema::hasColumn('tbl_payroll', $kolom)) {
                DB::statement("ALTER TABLE tbl_payroll ADD COLUMN {$kolom} SMALLINT UNSIGNED NULL AFTER {$sebelumnya}");
            }
            $sebelumnya = $kolom;
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 5);
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

        // View lama dipasang dulu agar tidak ada view yang merujuk kolom yang dihapus.
        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 5);

        $kolomAda = array_values(array_filter(self::KOLOM_HARI, fn (string $kolom) => Schema::hasColumn('tbl_payroll', $kolom)));
        if ($kolomAda !== []) {
            DB::statement('ALTER TABLE tbl_payroll '.implode(', ', array_map(fn (string $kolom) => "DROP COLUMN {$kolom}", $kolomAda)));
        }

        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_payroll')
            && Schema::hasTable('tbl_penempatan')
            && Schema::hasColumn('tbl_absensi', 'id_penempatan');
    }
};

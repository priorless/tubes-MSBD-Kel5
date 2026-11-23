<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menggabungkan komponen gaji manual (2026_11_18_000001) dengan BPJS klien (2026_11_18_041642).
 *
 * - Generate Payroll & v_slip_gaji_detail mengikuti aturan halaman Komponen Gaji
 *   (sql/Komponen/15_komponen_gaji_bpjs_klien.sql): tunjangan/pinjaman dari tbl_komponen_gaji,
 *   BPJS = persentase klien x gaji pokok, lembur per absensi valid (jam pertama 1,5x, berikutnya 2x,
 *   upah per jam = gaji pokok / 173).
 * - Potongan BPJS tidak lagi diinput manual: kolom tbl_komponen_gaji.potongan_bpjs dihapus.
 *   Payroll yang sudah dibuat tidak dihitung ulang.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/15_komponen_gaji_bpjs_klien.sql';

    private const string FILE_BPJS = 'sql/Komponen/14_bpjs_klien.sql';

    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 1);

        if (Schema::hasColumn('tbl_komponen_gaji', 'potongan_bpjs')) {
            DB::statement('ALTER TABLE tbl_komponen_gaji DROP COLUMN potongan_bpjs');
        }

        SqlKomponen::pasangHakAkses();
    }

    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (! Schema::hasColumn('tbl_komponen_gaji', 'potongan_bpjs')) {
            DB::statement('ALTER TABLE tbl_komponen_gaji ADD COLUMN potongan_bpjs DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tunjangan');
        }

        SqlKomponen::jalankanBlok(self::FILE_BPJS, 1);
        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_komponen_gaji')
            && Schema::hasColumn('tbl_payroll', 'komponen_tunjangan')
            && Schema::hasColumn('tbl_payroll', 'bpjs_metode');
    }
};

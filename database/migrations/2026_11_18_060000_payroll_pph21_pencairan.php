<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll: PPh 21 otomatis, upah back-up, dan pencairan tanpa Mutasi Kas (sql/Komponen/16_payroll_pph21_pencairan.sql).
 *
 * - tbl_payroll: + upah_backup, + pph21 (DECIMAL(12,2) NOT NULL DEFAULT 0). Payroll lama tidak dihitung ulang.
 * - status_bayar: ENUM('Belum Dibayar','Sudah Dibayar') -> ENUM('Belum Dibayar','Sudah Lunas'); data
 *   'Sudah Dibayar' dikonversi menjadi 'Sudah Lunas'.
 * - Trigger pencairan payroll & pelunasan invoice tidak lagi menulis tbl_mutasi_keuangan (log aktivitas tetap).
 *   Data Mutasi Kas yang sudah ada tidak dihapus.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/16_payroll_pph21_pencairan.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/16_payroll_pph21_pencairan_rollback.sql';

    private const string FILE_SEBELUMNYA = 'sql/Komponen/15_komponen_gaji_bpjs_klien.sql';

    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (! Schema::hasColumn('tbl_payroll', 'upah_backup')) {
            DB::statement('ALTER TABLE tbl_payroll ADD COLUMN upah_backup DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER komponen_tunjangan');
        }

        if (! Schema::hasColumn('tbl_payroll', 'pph21')) {
            DB::statement('ALTER TABLE tbl_payroll ADD COLUMN pph21 DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER potongan_pinjaman');
        }

        // Routine & trigger baru dipasang lebih dulu agar konversi status tidak menulis Mutasi Kas.
        DB::statement("ALTER TABLE tbl_payroll MODIFY status_bayar ENUM('Belum Dibayar','Sudah Dibayar','Sudah Lunas') NOT NULL DEFAULT 'Belum Dibayar'");
        SqlKomponen::jalankanBlok(self::FILE_SQL, 1);
        DB::table('tbl_payroll')->where('status_bayar', 'Sudah Dibayar')->update(['status_bayar' => 'Sudah Lunas']);
        DB::statement("ALTER TABLE tbl_payroll MODIFY status_bayar ENUM('Belum Dibayar','Sudah Lunas') NOT NULL DEFAULT 'Belum Dibayar'");

        SqlKomponen::pasangHakAkses();
    }

    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        DB::statement("ALTER TABLE tbl_payroll MODIFY status_bayar ENUM('Belum Dibayar','Sudah Dibayar','Sudah Lunas') NOT NULL DEFAULT 'Belum Dibayar'");
        // Trigger tanpa Mutasi Kas masih aktif saat konversi, sehingga tidak ada Mutasi Kas yang tercatat ulang.
        DB::table('tbl_payroll')->where('status_bayar', 'Sudah Lunas')->update(['status_bayar' => 'Sudah Dibayar']);
        DB::statement("ALTER TABLE tbl_payroll MODIFY status_bayar ENUM('Belum Dibayar','Sudah Dibayar') NOT NULL DEFAULT 'Belum Dibayar'");

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 1);
        SqlKomponen::jalankanBlok(self::FILE_SEBELUMNYA, 1);

        foreach (['pph21', 'upah_backup'] as $kolom) {
            if (Schema::hasColumn('tbl_payroll', $kolom)) {
                DB::statement("ALTER TABLE tbl_payroll DROP COLUMN {$kolom}");
            }
        }

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

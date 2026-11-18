<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan default BPJS per klien (sql/Komponen/14_bpjs_klien.sql). Satu BPJS gabungan.
 *
 * - tbl_klien.bpjs_metode ENUM('Persentase','Nominal') NULL + bpjs_nilai DECIMAL(12,2) NULL.
 *   NULL keduanya = belum ditentukan / tanpa BPJS. chk_klien_bpjs: persentase 0 < x <= 100, nominal > 0.
 * - tbl_payroll: id_klien_bpjs, bpjs_metode, bpjs_nilai (snapshot saat payroll dibuat), potongan_bpjs.
 * - tbl_invoice: bpjs_metode, bpjs_nilai (snapshot), biaya_bpjs.
 * - sp_generate_payroll_bulanan, sp_generate_invoice_klien, v_slip_gaji_detail ditulis ulang.
 *
 * Payroll/invoice yang sudah ada TIDAK dihitung ulang (potongan_bpjs/biaya_bpjs = 0, snapshot NULL).
 * Perubahan pengaturan klien di kemudian hari tidak mengubah payroll/invoice yang sudah dibuat.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/14_bpjs_klien.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/14_bpjs_klien_rollback.sql';

    private const string CONSTRAINT_KLIEN = 'chk_klien_bpjs';

    private const string FK_PAYROLL = 'fk_payroll_klien_bpjs';

    private const string INDEKS_PAYROLL = 'idx_payroll_klien_bpjs_periode';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        $setelahKolom = Schema::hasColumn('tbl_klien', 'management_fee') ? 'management_fee' : 'pic_email';

        if (! Schema::hasColumn('tbl_klien', 'bpjs_metode')) {
            DB::statement("ALTER TABLE tbl_klien
                ADD COLUMN bpjs_metode ENUM('Persentase','Nominal') NULL DEFAULT NULL
                    COMMENT 'Metode BPJS default; NULL = belum ditentukan / tanpa BPJS' AFTER {$setelahKolom},
                ADD COLUMN bpjs_nilai DECIMAL(12,2) NULL DEFAULT NULL
                    COMMENT 'Persen (0-100) atau nominal rupiah per tenaga' AFTER bpjs_metode");
        }

        if (! SqlKomponen::constraintAda('tbl_klien', self::CONSTRAINT_KLIEN)) {
            // CASE agar setiap cabang bernilai TRUE/FALSE: CHECK yang bernilai NULL dianggap lolos oleh MySQL.
            DB::statement('ALTER TABLE tbl_klien ADD CONSTRAINT '.self::CONSTRAINT_KLIEN." CHECK (CASE
                WHEN bpjs_metode IS NULL THEN bpjs_nilai IS NULL
                WHEN bpjs_nilai IS NULL THEN FALSE
                WHEN bpjs_metode = 'Persentase' THEN bpjs_nilai > 0 AND bpjs_nilai <= 100
                ELSE bpjs_nilai > 0
            END)");
        }

        if (! Schema::hasColumn('tbl_payroll', 'potongan_bpjs')) {
            DB::statement("ALTER TABLE tbl_payroll
                ADD COLUMN id_klien_bpjs INT NULL DEFAULT NULL AFTER hari_absen,
                ADD COLUMN bpjs_metode ENUM('Persentase','Nominal') NULL DEFAULT NULL AFTER id_klien_bpjs,
                ADD COLUMN bpjs_nilai DECIMAL(12,2) NULL DEFAULT NULL AFTER bpjs_metode,
                ADD COLUMN potongan_bpjs DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER bpjs_nilai");
        }

        if (! SqlKomponen::indeksAda('tbl_payroll', self::INDEKS_PAYROLL)) {
            DB::statement('ALTER TABLE tbl_payroll ADD INDEX '.self::INDEKS_PAYROLL.' (id_klien_bpjs, periode_tahun, periode_bulan)');
        }

        if (! SqlKomponen::constraintAda('tbl_payroll', self::FK_PAYROLL)) {
            DB::statement('ALTER TABLE tbl_payroll ADD CONSTRAINT '.self::FK_PAYROLL
                .' FOREIGN KEY (id_klien_bpjs) REFERENCES tbl_klien (id) ON UPDATE RESTRICT ON DELETE RESTRICT');
        }

        if (! Schema::hasColumn('tbl_invoice', 'biaya_bpjs')) {
            DB::statement("ALTER TABLE tbl_invoice
                ADD COLUMN bpjs_metode ENUM('Persentase','Nominal') NULL DEFAULT NULL AFTER jumlah_tenaga,
                ADD COLUMN bpjs_nilai DECIMAL(12,2) NULL DEFAULT NULL AFTER bpjs_metode,
                ADD COLUMN biaya_bpjs DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER bpjs_nilai");
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 1);
        SqlKomponen::pasangHakAkses();
    }

    /**
     * Reverse the migrations. Ditolak bila sudah ada payroll/invoice yang memuat BPJS, karena
     * rinciannya akan hilang sementara total_gaji/total_tagihan sudah memperhitungkannya.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (
            (Schema::hasColumn('tbl_payroll', 'potongan_bpjs') && DB::table('tbl_payroll')->where('potongan_bpjs', '>', 0)->exists())
            || (Schema::hasColumn('tbl_invoice', 'biaya_bpjs') && DB::table('tbl_invoice')->where('biaya_bpjs', '>', 0)->exists())
        ) {
            throw new LogicException('Rollback ditolak karena sudah ada payroll/invoice yang memuat potongan atau biaya BPJS.');
        }

        // Routine & view lama dipasang dulu agar tidak ada yang merujuk kolom yang dihapus.
        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 1);

        if (Schema::hasColumn('tbl_invoice', 'biaya_bpjs')) {
            DB::statement('ALTER TABLE tbl_invoice DROP COLUMN biaya_bpjs, DROP COLUMN bpjs_nilai, DROP COLUMN bpjs_metode');
        }

        if (SqlKomponen::constraintAda('tbl_payroll', self::FK_PAYROLL)) {
            DB::statement('ALTER TABLE tbl_payroll DROP FOREIGN KEY '.self::FK_PAYROLL);
        }

        if (SqlKomponen::indeksAda('tbl_payroll', self::INDEKS_PAYROLL)) {
            DB::statement('ALTER TABLE tbl_payroll DROP INDEX '.self::INDEKS_PAYROLL);
        }

        if (Schema::hasColumn('tbl_payroll', 'potongan_bpjs')) {
            DB::statement('ALTER TABLE tbl_payroll DROP COLUMN potongan_bpjs, DROP COLUMN bpjs_nilai, DROP COLUMN bpjs_metode, DROP COLUMN id_klien_bpjs');
        }

        // DROP CONSTRAINT: didukung MariaDB (>= 10.2.1) dan MySQL (>= 8.0.19).
        if (SqlKomponen::constraintAda('tbl_klien', self::CONSTRAINT_KLIEN)) {
            DB::statement('ALTER TABLE tbl_klien DROP CONSTRAINT '.self::CONSTRAINT_KLIEN);
        }

        if (Schema::hasColumn('tbl_klien', 'bpjs_metode')) {
            DB::statement('ALTER TABLE tbl_klien DROP COLUMN bpjs_nilai, DROP COLUMN bpjs_metode');
        }

        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_klien')
            && Schema::hasTable('tbl_invoice')
            && Schema::hasColumn('tbl_payroll', 'hari_absen');
    }
};

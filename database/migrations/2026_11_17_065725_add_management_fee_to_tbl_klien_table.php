<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Management Fee klien: persentase yang disepakati perusahaan outsourcing dengan klien.
 *
 * - DECIMAL(5,2) NULL: dua angka desimal tepat (mis. 7.50). NULL = "Belum ditentukan",
 *   0.00 = disepakati tanpa management fee. Klien lama dibiarkan NULL (tidak diisi 0).
 * - chk_klien_management_fee: nilai 0–100 (NULL diizinkan).
 *
 * Belum dipakai dalam perhitungan invoice/payroll; dasar perhitungannya belum disepakati.
 * Hak akses tidak berubah: GRANT di file 10 berlaku untuk seluruh kolom tbl_klien.
 * Idempoten dan hanya jalan di MySQL/MariaDB (SQLite pengujian membuat tabelnya sendiri).
 */
return new class extends Migration
{
    private const string CONSTRAINT = 'chk_klien_management_fee';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (! Schema::hasColumn('tbl_klien', 'management_fee')) {
            DB::statement("ALTER TABLE tbl_klien ADD COLUMN management_fee DECIMAL(5,2) NULL DEFAULT NULL
                COMMENT 'Persentase management fee; NULL = belum ditentukan' AFTER pic_email");
        }

        if (! SqlKomponen::constraintAda('tbl_klien', self::CONSTRAINT)) {
            DB::statement('ALTER TABLE tbl_klien ADD CONSTRAINT '.self::CONSTRAINT
                .' CHECK (management_fee IS NULL OR management_fee BETWEEN 0 AND 100)');
        }
    }

    /**
     * Reverse the migrations. Nilai management fee yang sudah diisi ikut terhapus.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        // DROP CONSTRAINT: didukung MariaDB (>= 10.2.1) dan MySQL (>= 8.0.19).
        if (SqlKomponen::constraintAda('tbl_klien', self::CONSTRAINT)) {
            DB::statement('ALTER TABLE tbl_klien DROP CONSTRAINT '.self::CONSTRAINT);
        }

        if (Schema::hasColumn('tbl_klien', 'management_fee')) {
            DB::statement('ALTER TABLE tbl_klien DROP COLUMN management_fee');
        }
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_klien');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis jaminan kesehatan karyawan (BPJS / Asuransi Kesehatan Swasta), diisi HRD di form karyawan.
 * Nullable agar data karyawan lama tetap valid; dipakai Keuangan sebagai acuan potongan BPJS.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_karyawan') || Schema::hasColumn('tbl_karyawan', 'jenis_jaminan_kesehatan')) {
            return;
        }

        Schema::table('tbl_karyawan', function (Blueprint $table) {
            $kolom = $table->enum('jenis_jaminan_kesehatan', ['BPJS', 'Asuransi Kesehatan Swasta'])->nullable();

            if (Schema::hasColumn('tbl_karyawan', 'nama_ibu_kandung')) {
                $kolom->after('nama_ibu_kandung');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tbl_karyawan') || ! Schema::hasColumn('tbl_karyawan', 'jenis_jaminan_kesehatan')) {
            return;
        }

        Schema::table('tbl_karyawan', function (Blueprint $table) {
            $table->dropColumn('jenis_jaminan_kesehatan');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_karyawan')) {
            return;
        }

        Schema::table('tbl_karyawan', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_karyawan', 'status_pernikahan')) {
                $table->enum('status_pernikahan', ['Belum Menikah', 'Menikah'])->nullable()->after('jenis_kelamin');
            }

            if (! Schema::hasColumn('tbl_karyawan', 'nama_ibu_kandung')) {
                $table->string('nama_ibu_kandung', 100)->nullable()->after('status_pernikahan');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tbl_karyawan')) {
            return;
        }

        $columns = array_values(array_filter(
            ['status_pernikahan', 'nama_ibu_kandung'],
            fn (string $column): bool => Schema::hasColumn('tbl_karyawan', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('tbl_karyawan', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};

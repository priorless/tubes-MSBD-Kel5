<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom pendukung autentikasi ke tbl_users (skema db_sji dibuat dari sql/schema_sji.sql).
 *
 * - nama                 : nama tampilan untuk akun staff internal.
 * - must_change_password : wajib ganti password saat login berikutnya (default true).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_users')) {
            return;
        }

        Schema::table('tbl_users', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_users', 'nama')) {
                $table->string('nama', 100)->nullable()->after('id_karyawan');
            }

            if (! Schema::hasColumn('tbl_users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(true)->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tbl_users')) {
            return;
        }

        Schema::table('tbl_users', function (Blueprint $table) {
            $table->dropColumn(['nama', 'must_change_password']);
        });
    }
};

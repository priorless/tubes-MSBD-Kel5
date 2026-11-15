<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_absensi') && ! Schema::hasColumn('tbl_absensi', 'foto_checkin')) {
            Schema::table('tbl_absensi', function (Blueprint $table) {
                $table->string('foto_checkin')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_absensi') && Schema::hasColumn('tbl_absensi', 'foto_checkin')) {
            Schema::table('tbl_absensi', function (Blueprint $table) {
                $table->dropColumn('foto_checkin');
            });
        }
    }
};

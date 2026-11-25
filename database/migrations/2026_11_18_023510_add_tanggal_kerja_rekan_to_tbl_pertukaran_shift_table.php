
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->date('tanggal_kerja_rekan')
                ->nullable()
                ->after('tanggal_kerja');
        });

        DB::table('tbl_pertukaran_shift')->update([
            'tanggal_kerja_rekan' => DB::raw('tanggal_kerja'),
        ]);
    }

    public function down(): void
    {
        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->dropColumn('tanggal_kerja_rekan');
        });
    }
};

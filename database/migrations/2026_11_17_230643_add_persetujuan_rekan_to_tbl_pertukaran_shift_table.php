<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persetujuan rekan pada Pertukaran Shift (Roker): rekan menerima/menolak lebih dulu,
 * baru Operasional memutuskan. Hanya MENAMBAH kolom.
 *
 * Pengajuan lama dibuat dengan pernyataan "rekan sudah setuju", sehingga ditandai 'Diterima'
 * agar perilakunya tidak berubah. Pengajuan baru dimulai dari 'Menunggu'.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->enum('status_rekan', ['Menunggu', 'Diterima', 'Ditolak'])->default('Menunggu')->after('status');
            $table->string('catatan_rekan', 500)->nullable()->after('status_rekan');
            $table->timestamp('ditanggapi_rekan_pada')->nullable()->after('catatan_rekan');
        });

        DB::table('tbl_pertukaran_shift')->update(['status_rekan' => 'Diterima']);

        if (DB::getDriverName() === 'mysql') {
            SqlKomponen::pasangHakAkses();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->dropColumn(['status_rekan', 'catatan_rekan', 'ditanggapi_rekan_pada']);
        });
    }
};

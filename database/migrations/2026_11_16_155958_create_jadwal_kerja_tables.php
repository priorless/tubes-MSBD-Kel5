<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_template_shift', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('id_klien');
            $table->string('nama_shift', 100);
            $table->time('jam_masuk');
            $table->time('jam_pulang');
            $table->unsignedSmallInteger('durasi_menit');
            $table->enum('status', ['Aktif', 'Nonaktif'])->default('Aktif');
            $table->integer('dibuat_oleh')->nullable();
            $table->integer('diubah_oleh')->nullable();
            $table->timestamps();

            $table->unique(['id_klien', 'nama_shift'], 'uq_template_shift_klien_nama');
            $table->index(['id_klien', 'status'], 'idx_template_shift_klien_status');
            $table->foreign('id_klien', 'fk_template_shift_klien')
                ->references('id')
                ->on('tbl_klien')
                ->restrictOnDelete();
        });

        Schema::create('tbl_jadwal_kerja', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('id_penempatan');
            $table->date('tanggal_kerja');
            $table->enum('status', ['Kerja', 'Libur']);
            $table->unsignedInteger('id_template_shift')->nullable();
            $table->string('nama_shift_snapshot', 100)->nullable();
            $table->time('jam_masuk')->nullable();
            $table->time('jam_pulang')->nullable();
            $table->unsignedSmallInteger('durasi_menit')->nullable();
            $table->integer('dibuat_oleh')->nullable();
            $table->integer('diubah_oleh')->nullable();
            $table->timestamps();

            $table->unique(['id_penempatan', 'tanggal_kerja'], 'uq_jadwal_penempatan_tanggal');
            $table->index(['tanggal_kerja', 'id_penempatan'], 'idx_jadwal_tanggal_penempatan');
            $table->foreign('id_penempatan', 'fk_jadwal_penempatan')
                ->references('id')
                ->on('tbl_penempatan')
                ->restrictOnDelete();
            $table->foreign('id_template_shift', 'fk_jadwal_template_shift')
                ->references('id')
                ->on('tbl_template_shift')
                ->restrictOnDelete();
        });

        Schema::create('tbl_jadwal_kerja_riwayat', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('id_jadwal_kerja');
            $table->enum('aksi', ['Dibuat', 'Diubah', 'Disalin']);
            $table->json('data_sebelum')->nullable();
            $table->json('data_sesudah');
            $table->integer('diubah_oleh')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['id_jadwal_kerja', 'created_at'], 'idx_jadwal_riwayat_tanggal');
            $table->foreign('id_jadwal_kerja', 'fk_jadwal_riwayat_jadwal')
                ->references('id')
                ->on('tbl_jadwal_kerja')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            SqlKomponen::pasangHakAkses();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['tbl_jadwal_kerja_riwayat', 'tbl_jadwal_kerja', 'tbl_template_shift'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new LogicException("Rollback ditolak karena {$table} sudah berisi data jadwal.");
            }
        }

        Schema::dropIfExists('tbl_jadwal_kerja_riwayat');
        Schema::dropIfExists('tbl_jadwal_kerja');
        Schema::dropIfExists('tbl_template_shift');
    }
};

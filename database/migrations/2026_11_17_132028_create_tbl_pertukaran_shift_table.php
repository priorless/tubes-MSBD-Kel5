<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Pertukaran Shift: karyawan menukar jadwal satu tanggal dengan rekan di klien yang sama,
 * lalu Operasional menyetujui atau menolak.
 *
 * Hanya MENAMBAH satu tabel; tbl_jadwal_kerja tidak diubah. Saat disetujui, isi kedua jadwal ditukar
 * lewat JadwalKerjaService::simpanNilaiJadwal() sehingga tercatat di tbl_jadwal_kerja_riwayat.
 * Snapshot JSON menyimpan shift saat pengajuan dibuat, agar persetujuan ditolak bila jadwal telah berubah.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('id_karyawan_pengaju');
            $table->integer('id_karyawan_rekan');
            $table->unsignedInteger('id_jadwal_pengaju');
            $table->unsignedInteger('id_jadwal_rekan');
            $table->date('tanggal_kerja');
            $table->json('snapshot_pengaju');
            $table->json('snapshot_rekan');
            $table->string('alasan', 500);
            $table->enum('status', ['Menunggu', 'Disetujui', 'Ditolak'])->default('Menunggu');
            $table->string('catatan_keputusan', 500)->nullable();
            $table->integer('diproses_oleh')->nullable();
            $table->timestamp('diproses_pada')->nullable();
            $table->timestamps();

            $table->index(['status', 'tanggal_kerja'], 'idx_pertukaran_status_tanggal');
            $table->index(['id_karyawan_pengaju', 'created_at'], 'idx_pertukaran_pengaju');
            $table->foreign('id_karyawan_pengaju', 'fk_pertukaran_pengaju')
                ->references('id')->on('tbl_karyawan')->restrictOnDelete();
            $table->foreign('id_karyawan_rekan', 'fk_pertukaran_rekan')
                ->references('id')->on('tbl_karyawan')->restrictOnDelete();
            $table->foreign('id_jadwal_pengaju', 'fk_pertukaran_jadwal_pengaju')
                ->references('id')->on('tbl_jadwal_kerja')->restrictOnDelete();
            $table->foreign('id_jadwal_rekan', 'fk_pertukaran_jadwal_rekan')
                ->references('id')->on('tbl_jadwal_kerja')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE tbl_pertukaran_shift
                ADD CONSTRAINT chk_pertukaran_beda_karyawan CHECK (id_karyawan_pengaju <> id_karyawan_rekan),
                ADD CONSTRAINT chk_pertukaran_diproses CHECK (
                    (status = 'Menunggu' AND diproses_oleh IS NULL AND diproses_pada IS NULL)
                    OR (status <> 'Menunggu' AND diproses_oleh IS NOT NULL AND diproses_pada IS NOT NULL)
                )
                SQL);

            SqlKomponen::pasangHakAkses();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tbl_pertukaran_shift') && DB::table('tbl_pertukaran_shift')->exists()) {
            throw new LogicException('Rollback ditolak karena tbl_pertukaran_shift sudah berisi data pengajuan.');
        }

        Schema::dropIfExists('tbl_pertukaran_shift');
    }
};

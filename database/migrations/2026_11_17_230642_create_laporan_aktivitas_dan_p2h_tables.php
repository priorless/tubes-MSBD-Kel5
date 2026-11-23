<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumentasi absensi per jenis layanan. Hanya MENAMBAH tabel; tbl_absensi tidak diubah.
 *
 * - tbl_laporan_aktivitas: laporan foto selama sesi absensi.
 *     jenis 'Berkala'    = Satpam & Cleaning Service, satu laporan per periode 1 jam (periode_ke unik per absensi);
 *     jenis 'Perjalanan' = Driver, laporan per tahapan perjalanan (tanpa periode).
 *   Setiap laporan maksimal 2 unggahan: foto_utama + foto_koreksi (jumlah_upload 1..2).
 * - tbl_p2h + tbl_p2h_item: Pemeriksaan Harian Kendaraan Driver saat check-in (9 komponen, foto kendaraan)
 *   dan kondisi akhir + foto kendaraan saat check-out. Satu P2H per absensi.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_laporan_aktivitas', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('id_absensi');
            $table->integer('id_karyawan');
            $table->enum('jenis', ['Berkala', 'Perjalanan']);
            $table->unsignedSmallInteger('periode_ke')->nullable();
            $table->dateTime('periode_mulai')->nullable();
            $table->dateTime('periode_selesai')->nullable();
            $table->string('tahapan', 40)->nullable();
            $table->string('keterangan', 500);
            $table->string('foto_utama', 255);
            $table->string('foto_koreksi', 255)->nullable();
            $table->unsignedTinyInteger('jumlah_upload')->default(1);
            $table->dateTime('waktu_lapor');
            $table->dateTime('waktu_koreksi')->nullable();
            $table->timestamps();

            $table->unique(['id_absensi', 'periode_ke'], 'uq_laporan_absensi_periode');
            $table->index(['id_karyawan', 'waktu_lapor'], 'idx_laporan_karyawan_waktu');
            $table->foreign('id_absensi', 'fk_laporan_absensi')->references('id')->on('tbl_absensi')->restrictOnDelete();
            $table->foreign('id_karyawan', 'fk_laporan_karyawan')->references('id')->on('tbl_karyawan')->restrictOnDelete();
        });

        Schema::create('tbl_p2h', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('id_absensi');
            $table->integer('id_karyawan');
            // NULL hanya untuk sesi yang check-in sebelum fitur P2H dipasang (baris dibuat saat check-out).
            $table->string('foto_kendaraan_awal', 255)->nullable();
            $table->dateTime('waktu_pemeriksaan')->nullable();
            $table->enum('kondisi_akhir', ['Baik', 'Perlu Perhatian', 'Rusak'])->nullable();
            $table->string('catatan_akhir', 500)->nullable();
            $table->string('foto_kendaraan_akhir', 255)->nullable();
            $table->dateTime('waktu_selesai')->nullable();
            $table->timestamps();

            $table->unique('id_absensi', 'uq_p2h_absensi');
            $table->foreign('id_absensi', 'fk_p2h_absensi')->references('id')->on('tbl_absensi')->restrictOnDelete();
            $table->foreign('id_karyawan', 'fk_p2h_karyawan')->references('id')->on('tbl_karyawan')->restrictOnDelete();
        });

        Schema::create('tbl_p2h_item', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('id_p2h');
            $table->string('komponen', 40);
            $table->enum('kondisi', ['Baik', 'Perlu Perhatian', 'Rusak']);
            $table->string('keterangan', 255)->nullable();

            $table->unique(['id_p2h', 'komponen'], 'uq_p2h_item_komponen');
            $table->foreign('id_p2h', 'fk_p2h_item_p2h')->references('id')->on('tbl_p2h')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE tbl_laporan_aktivitas
                ADD CONSTRAINT chk_laporan_jumlah_upload CHECK (jumlah_upload BETWEEN 1 AND 2),
                ADD CONSTRAINT chk_laporan_koreksi CHECK ((jumlah_upload = 2) = (foto_koreksi IS NOT NULL)),
                ADD CONSTRAINT chk_laporan_jenis CHECK (
                    (jenis = 'Berkala' AND periode_ke IS NOT NULL AND periode_mulai IS NOT NULL AND periode_selesai IS NOT NULL)
                    OR (jenis = 'Perjalanan' AND periode_ke IS NULL AND tahapan IS NOT NULL)
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
        foreach (['tbl_p2h_item', 'tbl_p2h', 'tbl_laporan_aktivitas'] as $tabel) {
            if (Schema::hasTable($tabel) && DB::table($tabel)->exists()) {
                throw new LogicException("Rollback ditolak karena {$tabel} sudah berisi data laporan.");
            }
        }

        Schema::dropIfExists('tbl_p2h_item');
        Schema::dropIfExists('tbl_p2h');
        Schema::dropIfExists('tbl_laporan_aktivitas');
    }
};

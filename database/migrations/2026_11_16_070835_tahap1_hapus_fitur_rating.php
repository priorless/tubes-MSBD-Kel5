<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 1 (sql/Komponen/11_lengkapi_fitur.sql): hapus fitur rating seluruhnya.
 *
 * Trigger trg_update_rating(_ubah/_hapus), tabel tbl_evaluasi_kinerja (beserta FK & chk_rating),
 * dan kolom tbl_karyawan.rating dihapus; v_pool_karyawan_hrd tidak lagi memuat rating.
 * down() mengembalikan tabel, kolom, trigger, dan view seperti versi file 09.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/11_lengkapi_fitur.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/11_lengkapi_fitur_rollback.sql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        // View baru dibuat dulu agar tidak ada view yang masih merujuk kolom rating.
        SqlKomponen::jalankanBlok(self::FILE_SQL, 1);

        Schema::dropIfExists('tbl_evaluasi_kinerja');

        if (Schema::hasColumn('tbl_karyawan', 'rating')) {
            Schema::table('tbl_karyawan', fn (Blueprint $table) => $table->dropColumn('rating'));
        }

        SqlKomponen::pasangHakAkses();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (! Schema::hasColumn('tbl_karyawan', 'rating')) {
            DB::statement('ALTER TABLE tbl_karyawan ADD COLUMN rating DECIMAL(2,1) NOT NULL DEFAULT 0.0 AFTER status');
        }

        if (! Schema::hasTable('tbl_evaluasi_kinerja')) {
            DB::statement(<<<'SQL'
                CREATE TABLE tbl_evaluasi_kinerja (
                  id INT NOT NULL AUTO_INCREMENT,
                  id_karyawan INT NOT NULL,
                  id_klien INT NOT NULL,
                  periode_bulan INT NOT NULL,
                  periode_tahun INT NOT NULL,
                  rating INT NOT NULL,
                  catatan TEXT NULL,
                  dinilai_oleh INT NULL,
                  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_evaluasi_periode (id_karyawan, periode_bulan, periode_tahun),
                  KEY fk_eval_klien (id_klien),
                  KEY fk_eval_user (dinilai_oleh),
                  CONSTRAINT fk_eval_karyawan FOREIGN KEY (id_karyawan) REFERENCES tbl_karyawan (id),
                  CONSTRAINT fk_eval_klien FOREIGN KEY (id_klien) REFERENCES tbl_klien (id),
                  CONSTRAINT fk_eval_user FOREIGN KEY (dinilai_oleh) REFERENCES tbl_users (id),
                  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
                ) ENGINE=InnoDB
                SQL);
        }

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 1);
        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql' && Schema::hasTable('tbl_karyawan');
    }
};

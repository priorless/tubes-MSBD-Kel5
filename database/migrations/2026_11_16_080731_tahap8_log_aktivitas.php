<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 8 (sql/Komponen/11_lengkapi_fitur.sql): log aktivitas (audit).
 *
 * Tabel tbl_log_aktivitas + trigger log untuk tbl_karyawan, tbl_penempatan, tbl_payroll, tbl_invoice,
 * tbl_users, dan tbl_klien. Trigger log digabung ke trigger yang sudah ada bila event-nya sama
 * (trg_auto_akun_karyawan, trg_karyawan_sinkron_akun, trg_mutasi_payroll_cair, trg_mutasi_invoice_lunas).
 * id_user diisi dari @app_user_id (middleware SetAppUserIdDatabase).
 * down() mengembalikan trigger lama dan menghapus tabel log beserta isinya.
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

        if (! Schema::hasTable('tbl_log_aktivitas')) {
            DB::statement(<<<'SQL'
                CREATE TABLE tbl_log_aktivitas (
                  id INT NOT NULL AUTO_INCREMENT,
                  waktu TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  id_user INT NULL,
                  nama_tabel VARCHAR(50) NOT NULL,
                  aksi ENUM('INSERT','UPDATE','DELETE') NOT NULL,
                  id_data INT NULL,
                  keterangan VARCHAR(255) NULL,
                  PRIMARY KEY (id),
                  KEY idx_log_tabel_waktu (nama_tabel, waktu),
                  KEY idx_log_user (id_user)
                ) ENGINE=InnoDB
                SQL);
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 8);
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

        // Trigger lama (tanpa log) dipasang dulu agar tidak ada trigger yang menulis ke tabel yang dihapus.
        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 8);
        Schema::dropIfExists('tbl_log_aktivitas');
        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_karyawan')
            && Schema::hasTable('tbl_users')
            && Schema::hasTable('tbl_klien');
    }
};

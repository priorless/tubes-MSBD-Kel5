<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/14_komponen_gaji.sql';

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('tbl_karyawan') || ! Schema::hasTable('tbl_payroll')) {
            return;
        }

        if (! Schema::hasTable('tbl_komponen_gaji')) {
            Schema::create('tbl_komponen_gaji', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('id_karyawan');
                $table->unsignedTinyInteger('periode_bulan');
                $table->unsignedSmallInteger('periode_tahun');
                $table->decimal('tunjangan', 12, 2)->default(0);
                $table->decimal('potongan_bpjs', 12, 2)->default(0);
                $table->decimal('potongan_pinjaman', 12, 2)->default(0);
                $table->timestamps();
                $table->unique(['id_karyawan', 'periode_bulan', 'periode_tahun'], 'uq_komponen_gaji_karyawan_periode');
                $table->index(['periode_tahun', 'periode_bulan'], 'idx_komponen_gaji_periode');
                $table->foreign('id_karyawan', 'fk_komponen_gaji_karyawan')
                    ->references('id')->on('tbl_karyawan')->restrictOnDelete();
            });
        }

        foreach (['komponen_tunjangan', 'potongan_bpjs', 'potongan_pinjaman'] as $kolom) {
            if (! Schema::hasColumn('tbl_payroll', $kolom)) {
                DB::statement("ALTER TABLE tbl_payroll ADD COLUMN {$kolom} DECIMAL(12,2) NOT NULL DEFAULT 0.00");
            }
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 1);
        SqlKomponen::pasangHakAkses();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('tbl_payroll')) {
            return;
        }

        if (Schema::hasTable('tbl_komponen_gaji') && DB::table('tbl_komponen_gaji')->exists()) {
            throw new LogicException('Rollback ditolak karena tbl_komponen_gaji sudah berisi data.');
        }

        // Pulihkan definisi Generate Payroll sebelum menghapus kolom yang dipakainya.
        SqlKomponen::jalankanBlok('sql/Komponen/11_lengkapi_fitur.sql', 6);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW v_slip_gaji_detail AS
            SELECT p.id, k.nip, k.nama, k.kategori, p.periode_bulan, p.periode_tahun,
                   p.gaji_pokok, p.total_lembur, p.potongan_absen, p.total_gaji,
                   p.status_bayar, p.tgl_bayar, p.hari_wajib, p.hari_masuk, p.hari_absen
            FROM tbl_payroll p JOIN tbl_karyawan k ON p.id_karyawan = k.id
            SQL);

        foreach (['komponen_tunjangan', 'potongan_bpjs', 'potongan_pinjaman'] as $kolom) {
            if (Schema::hasColumn('tbl_payroll', $kolom)) {
                DB::statement("ALTER TABLE tbl_payroll DROP COLUMN {$kolom}");
            }
        }
        Schema::dropIfExists('tbl_komponen_gaji');
        SqlKomponen::pasangHakAkses();
    }
};

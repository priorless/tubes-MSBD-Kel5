<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice klien dari data payroll tersimpan + Management Fee + PPN 11% (sql/Komponen/17_invoice_payroll_fee_ppn.sql).
 *
 * - tbl_invoice: + sub_total, persen_fee, management_fee, ppn. total_tagihan = Grand Total.
 *   Invoice lama tidak dihitung ulang (kolom baru bernilai 0).
 * - tbl_invoice_detail: snapshot rincian per karyawan (sumber lampiran PDF) agar surat, invoice, dan lampiran
 *   selalu konsisten walaupun payroll periode itu bertambah setelah invoice dibuat. Payroll tidak diubah.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/17_invoice_payroll_fee_ppn.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/17_invoice_payroll_fee_ppn_rollback.sql';

    /** @var list<string> */
    private const array KOLOM_INVOICE = ['sub_total', 'persen_fee', 'management_fee', 'ppn'];

    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (! Schema::hasColumn('tbl_invoice', 'sub_total')) {
            DB::statement('ALTER TABLE tbl_invoice
                ADD COLUMN sub_total DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER biaya_bpjs,
                ADD COLUMN persen_fee DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER sub_total,
                ADD COLUMN management_fee DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER persen_fee,
                ADD COLUMN ppn DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER management_fee');
        }

        if (! Schema::hasTable('tbl_invoice_detail')) {
            Schema::create('tbl_invoice_detail', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('id_invoice');
                $table->integer('id_payroll');
                $table->integer('id_karyawan');
                $table->string('nip', 20);
                $table->string('nama', 100);
                $table->string('jabatan', 50);
                $table->unsignedSmallInteger('hari_klien');
                $table->unsignedSmallInteger('hari_total');
                foreach (['gaji_pokok', 'tunjangan', 'upah_backup', 'upah_lembur', 'potongan_absen', 'biaya_tenaga_kerja',
                    'potongan_pinjaman', 'potongan_bpjs', 'pph21', 'total_penerimaan'] as $kolom) {
                    $table->decimal($kolom, 14, 2)->default(0);
                }
                $table->unique(['id_invoice', 'id_payroll'], 'uq_invoice_detail_payroll');
                $table->index('id_payroll', 'idx_invoice_detail_payroll');
                // Tanpa FK ke tbl_payroll: lampiran adalah snapshot dokumen tagihan.
                $table->foreign('id_invoice', 'fk_invoice_detail_invoice')->references('id')->on('tbl_invoice')->cascadeOnDelete();
            });
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 1);
        SqlKomponen::pasangHakAkses();
    }

    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 1);
        Schema::dropIfExists('tbl_invoice_detail');

        foreach (self::KOLOM_INVOICE as $kolom) {
            if (Schema::hasColumn('tbl_invoice', $kolom)) {
                DB::statement("ALTER TABLE tbl_invoice DROP COLUMN {$kolom}");
            }
        }

        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_invoice')
            && Schema::hasColumn('tbl_invoice', 'biaya_bpjs')
            && Schema::hasColumn('tbl_payroll', 'pph21');
    }
};

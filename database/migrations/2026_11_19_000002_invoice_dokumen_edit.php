<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mode edit dokumen tagihan klien (Admin Keuangan): teks surat & catatan manual per invoice + riwayat audit.
 *
 * - tbl_invoice_dokumen: satu baris per invoice; kolom teks NULL = teks bawaan. versi untuk mencegah simpanan
 *   bersamaan saling menimpa.
 * - tbl_invoice_catatan: catatan tambahan manual Keuangan (terpisah dari log Tukar Shift milik Operasional).
 * - tbl_invoice_dokumen_riwayat: data sebelum/sesudah setiap simpan, pengubah, dan waktunya.
 * Nominal invoice tidak disimpan di sini; tetap dari tbl_invoice/tbl_invoice_detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (! Schema::hasTable('tbl_invoice_dokumen')) {
            Schema::create('tbl_invoice_dokumen', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->integer('id_invoice')->primary();
                $table->string('penerima', 150)->nullable();
                $table->string('hal', 200)->nullable();
                $table->text('paragraf_pembuka')->nullable();
                $table->text('paragraf_penutup')->nullable();
                $table->string('salam_penutup', 255)->nullable();
                $table->unsignedInteger('versi')->default(0);
                $table->integer('diubah_oleh')->nullable();
                $table->timestamp('diubah_pada')->nullable();
                $table->foreign('id_invoice', 'fk_invoice_dokumen_invoice')->references('id')->on('tbl_invoice')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('tbl_invoice_catatan')) {
            Schema::create('tbl_invoice_catatan', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('id_invoice');
                $table->string('isi', 500);
                $table->integer('dibuat_oleh');
                $table->timestamp('dibuat_pada')->useCurrent();
                $table->integer('diubah_oleh')->nullable();
                $table->timestamp('diubah_pada')->nullable();
                $table->index('id_invoice', 'idx_invoice_catatan_invoice');
                $table->foreign('id_invoice', 'fk_invoice_catatan_invoice')->references('id')->on('tbl_invoice')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('tbl_invoice_dokumen_riwayat')) {
            Schema::create('tbl_invoice_dokumen_riwayat', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->integer('id_invoice');
                $table->unsignedInteger('versi');
                $table->json('data_sebelum');
                $table->json('data_sesudah');
                $table->integer('diubah_oleh');
                $table->timestamp('waktu')->useCurrent();
                $table->unique(['id_invoice', 'versi'], 'uq_invoice_riwayat_versi');
                $table->foreign('id_invoice', 'fk_invoice_riwayat_invoice')->references('id')->on('tbl_invoice')->cascadeOnDelete();
            });
        }

        SqlKomponen::pasangHakAkses();
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_invoice_dokumen_riwayat');
        Schema::dropIfExists('tbl_invoice_catatan');
        Schema::dropIfExists('tbl_invoice_dokumen');

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            SqlKomponen::pasangHakAkses();
        }
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_invoice_detail');
    }
};

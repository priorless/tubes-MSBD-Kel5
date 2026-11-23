<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Template Jadwal Bulanan (Pribadi & Bersama).
 *
 * Hanya MENAMBAH tiga tabel baru; tidak ada tabel, routine, trigger, view, atau data lama yang diubah.
 * Hak akses (GRANT) sengaja TIDAK dipasang di sini dan diterapkan terpisah setelah disetujui.
 *
 * - tbl_template_jadwal           : identitas template (Pribadi milik satu karyawan, Bersama milik satu klien).
 * - tbl_template_jadwal_detail    : pola per hari ke-1..31; hari tanpa baris = Belum Diatur.
 * - tbl_template_jadwal_penerapan : log setiap kali template diterapkan ke jadwal seorang karyawan.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array TABEL = [
        'tbl_template_jadwal',
        'tbl_template_jadwal_detail',
        'tbl_template_jadwal_penerapan',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            if (Schema::hasTable($tabel)) {
                throw new LogicException("Migration dihentikan: {$tabel} sudah ada. Periksa skema sebelum melanjutkan.");
            }
        }

        try {
            $this->buatTabel();
        } catch (Throwable $exception) {
            // DDL MySQL/MariaDB tidak transaksional: bersihkan tabel kosong yang sempat dibuat pada percobaan ini.
            foreach (array_reverse(self::TABEL) as $tabel) {
                if (Schema::hasTable($tabel) && ! DB::table($tabel)->exists()) {
                    Schema::drop($tabel);
                }
            }

            throw $exception;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            if (Schema::hasTable($tabel) && DB::table($tabel)->exists()) {
                throw new LogicException("Rollback ditolak karena {$tabel} sudah berisi data template jadwal.");
            }
        }

        foreach (array_reverse(self::TABEL) as $tabel) {
            Schema::dropIfExists($tabel);
        }
    }

    private function buatTabel(): void
    {
        Schema::create('tbl_template_jadwal', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('nama_template', 100);
            $table->enum('jenis', ['Pribadi', 'Bersama']);
            $table->integer('id_karyawan')->nullable();
            $table->integer('id_klien')->nullable();
            $table->integer('id_jenis_layanan')->nullable();
            $table->text('keterangan')->nullable();
            $table->enum('status', ['Aktif', 'Nonaktif'])->default('Aktif');
            $table->integer('dibuat_oleh')->nullable();
            $table->integer('diubah_oleh')->nullable();
            $table->timestamps();

            $table->unique('nama_template', 'uq_template_jadwal_nama');
            $table->index(['jenis', 'status'], 'idx_template_jadwal_jenis_status');
            $table->index(['id_karyawan', 'status'], 'idx_template_jadwal_karyawan');
            $table->index(['id_klien', 'status'], 'idx_template_jadwal_klien');
            $table->foreign('id_karyawan', 'fk_template_jadwal_karyawan')
                ->references('id')->on('tbl_karyawan')->restrictOnDelete();
            $table->foreign('id_klien', 'fk_template_jadwal_klien')
                ->references('id')->on('tbl_klien')->restrictOnDelete();
            $table->foreign('id_jenis_layanan', 'fk_template_jadwal_layanan')
                ->references('id')->on('tbl_jenis_layanan')->restrictOnDelete();
        });

        Schema::create('tbl_template_jadwal_detail', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('id_template_jadwal');
            $table->unsignedTinyInteger('hari_ke');
            $table->enum('status', ['Kerja', 'Libur']);
            $table->time('jam_masuk')->nullable();
            $table->time('jam_pulang')->nullable();
            $table->unsignedSmallInteger('durasi_menit')->nullable();

            $table->unique(['id_template_jadwal', 'hari_ke'], 'uq_template_jadwal_detail_hari');
            $table->foreign('id_template_jadwal', 'fk_template_jadwal_detail_template')
                ->references('id')->on('tbl_template_jadwal')->restrictOnDelete();
        });

        Schema::create('tbl_template_jadwal_penerapan', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('id_template_jadwal');
            $table->integer('id_karyawan');
            $table->integer('id_penempatan');
            $table->date('bulan_tujuan');
            $table->enum('mode_konflik', ['Lewati', 'Timpa']);
            $table->unsignedSmallInteger('jumlah_dibuat')->default(0);
            $table->unsignedSmallInteger('jumlah_ditimpa')->default(0);
            $table->unsignedSmallInteger('jumlah_dilewati')->default(0);
            $table->integer('diterapkan_oleh')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['id_template_jadwal', 'created_at'], 'idx_penerapan_template');
            $table->index(['id_karyawan', 'bulan_tujuan'], 'idx_penerapan_karyawan_bulan');
            $table->foreign('id_template_jadwal', 'fk_penerapan_template')
                ->references('id')->on('tbl_template_jadwal')->restrictOnDelete();
            $table->foreign('id_karyawan', 'fk_penerapan_karyawan')
                ->references('id')->on('tbl_karyawan')->restrictOnDelete();
            $table->foreign('id_penempatan', 'fk_penerapan_penempatan')
                ->references('id')->on('tbl_penempatan')->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE tbl_template_jadwal
                ADD CONSTRAINT chk_template_jadwal_pemilik CHECK (
                    (jenis = 'Pribadi' AND id_karyawan IS NOT NULL AND id_klien IS NULL AND id_jenis_layanan IS NULL)
                    OR (jenis = 'Bersama' AND id_karyawan IS NULL AND id_klien IS NOT NULL)
                )
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE tbl_template_jadwal_detail
                ADD CONSTRAINT chk_template_jadwal_detail_hari CHECK (hari_ke BETWEEN 1 AND 31),
                ADD CONSTRAINT chk_template_jadwal_detail_jam CHECK (
                    (status = 'Libur' AND jam_masuk IS NULL AND jam_pulang IS NULL AND durasi_menit IS NULL)
                    OR (status = 'Kerja' AND jam_masuk IS NOT NULL AND jam_pulang IS NOT NULL
                        AND durasi_menit BETWEEN 1 AND 1439)
                )
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE tbl_template_jadwal_penerapan
                ADD CONSTRAINT chk_penerapan_bulan CHECK (DAYOFMONTH(bulan_tujuan) = 1)
                SQL);
        }
    }
};

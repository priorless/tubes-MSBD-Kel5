<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roker shift menjadi BACKUP SHIFT (1 arah) dan integritas absensi (sql/Komponen/22_backup_shift_integritas.sql).
 *
 * tbl_pertukaran_shift:
 * - status eksplisit: + Dibatalkan, Kedaluwarsa (penanda "Dibatalkan oleh pengaju." di catatan dihapus);
 * - kolom model tukar 2 arah (id_jadwal_rekan, tanggal_kerja_rekan, snapshot_rekan) dihapus;
 * - id_jadwal_aktif (kolom generated) + UNIQUE: satu jadwal paling banyak satu pengajuan Menunggu/Disetujui;
 * - indeks (id_karyawan_rekan, created_at) untuk riwayat & permintaan masuk rekan;
 * - waktu lama yang ditulis Laravel dalam UTC digeser ke WIB (app.timezone kini Asia/Jakarta).
 * tbl_jadwal_kerja: + id_karyawan_pengganti (diisi hanya lewat pengajuan Disetujui, dijaga trigger).
 * Tabel baru: tbl_koreksi_check_out (perbaikan lupa check-out).
 * tbl_laporan_aktivitas: CHECK dilonggarkan untuk monitoring tanpa interval (Berkala) dan tanpa tahapan (Driver).
 * Bagian khusus MySQL (CHECK, trigger, procedure, function, view) ada di file SQL komponen.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/22_backup_shift_integritas.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/22_backup_shift_integritas_rollback.sql';

    private const array STATUS_BARU = ['Menunggu', 'Disetujui', 'Ditolak', 'Dibatalkan', 'Kedaluwarsa'];

    private const array STATUS_LAMA = ['Menunggu', 'Disetujui', 'Ditolak'];

    public function up(): void
    {
        $mysql = $this->mysql();

        if ($mysql) {
            DB::statement('DROP VIEW IF EXISTS v_pertukaran_shift_snapshot');
            if (SqlKomponen::constraintAda('tbl_pertukaran_shift', 'chk_pertukaran_diproses')) {
                DB::statement('ALTER TABLE tbl_pertukaran_shift DROP CONSTRAINT chk_pertukaran_diproses');
            }

            // Data lama ditulis Laravel dengan zona UTC; sejak perubahan ini semua waktu memakai WIB.
            DB::table('tbl_pertukaran_shift')->update([
                'created_at' => DB::raw('created_at + INTERVAL 7 HOUR'),
                'updated_at' => DB::raw('updated_at + INTERVAL 7 HOUR'),
                'diproses_pada' => DB::raw('diproses_pada + INTERVAL 7 HOUR'),
                'ditanggapi_rekan_pada' => DB::raw('ditanggapi_rekan_pada + INTERVAL 7 HOUR'),
            ]);
        }

        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->enum('status', self::STATUS_BARU)->default('Menunggu')->change();
        });

        $this->normalisasiDataPertukaran();

        // SQLite tidak menyimpan nama foreign key, sehingga dihapus berdasarkan kolomnya.
        Schema::table('tbl_pertukaran_shift', function (Blueprint $table) use ($mysql): void {
            $table->dropForeign($mysql ? 'fk_pertukaran_jadwal_rekan' : ['id_jadwal_rekan']);
        });

        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->dropColumn(['id_jadwal_rekan', 'tanggal_kerja_rekan', 'snapshot_rekan']);
        });

        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->unsignedInteger('id_jadwal_aktif')
                ->nullable()
                ->virtualAs("CASE WHEN status IN ('Menunggu', 'Disetujui') THEN id_jadwal_pengaju END")
                ->after('id_jadwal_pengaju');
            $table->unique('id_jadwal_aktif', 'uq_pertukaran_jadwal_aktif');
            $table->index(['id_karyawan_rekan', 'created_at'], 'idx_pertukaran_rekan');
        });

        if ($mysql && SqlKomponen::indeksAda('tbl_pertukaran_shift', 'fk_pertukaran_rekan')) {
            DB::statement('ALTER TABLE tbl_pertukaran_shift DROP INDEX fk_pertukaran_rekan');
        }

        Schema::table('tbl_jadwal_kerja', function (Blueprint $table): void {
            $table->integer('id_karyawan_pengganti')->nullable()->after('durasi_menit');
            $table->index(['id_karyawan_pengganti', 'tanggal_kerja'], 'idx_jadwal_pengganti_tanggal');
            $table->foreign('id_karyawan_pengganti', 'fk_jadwal_pengganti')
                ->references('id')->on('tbl_karyawan')->restrictOnDelete();
        });

        Schema::create('tbl_koreksi_check_out', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('id_absensi');
            $table->unsignedInteger('id_absensi_menunggu')
                ->nullable()
                ->virtualAs("CASE WHEN status = 'Menunggu' THEN id_absensi END");
            $table->time('jam_keluar');
            $table->string('alasan', 500);
            $table->enum('status', ['Menunggu', 'Disetujui', 'Ditolak'])->default('Menunggu');
            $table->string('catatan_keputusan', 500)->nullable();
            $table->integer('diajukan_oleh');
            $table->integer('diproses_oleh')->nullable();
            $table->timestamp('diproses_pada')->nullable();
            $table->timestamps();

            $table->unique('id_absensi_menunggu', 'uq_koreksi_check_out_menunggu');
            $table->index(['status', 'created_at'], 'idx_koreksi_check_out_status');
            $table->foreign('id_absensi', 'fk_koreksi_check_out_absensi')
                ->references('id')->on('tbl_absensi')->restrictOnDelete();
            $table->foreign('diajukan_oleh', 'fk_koreksi_check_out_pengaju')
                ->references('id')->on('tbl_users')->restrictOnDelete();
            $table->foreign('diproses_oleh', 'fk_koreksi_check_out_pemroses')
                ->references('id')->on('tbl_users')->restrictOnDelete();
        });

        if ($mysql) {
            foreach (range(1, 7) as $tahap) {
                SqlKomponen::jalankanBlok(self::FILE_SQL, $tahap);
            }

            SqlKomponen::pasangHakAkses();
        }
    }

    public function down(): void
    {
        $mysql = $this->mysql();

        if (DB::table('tbl_jadwal_kerja')->whereNotNull('id_karyawan_pengganti')->exists()) {
            throw new LogicException('Rollback ditolak: tbl_jadwal_kerja sudah berisi penugasan backup shift (id_karyawan_pengganti).');
        }

        if ($mysql) {
            foreach (range(7, 1) as $tahap) {
                SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, $tahap);
            }
        }

        Schema::dropIfExists('tbl_koreksi_check_out');

        Schema::table('tbl_jadwal_kerja', function (Blueprint $table) use ($mysql): void {
            $table->dropForeign($mysql ? 'fk_jadwal_pengganti' : ['id_karyawan_pengganti']);
            $table->dropIndex('idx_jadwal_pengganti_tanggal');
            $table->dropColumn('id_karyawan_pengganti');
        });

        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->index('id_karyawan_rekan', 'fk_pertukaran_rekan');
        });

        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->dropUnique('uq_pertukaran_jadwal_aktif');
            $table->dropIndex('idx_pertukaran_rekan');
            $table->dropColumn('id_jadwal_aktif');
        });

        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->unsignedInteger('id_jadwal_rekan')->nullable()->after('id_jadwal_pengaju');
            $table->date('tanggal_kerja_rekan')->nullable()->after('tanggal_kerja');
            $table->json('snapshot_rekan')->nullable()->after('snapshot_pengaju');
            $table->foreign('id_jadwal_rekan', 'fk_pertukaran_jadwal_rekan')
                ->references('id')->on('tbl_jadwal_kerja')->restrictOnDelete();
        });

        // Skema lama mewajibkan diproses_oleh untuk status selain Menunggu. Pengajuan yang diakhiri sistem
        // (kedaluwarsa, dibatalkan otomatis, ditolak rekan) kembali Menunggu; aplikasi lama menampilkannya
        // sebagai Kedaluwarsa begitu tanggal shift lewat.
        DB::table('tbl_pertukaran_shift')->where('status', '<>', 'Menunggu')->whereNull('diproses_oleh')
            ->update(['status' => 'Menunggu', 'diproses_pada' => null]);
        DB::table('tbl_pertukaran_shift')->where('status', 'Dibatalkan')
            ->update(['status' => 'Ditolak', 'catatan_keputusan' => 'Dibatalkan oleh pengaju.']);

        Schema::table('tbl_pertukaran_shift', function (Blueprint $table): void {
            $table->enum('status', self::STATUS_LAMA)->default('Menunggu')->change();
        });

        if ($mysql) {
            SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 8);
            SqlKomponen::pasangHakAkses();
        }
    }

    /**
     * Status eksplisit dan konsistensi tanggapan rekan pada data lama (model tukar 2 arah):
     * - pembatalan yang dulu ditandai lewat catatan menjadi status Dibatalkan;
     * - pengajuan tukar 2 arah yang masih Menunggu dibatalkan sistem karena alurnya sudah diganti;
     * - pengajuan lama yang sudah diputuskan Operasional dulu dibuat dengan pernyataan rekan sudah setuju,
     *   sehingga status_rekan = Diterima dengan waktu tanggapan = waktu pengajuan.
     */
    private function normalisasiDataPertukaran(): void
    {
        $sekarang = now();

        DB::table('tbl_pertukaran_shift')
            ->where('status', 'Ditolak')
            ->where('catatan_keputusan', 'Dibatalkan oleh pengaju.')
            ->update(['status' => 'Dibatalkan', 'catatan_keputusan' => null]);

        DB::table('tbl_pertukaran_shift')
            ->where('status', 'Menunggu')
            ->update([
                'status' => 'Dibatalkan',
                'catatan_keputusan' => 'Dibatalkan sistem: alur tukar shift dua arah diganti menjadi backup shift.',
                'diproses_oleh' => null,
                'diproses_pada' => $sekarang,
                'updated_at' => $sekarang,
            ]);

        DB::table('tbl_pertukaran_shift')
            ->where('status_rekan', 'Menunggu')
            ->whereIn('status', ['Disetujui', 'Ditolak'])
            ->update(['status_rekan' => 'Diterima']);

        DB::table('tbl_pertukaran_shift')
            ->where('status_rekan', '<>', 'Menunggu')
            ->whereNull('ditanggapi_rekan_pada')
            ->update(['ditanggapi_rekan_pada' => DB::raw('created_at')]);
    }

    private function mysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};

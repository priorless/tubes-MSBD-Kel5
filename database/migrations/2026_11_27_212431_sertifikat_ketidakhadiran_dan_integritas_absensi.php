<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Sertifikat, pengajuan ketidakhadiran oleh karyawan, batas koreksi check-out, dan konsistensi id_karyawan
 * dokumentasi absensi (sql/Komponen/23_sertifikat_ketidakhadiran_integritas.sql).
 *
 * - Berkas sertifikat dipindah dari disk public (bisa dibuka tanpa login) ke disk privat local.
 * - Tabel baru tbl_pengajuan_ketidakhadiran (Izin/Sakit/Cuti diajukan karyawan, diputuskan HRD).
 * - Bagian khusus MySQL (CHECK, trigger, procedure, view, FK komposit) ada di file SQL komponen.
 *
 * Hanya untuk MySQL/MariaDB: tabel inti (tbl_karyawan, tbl_absensi, tbl_sertifikat) tidak dibuat di SQLite.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/23_sertifikat_ketidakhadiran_integritas.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/23_sertifikat_ketidakhadiran_integritas_rollback.sql';

    private const string DISK_LAMA = 'public';

    private const string DISK_PRIVAT = 'local';

    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        Schema::create('tbl_pengajuan_ketidakhadiran', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('id_karyawan');
            $table->enum('jenis', ['Izin', 'Sakit', 'Cuti']);
            $table->date('tgl_mulai');
            $table->date('tgl_selesai');
            $table->string('alasan', 500);
            $table->string('file_bukti', 255)->nullable();
            $table->enum('status', ['Menunggu', 'Disetujui', 'Ditolak', 'Dibatalkan'])->default('Menunggu');
            $table->string('catatan_keputusan', 500)->nullable();
            $table->integer('diproses_oleh')->nullable();
            $table->timestamp('diproses_pada')->nullable();
            $table->timestamps();

            $table->index(['id_karyawan', 'tgl_mulai'], 'idx_pengajuan_ketidakhadiran_karyawan');
            $table->index(['status', 'created_at'], 'idx_pengajuan_ketidakhadiran_status');
            $table->foreign('id_karyawan', 'fk_pengajuan_ketidakhadiran_karyawan')
                ->references('id')->on('tbl_karyawan')->restrictOnDelete();
            $table->foreign('diproses_oleh', 'fk_pengajuan_ketidakhadiran_pemroses')
                ->references('id')->on('tbl_users')->restrictOnDelete();
        });

        foreach (range(1, 4) as $tahap) {
            SqlKomponen::jalankanBlok(self::FILE_SQL, $tahap);
        }

        SqlKomponen::pasangHakAkses();

        $this->pindahkanBerkasSertifikat(self::DISK_LAMA, self::DISK_PRIVAT);
    }

    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        if (DB::table('tbl_absensi')->where('status_kehadiran', 'Cuti')->exists()) {
            throw new LogicException("Rollback ditolak: tbl_absensi sudah berisi status_kehadiran 'Cuti'.");
        }

        foreach (range(4, 1) as $tahap) {
            SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, $tahap);
        }

        Schema::dropIfExists('tbl_pengajuan_ketidakhadiran');

        SqlKomponen::pasangHakAkses();

        $this->pindahkanBerkasSertifikat(self::DISK_PRIVAT, self::DISK_LAMA);
    }

    /**
     * Pindahkan berkas sertifikat yang tercatat di tbl_sertifikat.file_upload antar-disk (path tetap sama).
     */
    private function pindahkanBerkasSertifikat(string $dari, string $ke): void
    {
        $asal = Storage::disk($dari);
        $tujuan = Storage::disk($ke);

        DB::table('tbl_sertifikat')->whereNotNull('file_upload')->pluck('file_upload')
            ->each(function (string $path) use ($asal, $tujuan): void {
                if (! $asal->exists($path)) {
                    return;
                }

                if (! $tujuan->exists($path)) {
                    $tujuan->put($path, $asal->get($path));
                }

                $asal->delete($path);
            });
    }

    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_sertifikat')
            && Schema::hasTable('tbl_absensi');
    }
};

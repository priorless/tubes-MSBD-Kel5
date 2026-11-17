<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keterlambatan & indikasi lembur memakai jadwal harian (sql/Komponen/13_jadwal_absensi_dari_roster.sql).
 *
 * - fn_jadwal_absensi(id_penempatan, tgl, jenis): jadwal Kerja harian -> Libur (NULL) -> jam penempatan
 *   bila jadwal harian belum diatur. fn_sumber_jadwal_absensi memberi label asal jadwal.
 * - trg_validasi_absen_masuk & trg_auto_hitung_lembur memakai tanggal absensi (tbl_absensi.tgl).
 * - v_absensi_harian & v_penugasan_aktif menampilkan jadwal efektif + kolom sumber_jadwal.
 * Tidak ada tabel/kolom yang diubah dan data absensi lama tidak dihitung ulang.
 */
return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/13_jadwal_absensi_dari_roster.sql';

    private const string FILE_ROLLBACK = 'sql/Komponen/13_jadwal_absensi_dari_roster_rollback.sql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        SqlKomponen::jalankanBlok(self::FILE_SQL, 1);
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

        SqlKomponen::jalankanBlok(self::FILE_ROLLBACK, 1);
        SqlKomponen::pasangHakAkses();
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_jadwal_kerja')
            && Schema::hasColumn('tbl_absensi', 'status_lembur')
            && Schema::hasColumn('tbl_penempatan', 'jam_datang');
    }
};

<?php

use App\Support\SqlKomponen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string FILE_SQL = 'sql/Komponen/12_rotasi_tanggal_kontrak.sql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        $tahap = Schema::hasColumn('tbl_kontrak', 'tunjangan') ? 1 : 2;

        SqlKomponen::jalankanBlok(self::FILE_SQL, $tahap);
        SqlKomponen::pasangHakAkses();
    }

    /**
     * The previous procedure definition differs between deployed databases.
     */
    public function down(): void
    {
        throw new LogicException('Perubahan prosedur rotasi bersifat forward-only; gunakan migration korektif yang baru.');
    }

    private function skemaTersedia(): bool
    {
        return DB::getDriverName() === 'mysql'
            && Schema::hasTable('tbl_penempatan')
            && Schema::hasTable('tbl_klien')
            && Schema::hasTable('tbl_kontrak')
            && Schema::hasColumn('tbl_penempatan', 'jam_datang')
            && Schema::hasColumn('tbl_penempatan', 'jam_pulang')
            && Schema::hasColumn('tbl_penempatan', 'tgl_mulai')
            && Schema::hasColumn('tbl_kontrak', 'id_penempatan')
            && Schema::hasColumn('tbl_kontrak', 'gaji_pokok');
    }
};

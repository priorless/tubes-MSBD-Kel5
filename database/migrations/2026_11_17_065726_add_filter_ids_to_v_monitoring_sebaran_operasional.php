<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v_monitoring_sebaran_operasional untuk filter Monitoring Sebaran per klien dan per jenis layanan.
 *
 * - Semua kolom lama tetap ada dengan urutan yang sama; id_klien dan id_jenis_layanan ditambahkan di akhir
 *   (filter memakai id karena nama perusahaan tidak unik).
 * - tbl_jenis_layanan di-LEFT JOIN: karyawan lapangan yang belum diberi jenis layanan oleh HRD tetap
 *   terhitung (nama_layanan NULL), sehingga jumlah sama dengan penempatan Aktif yang sebenarnya.
 * - Isi baris tetap hanya penempatan berstatus Aktif. Status Belum Mulai / Berjalan / Lewat Kontrak
 *   dihitung aplikasi dari tanggal.
 *
 * GRANT SELECT pada view (file 10) tetap berlaku setelah CREATE OR REPLACE VIEW.
 * down() mengembalikan definisi dari migrasi 2026_11_15_052355.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW v_monitoring_sebaran_operasional AS
            SELECT p.id AS id_penempatan, k.nip, k.nama, jl.nama_layanan,
                   kl.nama_perusahaan, p.lokasi_kerja, p.jam_datang, p.jam_pulang,
                   p.tgl_mulai, p.tgl_selesai,
                   IFNULL(DATEDIFF(p.tgl_selesai, CURDATE()), 9999) AS sisa_hari_kontrak,
                   p.id_klien, k.id_jenis_layanan
            FROM tbl_penempatan p
            JOIN tbl_karyawan k ON p.id_karyawan = k.id
            LEFT JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
            JOIN tbl_klien kl ON p.id_klien = kl.id
            WHERE p.status = 'Aktif'
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->skemaTersedia()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW v_monitoring_sebaran_operasional AS
            SELECT p.id AS id_penempatan, k.nip, k.nama, jl.nama_layanan,
                   kl.nama_perusahaan, p.lokasi_kerja, p.jam_datang, p.jam_pulang,
                   p.tgl_mulai, p.tgl_selesai,
                   IFNULL(DATEDIFF(p.tgl_selesai, CURDATE()), 9999) AS sisa_hari_kontrak
            FROM tbl_penempatan p
            JOIN tbl_karyawan k ON p.id_karyawan = k.id
            JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
            JOIN tbl_klien kl ON p.id_klien = kl.id
            WHERE p.status = 'Aktif'
            SQL);
    }

    /** Khusus MySQL/MariaDB db_sji yang sudah memakai jadwal penempatan (kolom jam_datang/jam_pulang). */
    private function skemaTersedia(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('tbl_penempatan')
            && Schema::hasTable('tbl_karyawan')
            && Schema::hasTable('tbl_jenis_layanan')
            && Schema::hasTable('tbl_klien')
            && Schema::hasColumn('tbl_penempatan', 'jam_datang');
    }
};

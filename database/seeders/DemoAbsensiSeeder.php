<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data demo absensi foto: klien + karyawan dummy yang ditempatkan dengan jadwal kerja penempatan.
 *
 * Memakai alur aslinya: KaryawanDummySeeder (akun dibuat trigger trg_auto_akun_karyawan,
 * username & password awal = NIP) lalu CALL sp_assign_penempatan (modul Operasional).
 * Aman dijalankan berulang.
 *
 *   php artisan db:seed --class=DemoAbsensiSeeder
 */
class DemoAbsensiSeeder extends Seeder
{
    public const string KodeKlien = 'DEMO01';

    /** @var array<string, array{lokasi: string, jam_datang: string, jam_pulang: string}> NIP => penempatan */
    private const array Penempatan = [
        'DUMMY-LAP-001' => ['lokasi' => 'Pos jaga lobi utama', 'jam_datang' => '07:00', 'jam_pulang' => '15:00'],
        'DUMMY-LAP-002' => ['lokasi' => 'Pool kendaraan basement', 'jam_datang' => '08:00', 'jam_pulang' => '16:00'],
        'DUMMY-LAP-003' => ['lokasi' => 'Gedung kantor lantai 1-3', 'jam_datang' => '06:00', 'jam_pulang' => '14:00'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(KaryawanDummySeeder::class);

        DB::table('tbl_klien')->updateOrInsert(['kode_klien' => self::KodeKlien], [
            'nama_perusahaan' => 'PT Demo Sejahtera',
            'alamat' => 'Jl. Balai Kota No. 1, Medan',
            'pic_nama' => 'Budi Demo',
            'pic_jabatan' => 'Facility Manager',
            'pic_telp' => '061000000',
            'status' => 'Aktif',
        ]);
        $idKlien = (int) DB::table('tbl_klien')->where('kode_klien', self::KodeKlien)->value('id');

        foreach (self::Penempatan as $nip => $penempatan) {
            $karyawan = DB::table('tbl_karyawan')->where('nip', $nip)->first(['id', 'status']);
            $sudahDitempatkan = $karyawan && DB::table('tbl_penempatan')
                ->where('id_karyawan', $karyawan->id)
                ->where('status', 'Aktif')
                ->exists();

            if (! $karyawan || $sudahDitempatkan || $karyawan->status !== 'Tersedia') {
                continue;
            }

            DB::statement('CALL sp_assign_penempatan(?, ?, CURDATE(), NULL, ?, ?, ?, NULL)', [
                $karyawan->id, $idKlien, $penempatan['lokasi'], $penempatan['jam_datang'], $penempatan['jam_pulang'],
            ]);
        }
    }
}

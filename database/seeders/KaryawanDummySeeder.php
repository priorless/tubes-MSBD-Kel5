<?php

namespace Database\Seeders;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KaryawanDummySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $daftarKaryawan = [
            [
                'nip' => 'DUMMY-LAP-001',
                'nama' => 'Bima Pratama',
                'kategori' => 'Lapangan',
                'id_jenis_layanan' => 1,
                'tunjangan' => 0,
                'no_ktp' => '3276011501900001',
                'tempat_lahir' => 'Bandung',
                'tgl_lahir' => '1990-01-15',
                'jenis_kelamin' => 'L',
                'alamat' => 'Jl. Contoh No. 1, Bandung',
                'no_telp' => '081234567801',
                'email' => 'dummy.satpam.001@example.test',
                'status' => 'Tersedia',
                'rating' => 0,
                'tgl_masuk' => '2025-01-15',
            ],
            [
                'nip' => 'DUMMY-LAP-002',
                'nama' => 'Andi Saputra',
                'kategori' => 'Lapangan',
                'id_jenis_layanan' => 2,
                'tunjangan' => 0,
                'no_ktp' => '3276021602910002',
                'tempat_lahir' => 'Bogor',
                'tgl_lahir' => '1991-02-16',
                'jenis_kelamin' => 'L',
                'alamat' => 'Jl. Contoh No. 2, Bogor',
                'no_telp' => '081234567802',
                'email' => 'dummy.driver.002@example.test',
                'status' => 'Tersedia',
                'rating' => 0,
                'tgl_masuk' => '2025-02-16',
            ],
            [
                'nip' => 'DUMMY-LAP-003',
                'nama' => 'Rani Puspita',
                'kategori' => 'Lapangan',
                'id_jenis_layanan' => 3,
                'tunjangan' => 0,
                'no_ktp' => '3276031703920003',
                'tempat_lahir' => 'Depok',
                'tgl_lahir' => '1992-03-17',
                'jenis_kelamin' => 'P',
                'alamat' => 'Jl. Contoh No. 3, Depok',
                'no_telp' => '081234567803',
                'email' => 'dummy.cleaning.003@example.test',
                'status' => 'Tersedia',
                'rating' => 0,
                'tgl_masuk' => '2025-03-17',
            ],
        ];

        DB::transaction(function () use ($daftarKaryawan): void {
            foreach ($daftarKaryawan as $karyawan) {
                $identitasSudahAda = DB::table('tbl_karyawan')
                    ->where(function (Builder $query) use ($karyawan): void {
                        $query->where('nip', $karyawan['nip'])
                            ->orWhere('no_ktp', $karyawan['no_ktp'])
                            ->orWhere('email', $karyawan['email']);
                    })
                    ->exists();
                $akunSudahAda = DB::table('tbl_users')
                    ->where('username', $karyawan['nip'])
                    ->exists();

                if ($identitasSudahAda || $akunSudahAda) {
                    continue;
                }

                DB::table('tbl_karyawan')->insert($karyawan);
            }
        });
    }
}

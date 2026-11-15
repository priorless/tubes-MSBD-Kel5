<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KlienDummySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $daftarKlien = [
            [
                'kode_klien' => 'KLN-DMY001',
                'nama_perusahaan' => 'PT Sinar Abadi Logistik',
                'alamat' => 'Jl. Soekarno-Hatta No. 588, Batununggal, Kota Bandung, Jawa Barat',
                'pic_nama' => 'Rizky Firmansyah',
                'pic_jabatan' => 'Manajer Operasional',
                'pic_telp' => '081234567801',
                'pic_email' => 'rizky.firmansyah@sinarabadilogistik.co.id',
                'status' => 'Aktif',
            ],
            [
                'kode_klien' => 'KLN-DMY002',
                'nama_perusahaan' => 'PT Cipta Karya Nusantara',
                'alamat' => 'Jl. Margonda Raya No. 215, Kemiri Muka, Kota Depok, Jawa Barat',
                'pic_nama' => 'Dewi Anggraini',
                'pic_jabatan' => 'Kepala HRD',
                'pic_telp' => '081234567802',
                'pic_email' => 'dewi.anggraini@ciptakaryanusantara.co.id',
                'status' => 'Aktif',
            ],
            [
                'kode_klien' => 'KLN-DMY003',
                'nama_perusahaan' => 'PT Mitra Pangan Sejahtera',
                'alamat' => 'Jl. Raya Bogor KM 28 No. 16, Pekayon, Kota Bekasi, Jawa Barat',
                'pic_nama' => 'Fajar Maulana',
                'pic_jabatan' => 'Direktur Administrasi',
                'pic_telp' => '081234567803',
                'pic_email' => 'fajar.maulana@mitrapangan.co.id',
                'status' => 'Nonaktif',
            ],
            [
                'kode_klien' => 'KLN-DMY004',
                'nama_perusahaan' => 'PT Graha Teknologi Mandiri',
                'alamat' => 'Jl. Jenderal Sudirman Kav. 52-53, Senayan, Jakarta Selatan, DKI Jakarta',
                'pic_nama' => 'Nadia Putri Lestari',
                'pic_jabatan' => 'Procurement Manager',
                'pic_telp' => '081234567804',
                'pic_email' => 'nadia.lestari@grahateknologi.co.id',
                'status' => 'Aktif',
            ],
            [
                'kode_klien' => 'KLN-DMY005',
                'nama_perusahaan' => 'PT Bumi Hijau Properti',
                'alamat' => 'Jl. Ring Road Utara No. 88, Condongcatur, Kabupaten Sleman, DI Yogyakarta',
                'pic_nama' => 'Agus Setiawan',
                'pic_jabatan' => 'General Manager',
                'pic_telp' => '081234567805',
                'pic_email' => 'agus.setiawan@bumihijauproperti.co.id',
                'status' => 'Nonaktif',
            ],
        ];

        DB::transaction(function () use ($daftarKlien): void {
            foreach ($daftarKlien as $klien) {
                $kodeKlienSudahAda = DB::table('tbl_klien')
                    ->where('kode_klien', $klien['kode_klien'])
                    ->exists();

                if ($kodeKlienSudahAda) {
                    continue;
                }

                DB::table('tbl_klien')->insert($klien);
            }
        });
    }
}

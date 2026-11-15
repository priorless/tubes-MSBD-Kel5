<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Membuat user MySQL per bagian dan memasangkan role dari sql/Komponen/10_hak_akses_database.sql.
 *
 * Password dibuat acak dan HANYA ditampilkan sekali di terminal (tidak disimpan di repo/.env).
 * Karyawan Lapangan sengaja tidak punya user MySQL (lihat penjelasan di file SQL 10).
 */
#[Signature('sji:buat-user-db
    {bagian=semua : hrd, operasional, keuangan, ceo, atau semua}
    {--host=localhost : Host asal koneksi user MySQL (mis. localhost atau %)}
    {--reset : Buat password baru untuk user yang sudah ada}')]
#[Description('Membuat user MySQL per bagian (hrd/operasional/keuangan/ceo) dengan role hak akses dan password acak.')]
class BuatUserDatabase extends Command
{
    /** @var array<string, string> bagian => role */
    private const array ROLE_PER_BAGIAN = [
        'hrd' => 'sji_role_hrd',
        'operasional' => 'sji_role_operasional',
        'keuangan' => 'sji_role_keuangan',
        'ceo' => 'sji_role_ceo',
    ];

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->error('Perintah ini hanya untuk database MySQL.');

            return self::FAILURE;
        }

        $bagian = strtolower((string) $this->argument('bagian'));
        $daftarBagian = $bagian === 'semua' ? array_keys(self::ROLE_PER_BAGIAN) : [$bagian];

        if (array_diff($daftarBagian, array_keys(self::ROLE_PER_BAGIAN)) !== []) {
            $this->error('Bagian tidak dikenal. Pilih: '.implode(', ', array_keys(self::ROLE_PER_BAGIAN)).', atau semua.');

            return self::FAILURE;
        }

        $host = (string) $this->option('host');
        if (preg_match('/^[A-Za-z0-9.%_-]{1,60}$/', $host) !== 1) {
            $this->error('Host tidak valid.');

            return self::FAILURE;
        }

        $hasil = [];

        foreach ($daftarBagian as $namaBagian) {
            $role = self::ROLE_PER_BAGIAN[$namaBagian];

            if (! $this->akunAda($role, '%')) {
                $this->error("Role {$role} belum ada. Jalankan dulu: php artisan migrate");

                return self::FAILURE;
            }

            $user = 'sji_'.$namaBagian;
            $sudahAda = $this->akunAda($user, $host);

            if ($sudahAda && ! $this->option('reset')) {
                $hasil[] = [$user, $host, $role, '(sudah ada — pakai --reset untuk password baru)'];

                continue;
            }

            $password = $this->buatPassword();
            $akun = $this->kutip($user).'@'.$this->kutip($host);

            DB::unprepared(($sudahAda ? 'ALTER USER ' : 'CREATE USER ').$akun.' IDENTIFIED BY '.$this->kutip($password));
            DB::unprepared('GRANT '.$this->kutip($role).' TO '.$akun);
            DB::unprepared('SET DEFAULT ROLE '.$this->kutip($role).' TO '.$akun);

            $hasil[] = [$user, $host, $role, $password];
        }

        $this->table(['User MySQL', 'Host', 'Role', 'Password'], $hasil);
        $this->warn('Simpan password di atas sekarang: password tidak disimpan di mana pun dan tidak bisa ditampilkan ulang.');
        $this->line('Cek hak akses: SHOW GRANTS FOR \'sji_hrd\'@\''.$host.'\' USING \'sji_role_hrd\';');

        return self::SUCCESS;
    }

    private function akunAda(string $user, string $host): bool
    {
        return DB::table('mysql.user')->where('User', $user)->where('Host', $host)->exists();
    }

    /**
     * Password acak 20 karakter: huruf besar, kecil, angka, dan simbol (lolos validate_password).
     * Simbol dibatasi agar aman disalin ke .env, shell, HeidiSQL, dan tabel terminal
     * (tanpa # $ | \ " ' ` spasi).
     */
    private function buatPassword(): string
    {
        $kelompok = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '-_.@+='];
        $semua = implode('', $kelompok);

        $karakter = array_map(fn (string $isi) => $isi[random_int(0, strlen($isi) - 1)], $kelompok);
        while (count($karakter) < 20) {
            $karakter[] = $semua[random_int(0, strlen($semua) - 1)];
        }

        return implode('', Arr::shuffle($karakter));
    }

    private function kutip(string $nilai): string
    {
        return DB::getPdo()->quote($nilai);
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Uji restore: memulihkan file backup ke database UJI terpisah (bukan database aplikasi), lalu mencocokkan
 * jumlah baris tabel utama dengan database aplikasi. Database uji dibuat ulang setiap kali perintah dijalankan.
 */
#[Signature('sji:restore-db
    {file : File backup (.sql); nama file di storage/app/backup atau path lengkap}
    {--database=db_sji_restore : Database uji tujuan restore (tidak boleh database aplikasi)}')]
#[Description('Restore backup ke database uji terpisah lalu mencocokkan jumlah baris tabel utama.')]
class RestoreDatabase extends Command
{
    /** Tabel utama yang jumlah barisnya dicocokkan setelah restore. */
    public const array TABEL_UTAMA = [
        'tbl_users', 'tbl_karyawan', 'tbl_klien', 'tbl_penempatan', 'tbl_kontrak', 'tbl_jadwal_kerja',
        'tbl_absensi', 'tbl_payroll', 'tbl_invoice', 'tbl_sertifikat', 'tbl_log_aktivitas',
    ];

    private const string KONEKSI_UJI = 'sji_restore_uji';

    public function handle(): int
    {
        $koneksi = config('database.connections.'.config('database.default'));

        if (! in_array($koneksi['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('Perintah ini hanya untuk database MySQL.');

            return self::FAILURE;
        }

        $tujuan = (string) $this->option('database');
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $tujuan) !== 1) {
            $this->error('Nama database tujuan tidak valid.');

            return self::FAILURE;
        }
        if (strcasecmp($tujuan, (string) $koneksi['database']) === 0) {
            $this->error("Restore ke database aplikasi ({$koneksi['database']}) ditolak. Pakai database uji terpisah.");

            return self::FAILURE;
        }

        $file = $this->cariFile((string) $this->argument('file'));
        if ($file === null) {
            $this->error('File backup tidak ditemukan.');

            return self::FAILURE;
        }

        DB::statement("DROP DATABASE IF EXISTS `{$tujuan}`");
        DB::statement("CREATE DATABASE `{$tujuan}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $hasil = Process::env(['MYSQL_PWD' => (string) ($koneksi['password'] ?? '')])
            ->timeout(1200)
            ->input(fopen($file, 'r'))
            ->run([
                config('database.backup.mysql'),
                '--host='.$koneksi['host'],
                '--port='.$koneksi['port'],
                '--user='.$koneksi['username'],
                '--default-character-set=utf8mb4',
                $tujuan,
            ]);

        if ($hasil->failed()) {
            $this->error('Restore gagal: '.trim($hasil->errorOutput()));

            return self::FAILURE;
        }

        // Database uji hanya untuk diperiksa: event (mis. ev_update_status_sertifikat) jangan ikut berjalan di sana.
        foreach (DB::select('SELECT EVENT_NAME AS nama FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?', [$tujuan]) as $event) {
            DB::statement("ALTER EVENT `{$tujuan}`.`{$event->nama}` DISABLE");
        }

        $this->info("Restore {$file} ke database {$tujuan} selesai. Mencocokkan jumlah baris...");

        return $this->cocokkanJumlahBaris($koneksi, $tujuan) ? self::SUCCESS : self::FAILURE;
    }

    private function cariFile(string $file): ?string
    {
        foreach ([$file, config('database.backup.folder').DIRECTORY_SEPARATOR.$file] as $kandidat) {
            if (File::isFile($kandidat)) {
                return $kandidat;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $koneksi
     */
    private function cocokkanJumlahBaris(array $koneksi, string $tujuan): bool
    {
        config(['database.connections.'.self::KONEKSI_UJI => [...$koneksi, 'database' => $tujuan]]);
        DB::purge(self::KONEKSI_UJI);

        $baris = [];
        $semuaCocok = true;

        foreach (self::TABEL_UTAMA as $tabel) {
            $asli = $this->hitung(DB::connection(), $tabel);
            $pulih = $this->hitung(DB::connection(self::KONEKSI_UJI), $tabel);
            $cocok = $asli === $pulih;
            $semuaCocok = $semuaCocok && $cocok;
            $baris[] = [$tabel, $asli ?? '-', $pulih ?? '-', $cocok ? 'Cocok' : 'BEDA'];
        }

        $this->table(['Tabel', 'Database aplikasi', "Hasil restore ({$tujuan})", 'Status'], $baris);
        DB::purge(self::KONEKSI_UJI);

        if ($semuaCocok) {
            $this->info('Semua tabel utama cocok. Backup dapat dipulihkan.');
        } else {
            $this->warn('Ada tabel yang berbeda. Wajar bila data berubah setelah backup dibuat; bila tidak, periksa file backup.');
        }

        return $semuaCocok;
    }

    private function hitung(Connection $koneksi, string $tabel): ?int
    {
        try {
            return (int) $koneksi->table($tabel)->count();
        } catch (Throwable) {
            return null;
        }
    }
}

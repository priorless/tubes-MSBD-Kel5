<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Backup lengkap database aplikasi memakai mysqldump: data, view, routine, trigger, event, dan CHECK constraint.
 *
 * File: storage/app/backup/{database}_YYYYmmdd_HHMMSS.sql. Backup yang lebih tua dari 7 hari dihapus.
 * Password dikirim lewat variabel lingkungan MYSQL_PWD agar tidak tampil di daftar proses.
 */
#[Signature('sji:backup-db')]
#[Description('Backup database (mysqldump --single-transaction --routines --triggers --events) ke storage/app/backup.')]
class BackupDatabase extends Command
{
    public function handle(): int
    {
        $koneksi = config('database.connections.'.config('database.default'));

        if (! in_array($koneksi['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('Perintah ini hanya untuk database MySQL.');

            return self::FAILURE;
        }

        $folder = config('database.backup.folder');
        File::ensureDirectoryExists($folder);
        $file = $folder.DIRECTORY_SEPARATOR.$koneksi['database'].'_'.now()->format('Ymd_His').'.sql';

        $hasil = Process::env(['MYSQL_PWD' => (string) ($koneksi['password'] ?? '')])
            ->timeout(600)
            ->run([
                config('database.backup.mysqldump'),
                '--host='.$koneksi['host'],
                '--port='.$koneksi['port'],
                '--user='.$koneksi['username'],
                '--single-transaction',
                '--routines',
                '--triggers',
                '--events',
                '--default-character-set=utf8mb4',
                '--result-file='.$file,
                $koneksi['database'],
            ]);

        if ($hasil->failed()) {
            File::delete($file);
            $this->error('Backup gagal: '.trim($hasil->errorOutput()));

            return self::FAILURE;
        }

        $this->info('Backup tersimpan: '.$file);
        $this->hapusBackupLama($folder);

        return self::SUCCESS;
    }

    private function hapusBackupLama(string $folder): void
    {
        $batas = now()->subDays((int) config('database.backup.simpan_hari'))->getTimestamp();

        foreach (File::glob($folder.DIRECTORY_SEPARATOR.'*.sql') as $file) {
            if (File::lastModified($file) < $batas) {
                File::delete($file);
                $this->line('Backup lama dihapus: '.basename($file));
            }
        }
    }
}

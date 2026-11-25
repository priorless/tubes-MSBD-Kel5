<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * sji:backup-db dan sji:restore-db. Program mysqldump/mysql dipalsukan (Process::fake), jadi tidak menyentuh MySQL.
 */
class BackupRestoreDatabaseTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/backup-'.uniqid());
        File::ensureDirectoryExists($this->folder);
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'db_sji',
            'database.connections.mysql.password' => 'rahasia',
            'database.backup.folder' => $this->folder,
            'database.backup.mysqldump' => 'mysqldump',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    public function test_backup_menjalankan_mysqldump_lengkap_dan_menghapus_backup_lebih_dari_tujuh_hari(): void
    {
        Process::fake();
        $lama = $this->folder.'/db_sji_lama.sql';
        $baru = $this->folder.'/db_sji_baru.sql';
        File::put($lama, '-- lama');
        File::put($baru, '-- baru');
        touch($lama, now()->subDays(8)->getTimestamp());
        touch($baru, now()->subDays(6)->getTimestamp());

        $this->artisan('sji:backup-db')->assertSuccessful();

        Process::assertRan(function (PendingProcess $process): bool {
            $perintah = $process->command;

            return $perintah[0] === 'mysqldump'
                && array_intersect(['--single-transaction', '--routines', '--triggers', '--events'], $perintah) === ['--single-transaction', '--routines', '--triggers', '--events']
                && end($perintah) === 'db_sji'
                && str_starts_with((string) collect($perintah)->first(fn (string $arg) => str_starts_with($arg, '--result-file=')), '--result-file='.$this->folder)
                && $process->environment === ['MYSQL_PWD' => 'rahasia']
                && ! in_array('rahasia', $perintah, true);
        });
        $this->assertFileDoesNotExist($lama);
        $this->assertFileExists($baru);
    }

    public function test_backup_gagal_tidak_meninggalkan_file_dan_mengembalikan_gagal(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Access denied', exitCode: 2)]);

        $this->artisan('sji:backup-db')
            ->expectsOutputToContain('Backup gagal: Access denied')
            ->assertFailed();

        $this->assertSame([], File::glob($this->folder.'/*.sql'));
    }

    public function test_restore_ke_database_aplikasi_ditolak(): void
    {
        Process::fake();
        File::put($this->folder.'/cadangan.sql', '-- isi');

        $this->artisan('sji:restore-db', ['file' => 'cadangan.sql', '--database' => 'DB_SJI'])
            ->expectsOutputToContain('Restore ke database aplikasi (db_sji) ditolak')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_restore_menolak_nama_database_tidak_valid_dan_file_yang_tidak_ada(): void
    {
        Process::fake();

        $this->artisan('sji:restore-db', ['file' => 'cadangan.sql', '--database' => 'uji`; DROP DATABASE db_sji; --'])
            ->expectsOutputToContain('Nama database tujuan tidak valid.')
            ->assertFailed();
        $this->artisan('sji:restore-db', ['file' => 'tidak-ada.sql'])
            ->expectsOutputToContain('File backup tidak ditemukan.')
            ->assertFailed();

        Process::assertNothingRan();
    }
}

<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\QueryException;

/**
 * Menerjemahkan error dari komponen database (trigger/procedure) menjadi pesan notifikasi.
 */
trait MenanganiSignalDatabase
{
    /**
     * Ambil pesan SIGNAL SQLSTATE '45000' dari exception, atau null bila bukan error aturan bisnis.
     */
    protected function pesanSignalDatabase(QueryException $exception): ?string
    {
        if ($this->sqlStateDari($exception) !== '45000') {
            return null;
        }

        $errorInfo = $exception->errorInfo ?? [];
        $pesan = trim((string) preg_replace('/^\d+\s+/', '', (string) ($errorInfo[2] ?? '')));

        return $pesan === ''
            ? 'Operasi ditolak oleh aturan database.'
            : 'Operasi ditolak oleh aturan database: '.$pesan;
    }

    /**
     * Teks asli SIGNAL SQLSTATE '45000' (tanpa awalan), untuk ditampilkan di bawah field form; null bila bukan SIGNAL.
     */
    protected function teksSignalDatabase(QueryException $exception): ?string
    {
        if ($this->sqlStateDari($exception) !== '45000') {
            return null;
        }

        $pesan = trim((string) preg_replace('/^\d+\s+/', '', (string) ($exception->errorInfo[2] ?? '')));

        return $pesan === '' ? 'Operasi ditolak oleh aturan database.' : $pesan;
    }

    /**
     * Apakah exception disebabkan nilai unik yang sudah dipakai (MySQL 1062).
     */
    protected function dataDuplikat(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * Apakah baris tidak bisa dihapus karena masih dirujuk tabel lain (MySQL 1451).
     */
    protected function masihDirujuk(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1451;
    }

    private function sqlStateDari(QueryException $exception): string
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode());
    }
}

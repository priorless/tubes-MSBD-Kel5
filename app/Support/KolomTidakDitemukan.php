<?php

namespace App\Support;

use RuntimeException;

/**
 * Dilempar jika kolom yang dibutuhkan modul tidak ditemukan di database.
 * Pesannya aman ditampilkan ke pengguna (tidak berisi detail koneksi/stack trace).
 */
class KolomTidakDitemukan extends RuntimeException
{
}

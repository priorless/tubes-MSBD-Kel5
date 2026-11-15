<?php

namespace App\Support;

use RuntimeException;

/**
 * Dilempar jika foto absensi tidak dapat dibaca, diproses, atau disimpan.
 * Pesannya aman ditampilkan ke pengguna.
 */
class FotoAbsensiTidakValid extends RuntimeException {}

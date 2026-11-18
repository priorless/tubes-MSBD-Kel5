<?php

namespace App\Support;

use RuntimeException;

/**
 * Dilempar jika aturan absensi (jadwal shift, jam check-in, sesi, periode monitoring,
 * batas unggahan, P2H) tidak terpenuhi. Pesannya aman ditampilkan ke pengguna.
 */
class AbsensiDitolak extends RuntimeException {}

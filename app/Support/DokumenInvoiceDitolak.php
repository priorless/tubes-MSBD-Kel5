<?php

namespace App\Support;

use RuntimeException;

/**
 * Dilempar jika perubahan dokumen tagihan (teks surat / catatan manual) tidak boleh disimpan:
 * invoice sudah lunas, versi sudah berubah, atau catatan bukan milik invoice ini. Pesannya aman ditampilkan.
 */
class DokumenInvoiceDitolak extends RuntimeException {}

<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Menentukan id_karyawan milik orang yang sedang memakai aplikasi.
 *
 * SATU-SATUNYA tempat modul Tenaga Kerja mengetahui "siapa saya".
 *
 * Aturan keamanan: jika user SUDAH LOGIN, identitas HANYA diambil dari
 * kolom id_karyawan pada akunnya. Akun tanpa id_karyawan (mis. staf yang
 * dibuat lewat Manajemen User) menghasilkan null — TIDAK pernah jatuh ke
 * session atau TK_DEV_ID_KARYAWAN, agar data karyawan uji tidak terpakai.
 */
class KaryawanAktif
{
    public static function id(): ?int
    {
        // 1. Sudah login → hanya dari akun. Tidak ada fallback.
        if (Auth::check()) {
            /** @var \Illuminate\Database\Eloquent\Model $user */
            $user = Auth::user();
            $nilai = $user->getAttribute(config('tenaga_kerja.kolom_user_id_karyawan'));

            return is_numeric($nilai) ? (int) $nilai : null;
        }

        // 2. Belum login: dari session (jalur integrasi lama)
        $session = session(config('tenaga_kerja.session_key'));
        if (is_numeric($session)) {
            return (int) $session;
        }

        // 3. Belum login + mode pengembangan: hanya aktif di APP_ENV=local
        $dev = config('tenaga_kerja.dev_id_karyawan');
        if (app()->environment('local') && is_numeric($dev)) {
            return (int) $dev;
        }

        return null;
    }

    /** True jika identitas berasal dari mode pengembangan (.env), bukan login sungguhan. */
    public static function modeUjiCoba(): bool
    {
        return ! Auth::check()
            && ! is_numeric(session(config('tenaga_kerja.session_key')))
            && self::id() !== null;
    }

    /** True jika user sudah login, tetapi akunnya tidak terhubung ke data karyawan. */
    public static function akunTanpaKaryawan(): bool
    {
        return Auth::check() && self::id() === null;
    }
}

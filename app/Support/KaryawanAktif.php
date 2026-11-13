<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Menentukan id_karyawan milik orang yang sedang memakai aplikasi.
 *
 * SATU-SATUNYA tempat modul Tenaga Kerja mengetahui "siapa saya".
 * Saat fitur login selesai, cukup pastikan salah satu sumber di bawah
 * terisi — controller dan view tidak perlu diubah.
 */
class KaryawanAktif
{
    public static function id(): ?int
    {
        // 1. Dari user yang login (Auth) — jika model user punya kolom id_karyawan
        if (Auth::check()) {
            /** @var \Illuminate\Database\Eloquent\Model $user */
            $user = Auth::user();
            $nilai = $user->getAttribute(config('tenaga_kerja.kolom_user_id_karyawan'));
            
            if (is_numeric($nilai)) {
                return (int) $nilai;
            }
        }

        // 2. Dari session (bisa diisi oleh fitur login buatan anggota tim lain)
        $session = session(config('tenaga_kerja.session_key'));
        if (is_numeric($session)) {
            return (int) $session;
        }

        // 3. Mode pengembangan: hanya aktif di APP_ENV=local
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
}

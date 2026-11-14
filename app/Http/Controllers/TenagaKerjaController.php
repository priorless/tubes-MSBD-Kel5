<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TenagaKerjaController extends Controller
{
    public function absensi(): View|RedirectResponse
    {
        $karyawan = $this->karyawanLogin();

        if ($karyawan === null) {
            return $this->karyawanTidakDitemukan();
        }

        $absensiHariIni = DB::table('tbl_absensi')
            ->where('id_karyawan', $karyawan->id)
            ->whereRaw('tgl = CURDATE()')
            ->first();

        return view('tenaga-kerja.absensi', compact('karyawan', 'absensiHariIni'));
    }

    public function checkIn(): RedirectResponse
    {
        $karyawan = $this->karyawanLogin();

        if ($karyawan === null) {
            return $this->karyawanTidakDitemukan();
        }

        try {
            DB::insert(
                'INSERT INTO tbl_absensi (id_karyawan, tgl, jam_masuk) VALUES (?, CURDATE(), CURTIME())',
                [$karyawan->id],
            );
        } catch (QueryException $exception) {
            if (! $this->isSqlState45000($exception)) {
                throw $exception;
            }

            return back()->with('error', $this->sqlMessage($exception));
        }

        return redirect()->route('tenaga-kerja.absensi')->with('success', 'Check-in berhasil dicatat.');
    }

    public function checkOut(): RedirectResponse
    {
        $karyawan = $this->karyawanLogin();

        if ($karyawan === null) {
            return $this->karyawanTidakDitemukan();
        }

        try {
            $jumlahBaris = DB::table('tbl_absensi')
                ->where('id_karyawan', $karyawan->id)
                ->whereRaw('tgl = CURDATE()')
                ->whereNull('jam_keluar')
                ->update(['jam_keluar' => DB::raw('CURTIME()')]);
        } catch (QueryException $exception) {
            if (! $this->isSqlState45000($exception)) {
                throw $exception;
            }

            return back()->with('error', $this->sqlMessage($exception));
        }

        if ($jumlahBaris === 0) {
            return back()->with('error', 'Absensi hari ini tidak ditemukan atau check-out sudah dicatat.');
        }

        return redirect()->route('tenaga-kerja.absensi')->with('success', 'Check-out berhasil dicatat.');
    }

    public function riwayatAbsensi(): View|RedirectResponse
    {
        $karyawan = $this->karyawanLogin();

        if ($karyawan === null) {
            return $this->karyawanTidakDitemukan();
        }

        $riwayat = DB::table('v_rekap_absensi_pribadi')
            ->where('id_karyawan', $karyawan->id)
            ->orderByDesc('tahun')
            ->orderByDesc('bulan')
            ->get();

        return view('tenaga-kerja.riwayat-absensi', compact('karyawan', 'riwayat'));
    }

    public function slipGaji(): View|RedirectResponse
    {
        $karyawan = $this->karyawanLogin();

        if ($karyawan === null) {
            return $this->karyawanTidakDitemukan();
        }

        $slipGaji = DB::table('v_slip_gaji_detail')
            ->where('nip', $karyawan->nip)
            ->orderByDesc('periode_tahun')
            ->orderByDesc('periode_bulan')
            ->get();

        return view('tenaga-kerja.slip-gaji', compact('karyawan', 'slipGaji'));
    }

    public function profil(): View|RedirectResponse
    {
        $karyawan = $this->karyawanLogin();

        if ($karyawan === null) {
            return $this->karyawanTidakDitemukan();
        }

        return view('tenaga-kerja.profil', compact('karyawan'));
    }

    private function karyawanLogin(): ?object
    {
        return DB::table('tbl_users as u')
            ->join('tbl_karyawan as k', 'k.id', '=', 'u.id_karyawan')
            ->where('u.id', Auth::id())
            ->select('k.*')
            ->first();
    }

    private function karyawanTidakDitemukan(): RedirectResponse
    {
        return redirect()->route('beranda')
            ->with('error', 'Akun ini belum terhubung dengan data karyawan.');
    }

    private function isSqlState45000(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? (string) $exception->getCode()) === '45000';
    }

    private function sqlMessage(QueryException $exception): string
    {
        $message = $exception->errorInfo[2] ?? 'Permintaan ditolak oleh aturan database.';

        return preg_replace('/^\d+\s+/', '', $message) ?? $message;
    }
}

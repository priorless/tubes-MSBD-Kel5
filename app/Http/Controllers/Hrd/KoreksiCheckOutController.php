<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Controller;
use App\Services\KoreksiCheckOutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Tab "Koreksi Check-out" di Absensi Karyawan: HRD memutuskan pengajuan lupa check-out dari karyawan,
 * atau langsung mengisi jam keluar absensi yang lupa check-out. Lembur dihitung trigger database.
 */
class KoreksiCheckOutController extends Controller
{
    public function index(KoreksiCheckOutService $koreksi): View
    {
        return view('hrd.absensi.koreksi-check-out', [
            'tab' => 'koreksi',
            'pengajuan' => $koreksi->daftarMenunggu(),
            'lupaCheckOut' => $koreksi->semuaLupaCheckOut(),
        ]);
    }

    public function approve(Request $request, int $koreksiCheckOut, KoreksiCheckOutService $koreksi): RedirectResponse
    {
        return $this->jalankan(fn () => $koreksi->setujui($koreksiCheckOut, (int) $request->user()->id),
            'Koreksi disetujui. Jam keluar dan lembur sudah dicatat.');
    }

    public function reject(Request $request, int $koreksiCheckOut, KoreksiCheckOutService $koreksi): RedirectResponse
    {
        $valid = $request->validate(['catatan_keputusan' => ['nullable', 'string', 'max:500']],
            ['catatan_keputusan.max' => 'Catatan maksimal 500 karakter.']);

        return $this->jalankan(fn () => $koreksi->tolak($koreksiCheckOut, (int) $request->user()->id,
            filled($valid['catatan_keputusan'] ?? null) ? trim($valid['catatan_keputusan']) : null),
            'Pengajuan koreksi check-out ditolak.');
    }

    public function store(Request $request, KoreksiCheckOutService $koreksi): RedirectResponse
    {
        $valid = $request->validate([
            'id_absensi' => ['required', 'integer', 'min:1'],
            'jam_keluar' => ['required', 'date_format:H:i'],
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'id_absensi.*' => 'Absensi yang dipilih tidak valid.',
            'jam_keluar.required' => 'Jam keluar wajib diisi.',
            'jam_keluar.date_format' => 'Format jam keluar harus JJ:MM.',
            'alasan.required' => 'Alasan wajib diisi.',
            'alasan.min' => 'Alasan minimal :min karakter.',
            'alasan.max' => 'Alasan maksimal :max karakter.',
        ]);

        return $this->jalankan(fn () => $koreksi->isiLangsung((int) $valid['id_absensi'], $valid['jam_keluar'], trim($valid['alasan']), (int) $request->user()->id),
            'Jam keluar berhasil diisi. Lembur dihitung otomatis.');
    }

    /**
     * @param  callable(): void  $aksi
     */
    private function jalankan(callable $aksi, string $pesanSukses): RedirectResponse
    {
        $kembali = redirect()->route('hrd.absensi.koreksi-check-out.index');

        try {
            $aksi();
        } catch (ValidationException $e) {
            return $kembali->withInput()->with('error', $e->validator->errors()->first());
        }

        return $kembali->with('success', $pesanSukses);
    }
}

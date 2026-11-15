<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OperasionalController extends Controller
{
    public function createPenempatan(): View
    {
        return view('operasional.penempatan.create', [
            'karyawan' => DB::table('tbl_karyawan')
                ->where('kategori', 'Lapangan')
                ->where('status', 'Tersedia')
                ->orderBy('nama')
                ->get(['id', 'nip', 'nama']),
            'klien' => DB::table('tbl_klien')
                ->where('status', 'Aktif')
                ->orderBy('nama_perusahaan')
                ->get(['id', 'kode_klien', 'nama_perusahaan']),
        ]);
    }

    public function storePenempatan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id_karyawan' => ['bail', 'required', 'integer', 'exists:tbl_karyawan,id'],
            'id_klien' => ['bail', 'required', 'integer', 'exists:tbl_klien,id'],
            'tgl_mulai' => ['bail', 'required', 'date'],
            'tgl_selesai' => ['nullable', 'date'],
            'lokasi' => ['bail', 'required', 'string', 'max:255'],
            'jam_datang' => ['bail', 'required', 'date_format:H:i'],
            'jam_pulang' => ['bail', 'required', 'date_format:H:i', 'different:jam_datang'],
        ], $this->operationValidationMessages());

        $userId = Auth::id();

        if ($userId === null) {
            return back()->withInput()->with('error', 'Silakan masuk terlebih dahulu untuk membuat penempatan.');
        }

        try {
            DB::statement('CALL sp_assign_penempatan(?, ?, ?, ?, ?, ?, ?, ?)', [
                $validated['id_karyawan'],
                $validated['id_klien'],
                $validated['tgl_mulai'],
                $validated['tgl_selesai'] ?? null,
                $validated['lokasi'],
                $validated['jam_datang'],
                $validated['jam_pulang'],
                $userId,
            ]);
        } catch (QueryException $exception) {
            $message = $this->signalMessage($exception);

            if ($message === null) {
                throw $exception;
            }

            return back()->withInput()->with('error', $message);
        }

        return redirect()->route('operasional.monitoring')->with('success', 'Penempatan karyawan berhasil dibuat.');
    }

    public function createRotasi(): View
    {
        return view('operasional.rotasi.create', [
            'penempatan' => DB::table('tbl_penempatan as p')
                ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
                ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
                ->where('p.status', 'Aktif')
                ->orderBy('k.nama')
                ->get([
                    'p.id',
                    'p.lokasi_kerja',
                    'p.jam_datang',
                    'p.jam_pulang',
                    'k.nip',
                    'k.nama as nama_karyawan',
                    'kl.nama_perusahaan',
                ]),
            'klien' => DB::table('tbl_klien')
                ->where('status', 'Aktif')
                ->orderBy('nama_perusahaan')
                ->get(['id', 'kode_klien', 'nama_perusahaan']),
        ]);
    }

    public function storeRotasi(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id_penempatan_lama' => ['bail', 'required', 'integer', 'exists:tbl_penempatan,id'],
            'id_klien_baru' => ['bail', 'required', 'integer', 'exists:tbl_klien,id'],
            'tgl_mulai_baru' => ['bail', 'required', 'date'],
            'lokasi_baru' => ['bail', 'required', 'string', 'max:255'],
            'jam_datang_baru' => ['bail', 'nullable', 'required_with:jam_pulang_baru', 'date_format:H:i'],
            'jam_pulang_baru' => ['bail', 'nullable', 'required_with:jam_datang_baru', 'date_format:H:i', 'different:jam_datang_baru'],
        ], $this->operationValidationMessages());

        $userId = Auth::id();

        if ($userId === null) {
            return back()->withInput()->with('error', 'Silakan masuk terlebih dahulu untuk memproses rotasi.');
        }

        try {
            DB::statement('CALL sp_rotasi_karyawan(?, ?, ?, ?, ?, ?, ?)', [
                $validated['id_penempatan_lama'],
                $validated['id_klien_baru'],
                $validated['tgl_mulai_baru'],
                $validated['lokasi_baru'],
                $validated['jam_datang_baru'] ?? null,
                $validated['jam_pulang_baru'] ?? null,
                $userId,
            ]);
        } catch (QueryException $exception) {
            $message = $this->signalMessage($exception);

            if ($message === null) {
                throw $exception;
            }

            return back()->withInput()->with('error', $message);
        }

        return redirect()->route('operasional.monitoring')->with('success', 'Rotasi karyawan berhasil diproses.');
    }

    public function monitoring(): View
    {
        return view('operasional.monitoring.index', [
            'penempatan' => DB::table('v_monitoring_sebaran_operasional')
                ->orderBy('sisa_hari_kontrak')
                ->paginate(15),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function operationValidationMessages(): array
    {
        return [
            'id_karyawan.required' => 'Karyawan wajib dipilih.',
            'id_karyawan.integer' => 'Pilihan karyawan tidak valid.',
            'id_karyawan.exists' => 'Karyawan yang dipilih tidak ditemukan.',
            'id_klien.required' => 'Klien wajib dipilih.',
            'id_klien.integer' => 'Pilihan klien tidak valid.',
            'id_klien.exists' => 'Klien yang dipilih tidak ditemukan.',
            'id_penempatan_lama.required' => 'Penempatan lama wajib dipilih.',
            'id_penempatan_lama.integer' => 'Pilihan penempatan tidak valid.',
            'id_penempatan_lama.exists' => 'Penempatan yang dipilih tidak ditemukan.',
            'id_klien_baru.required' => 'Klien tujuan wajib dipilih.',
            'id_klien_baru.integer' => 'Pilihan klien tujuan tidak valid.',
            'id_klien_baru.exists' => 'Klien tujuan tidak ditemukan.',
            'tgl_mulai.required' => 'Tanggal mulai wajib diisi.',
            'tgl_mulai.date' => 'Tanggal mulai tidak valid.',
            'tgl_selesai.date' => 'Tanggal selesai tidak valid.',
            'tgl_mulai_baru.required' => 'Tanggal mulai rotasi wajib diisi.',
            'tgl_mulai_baru.date' => 'Tanggal mulai rotasi tidak valid.',
            'lokasi.required' => 'Lokasi kerja wajib diisi.',
            'lokasi.max' => 'Lokasi kerja maksimal 255 karakter.',
            'lokasi_baru.required' => 'Lokasi kerja baru wajib diisi.',
            'lokasi_baru.max' => 'Lokasi kerja baru maksimal 255 karakter.',
            'jam_datang.required' => 'Jam datang wajib diisi.',
            'jam_datang.date_format' => 'Format jam datang tidak valid (JJ:MM).',
            'jam_pulang.required' => 'Jam pulang wajib diisi.',
            'jam_pulang.date_format' => 'Format jam pulang tidak valid (JJ:MM).',
            'jam_pulang.different' => 'Jam pulang tidak boleh sama dengan jam datang.',
            'jam_datang_baru.required_with' => 'Jam datang baru wajib diisi bila jam pulang baru diisi.',
            'jam_datang_baru.date_format' => 'Format jam datang baru tidak valid (JJ:MM).',
            'jam_pulang_baru.required_with' => 'Jam pulang baru wajib diisi bila jam datang baru diisi.',
            'jam_pulang_baru.date_format' => 'Format jam pulang baru tidak valid (JJ:MM).',
            'jam_pulang_baru.different' => 'Jam pulang baru tidak boleh sama dengan jam datang baru.',
        ];
    }

    private function signalMessage(QueryException $exception): ?string
    {
        $errorInfo = $exception->errorInfo ?? $exception->getPrevious()?->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? $exception->getCode());

        if ($sqlState !== '45000') {
            return null;
        }

        $databaseMessage = (string) ($errorInfo[2] ?? $exception->getPrevious()?->getMessage() ?? '');
        $databaseMessage = trim((string) preg_replace('/^\d+\s+/', '', $databaseMessage));

        return $databaseMessage === ''
            ? 'Operasi ditolak oleh aturan database. Periksa kembali status karyawan atau penempatan.'
            : 'Operasi ditolak oleh aturan database: '.$databaseMessage;
    }
}

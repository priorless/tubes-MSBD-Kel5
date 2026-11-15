<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KlienController extends Controller
{
    public function index(): View
    {
        return view('operasional.klien.index', [
            'klien' => DB::table('tbl_klien')->orderByDesc('id')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('operasional.klien.form', ['klien' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kode_klien' => ['bail', 'required', 'string', 'max:10', Rule::unique('tbl_klien', 'kode_klien')],
            'nama_perusahaan' => ['bail', 'required', 'string', 'max:150'],
            'alamat' => ['bail', 'required', 'string'],
            'pic_nama' => ['bail', 'required', 'string', 'max:100'],
            'pic_jabatan' => ['nullable', 'string', 'max:50'],
            'pic_telp' => ['bail', 'required', 'string', 'max:15'],
            'pic_email' => ['nullable', 'email', 'max:100'],
            'status' => ['required', Rule::in(['Aktif', 'Nonaktif'])],
        ], $this->validationMessages());

        try {
            DB::table('tbl_klien')->insert($validated);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23000') {
                throw $exception;
            }

            return back()->withInput()->with('error', 'Kode klien sudah digunakan. Periksa kembali data klien.');
        }

        return redirect()->route('operasional.klien.index')->with('success', 'Data klien berhasil ditambahkan.');
    }

    public function edit(int $klien): View
    {
        return view('operasional.klien.form', [
            'klien' => DB::table('tbl_klien')->where('id', $klien)->firstOrFail(),
        ]);
    }

    public function update(Request $request, int $klien): RedirectResponse
    {
        $existingClient = DB::table('tbl_klien')->where('id', $klien)->firstOrFail();

        $validated = $request->validate([
            'kode_klien' => ['bail', 'required', 'string', 'max:10', Rule::unique('tbl_klien', 'kode_klien')->ignore($existingClient->id)],
            'nama_perusahaan' => ['bail', 'required', 'string', 'max:150'],
            'alamat' => ['bail', 'required', 'string'],
            'pic_nama' => ['bail', 'required', 'string', 'max:100'],
            'pic_jabatan' => ['nullable', 'string', 'max:50'],
            'pic_telp' => ['bail', 'required', 'string', 'max:15'],
            'pic_email' => ['nullable', 'email', 'max:100'],
            'status' => ['required', Rule::in(['Aktif', 'Nonaktif'])],
        ], $this->validationMessages());

        try {
            DB::table('tbl_klien')->where('id', $existingClient->id)->update($validated);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23000') {
                throw $exception;
            }

            return back()->withInput()->with('error', 'Kode klien sudah digunakan. Periksa kembali data klien.');
        }

        return redirect()->route('operasional.klien.index')->with('success', 'Data klien berhasil diperbarui.');
    }

    public function destroy(int $klien): RedirectResponse
    {
        $existingClient = DB::table('tbl_klien')->where('id', $klien)->firstOrFail();

        try {
            DB::table('tbl_klien')->where('id', $existingClient->id)->delete();
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23000') {
                throw $exception;
            }

            return back()->with('error', 'Klien tidak dapat dihapus karena masih digunakan pada data operasional.');
        }

        return redirect()->route('operasional.klien.index')->with('success', 'Data klien berhasil dihapus.');
    }

    /**
     * @return array<string, string>
     */
    private function validationMessages(): array
    {
        return [
            'kode_klien.required' => 'Kode klien wajib diisi.',
            'kode_klien.max' => 'Kode klien maksimal 10 karakter.',
            'kode_klien.unique' => 'Kode klien sudah digunakan.',
            'nama_perusahaan.required' => 'Nama perusahaan wajib diisi.',
            'nama_perusahaan.max' => 'Nama perusahaan maksimal 150 karakter.',
            'alamat.required' => 'Alamat wajib diisi.',
            'pic_nama.required' => 'Nama PIC wajib diisi.',
            'pic_nama.max' => 'Nama PIC maksimal 100 karakter.',
            'pic_jabatan.max' => 'Jabatan PIC maksimal 50 karakter.',
            'pic_telp.required' => 'Nomor telepon PIC wajib diisi.',
            'pic_telp.max' => 'Nomor telepon PIC maksimal 15 karakter.',
            'pic_email.email' => 'Alamat email PIC tidak valid.',
            'pic_email.max' => 'Alamat email PIC maksimal 100 karakter.',
            'status.required' => 'Status klien wajib dipilih.',
            'status.in' => 'Status klien tidak valid.',
        ];
    }
}

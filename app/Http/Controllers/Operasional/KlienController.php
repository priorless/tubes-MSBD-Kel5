<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        ] + $this->aturanTitikAbsensi(), $this->validationMessages());

        $validated = $this->siapkanTitikAbsensi($validated);

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
        ] + $this->aturanTitikAbsensi(), $this->validationMessages());

        $validated = $this->siapkanTitikAbsensi($validated);

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
     * Titik lokasi kerja klien untuk geofencing absensi (diisi opsional).
     *
     * @return array<string, list<string>>
     */
    private function aturanTitikAbsensi(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'radius_meter' => ['nullable', 'integer', 'between:25,5000'],
        ];
    }

    /**
     * Radius kosong memakai bawaan 200 m. Kolom titik dilewati bila migrasi geofence belum dijalankan.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function siapkanTitikAbsensi(array $validated): array
    {
        if (! Schema::hasColumn('tbl_klien', 'latitude')) {
            return Arr::except($validated, ['latitude', 'longitude', 'radius_meter']);
        }

        $validated['radius_meter'] = $validated['radius_meter'] ?? 200;

        return $validated;
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
            'latitude.between' => 'Latitude harus antara -90 dan 90.',
            'latitude.required_with' => 'Latitude wajib diisi bersama longitude.',
            'longitude.between' => 'Longitude harus antara -180 dan 180.',
            'longitude.required_with' => 'Longitude wajib diisi bersama latitude.',
            'radius_meter.between' => 'Radius absensi harus antara 25 dan 5000 meter.',
        ];
    }
}

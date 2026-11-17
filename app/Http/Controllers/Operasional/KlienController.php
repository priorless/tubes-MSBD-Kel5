<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KlienController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['Aktif', 'Nonaktif'])],
        ], [
            'q.max' => 'Kata pencarian maksimal 100 karakter.',
            'status.in' => 'Filter status tidak valid.',
        ]);
        $kataKunci = trim((string) ($validated['q'] ?? ''));
        $status = $validated['status'] ?? null;

        $klien = DB::table('tbl_klien')
            ->when($kataKunci !== '', fn ($query) => $query->where(fn ($cari) => $cari
                ->where('nama_perusahaan', 'like', '%'.$kataKunci.'%')
                ->orWhere('kode_klien', 'like', '%'.$kataKunci.'%')))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderBy('nama_perusahaan')
            ->paginate(15)
            ->withQueryString();
        $layananPerKlien = $this->layananAktifPerKlien($klien->pluck('id')->all());

        return view('operasional.klien.index', [
            'klien' => $klien,
            'kataKunci' => $kataKunci,
            'status' => $status,
            'jumlahPerStatus' => DB::table('tbl_klien')->groupBy('status')->selectRaw('status, COUNT(*) as jumlah')->pluck('jumlah', 'status'),
            'layananPerKlien' => $layananPerKlien,
        ]);
    }

    /**
     * Detail klien: informasi, kontak, jenis layanan, karyawan yang sedang ditempatkan, dan riwayat kerja sama.
     */
    public function show(int $klien): View
    {
        $dataKlien = DB::table('tbl_klien')->where('id', $klien)->firstOrFail();
        $penempatan = DB::table('tbl_penempatan as p')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
            ->where('p.id_klien', $klien)
            ->orderByDesc('p.tgl_mulai')
            ->get(['p.id', 'p.id_karyawan', 'p.lokasi_kerja', 'p.tgl_mulai', 'p.tgl_selesai', 'p.status', 'k.nip', 'k.nama', 'jl.nama_layanan']);
        $penempatanAktif = $penempatan->where('status', 'Aktif')->sortBy('nama')->values();

        return view('operasional.klien.show', [
            'klien' => $dataKlien,
            'penempatanAktif' => $penempatanAktif,
            'riwayatPenempatan' => $penempatan->where('status', '!=', 'Aktif')->values(),
            'layanan' => $this->layananAktifPerKlien([$klien])->get($klien, collect()),
            'kerjaSamaSejak' => $penempatan->min('tgl_mulai'),
        ]);
    }

    /**
     * Mengubah status kerja sama tanpa menghapus data; penempatan yang ada tidak ikut berubah.
     */
    public function updateStatus(Request $request, int $klien): RedirectResponse
    {
        $dataKlien = DB::table('tbl_klien')->where('id', $klien)->firstOrFail();
        $validated = $request->validate([
            'status' => ['required', Rule::in(['Aktif', 'Nonaktif'])],
        ], [
            'status.required' => 'Status klien wajib dipilih.',
            'status.in' => 'Status klien tidak valid.',
        ]);

        DB::table('tbl_klien')->where('id', $dataKlien->id)->update(['status' => $validated['status']]);

        return back()->with('success', $validated['status'] === 'Aktif'
            ? "Kerja sama dengan {$dataKlien->nama_perusahaan} diaktifkan kembali."
            : "Kerja sama dengan {$dataKlien->nama_perusahaan} dinonaktifkan. Data dan riwayatnya tetap tersimpan.");
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
            $idKlien = (int) DB::table('tbl_klien')->insertGetId($validated);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23000') {
                throw $exception;
            }

            return back()->withInput()->with('error', 'Kode klien sudah digunakan. Periksa kembali data klien.');
        }

        return redirect()->route('operasional.klien.show', $idKlien)
            ->with('success', 'Klien berhasil ditambahkan. Langkah berikutnya: buat penempatan karyawan untuk klien ini.');
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

        return redirect()->route('operasional.klien.show', $existingClient->id)->with('success', 'Data klien berhasil diperbarui.');
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
     * Jenis layanan karyawan yang sedang ditempatkan (penempatan Aktif) per klien, beserta jumlah karyawannya.
     * Jenis layanan berasal dari data HRD (tbl_karyawan.id_jenis_layanan).
     *
     * @param  list<int>  $idKlien
     * @return Collection<int, Collection<int, object>>
     */
    private function layananAktifPerKlien(array $idKlien): Collection
    {
        if ($idKlien === []) {
            return collect();
        }

        return DB::table('tbl_penempatan as p')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
            ->whereIn('p.id_klien', $idKlien)
            ->where('p.status', 'Aktif')
            ->groupBy('p.id_klien', 'jl.nama_layanan')
            ->orderBy('jl.nama_layanan')
            ->selectRaw('p.id_klien, jl.nama_layanan, COUNT(DISTINCT p.id_karyawan) as jumlah')
            ->get()
            ->groupBy(static fn (object $baris): int => (int) $baris->id_klien);
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

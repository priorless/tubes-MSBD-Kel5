<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'ruteKlien' => $this->ruteKlien(),
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
            'managementFeeTersedia' => $this->managementFeeTersedia(),
            'bpjsTersedia' => $this->bpjsTersedia(),
            'ruteKlien' => $this->ruteKlien(),
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
        return view('operasional.klien.form', [
            'klien' => null,
            'managementFeeTersedia' => $this->managementFeeTersedia(),
            'bpjsTersedia' => $this->bpjsTersedia(),
            'ruteKlien' => $this->ruteKlien(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->validationRules($request), $this->validationMessages($request));

        try {
            $idKlien = (int) DB::table('tbl_klien')->insertGetId($validated);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23000') {
                throw $exception;
            }

            return back()->withInput()->with('error', 'Kode klien sudah digunakan. Periksa kembali data klien.');
        }

        return redirect()->route($this->ruteKlien().'.show', $idKlien)
            ->with('success', 'Klien berhasil ditambahkan. Langkah berikutnya: buat penempatan karyawan untuk klien ini.');
    }

    public function edit(int $klien): View
    {
        return view('operasional.klien.form', [
            'klien' => DB::table('tbl_klien')->where('id', $klien)->firstOrFail(),
            'managementFeeTersedia' => $this->managementFeeTersedia(),
            'bpjsTersedia' => $this->bpjsTersedia(),
            'ruteKlien' => $this->ruteKlien(),
        ]);
    }

    public function update(Request $request, int $klien): RedirectResponse
    {
        $existingClient = DB::table('tbl_klien')->where('id', $klien)->firstOrFail();

        $validated = $request->validate($this->validationRules($request, $existingClient->id), $this->validationMessages($request));

        try {
            DB::table('tbl_klien')->where('id', $existingClient->id)->update($validated);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23000') {
                throw $exception;
            }

            return back()->withInput()->with('error', 'Kode klien sudah digunakan. Periksa kembali data klien.');
        }

        return redirect()->route($this->ruteKlien().'.show', $existingClient->id)->with('success', 'Data klien berhasil diperbarui.');
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

        return redirect()->route($this->ruteKlien().'.index')->with('success', 'Data klien berhasil dihapus.');
    }

    /**
     * Prefix nama route Data Klien sesuai modul yang membuka halaman. Controller ini dipakai bersama oleh
     * Operasional (operasional.klien.*) dan HRD (hrd.klien.*) agar data dan aturannya tetap satu sumber.
     */
    private function ruteKlien(): string
    {
        return request()->routeIs('hrd.*') ? 'hrd.klien' : 'operasional.klien';
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
     * Kolom tbl_klien.management_fee dibuat migrasi 2026_11_17_065725. Selama belum dimigrasi, form dan detail
     * klien tetap berjalan tanpa Management Fee dan menampilkan peringatan (kegagalan migrasi tidak disembunyikan).
     */
    private function managementFeeTersedia(): bool
    {
        return Schema::hasColumn('tbl_klien', 'management_fee');
    }

    /**
     * Kolom tbl_klien.bpjs_metode/bpjs_nilai dibuat migrasi 2026_11_18_041642. Pola sama dengan Management Fee.
     */
    private function bpjsTersedia(): bool
    {
        return Schema::hasColumn('tbl_klien', 'bpjs_metode');
    }

    /**
     * Aturan validasi tambah/ubah klien. Management Fee: kosong = belum ditentukan (NULL), 0 = tanpa fee,
     * maksimal dua angka desimal; koma desimal ("7,5") diterima dan diubah menjadi titik.
     *
     * @return array<string, list<mixed>>
     */
    private function validationRules(Request $request, ?int $abaikanIdKlien = null): array
    {
        $aturan = [
            'kode_klien' => ['bail', 'required', 'string', 'max:10', Rule::unique('tbl_klien', 'kode_klien')->ignore($abaikanIdKlien)],
            'nama_perusahaan' => ['bail', 'required', 'string', 'max:150'],
            'alamat' => ['bail', 'required', 'string'],
            'pic_nama' => ['bail', 'required', 'string', 'max:100'],
            'pic_jabatan' => ['nullable', 'string', 'max:50'],
            'pic_telp' => ['bail', 'required', 'string', 'max:15'],
            'pic_email' => ['nullable', 'email', 'max:100'],
            'status' => ['required', Rule::in(['Aktif', 'Nonaktif'])],
        ];

        if ($this->managementFeeTersedia()) {
            if (is_string($request->input('management_fee'))) {
                $request->merge(['management_fee' => str_replace(',', '.', trim($request->input('management_fee')))]);
            }

            $aturan['management_fee'] = ['bail', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'];
        }

        if ($this->bpjsTersedia()) {
            $aturan = [...$aturan, ...$this->aturanBpjs($request)];
        }

        return $aturan;
    }

    /**
     * BPJS default klien (satu BPJS gabungan): metode kosong = belum ditentukan/tanpa BPJS, sehingga nilainya
     * ikut dikosongkan. Persentase 0 < x <= 100; nominal rupiah per tenaga > 0. Koma desimal diterima.
     *
     * @return array<string, list<mixed>>
     */
    private function aturanBpjs(Request $request): array
    {
        $metode = $request->input('bpjs_metode');

        if (! is_string($metode) || trim($metode) === '') {
            $request->merge(['bpjs_metode' => null, 'bpjs_nilai' => null]);
        } elseif (is_string($request->input('bpjs_nilai'))) {
            $request->merge(['bpjs_nilai' => str_replace(',', '.', trim($request->input('bpjs_nilai')))]);
        }

        $batasAtas = $request->input('bpjs_metode') === 'Persentase' ? 'max:100' : 'max:9999999999.99';

        return [
            'bpjs_metode' => ['nullable', Rule::in(['Persentase', 'Nominal'])],
            'bpjs_nilai' => ['bail', 'nullable', 'required_with:bpjs_metode', 'numeric', 'decimal:0,2', 'gt:0', $batasAtas],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validationMessages(Request $request): array
    {
        return [
            'management_fee.numeric' => 'Management Fee harus berupa angka.',
            'management_fee.decimal' => 'Management Fee maksimal dua angka di belakang koma.',
            'management_fee.min' => 'Management Fee tidak boleh kurang dari 0%.',
            'management_fee.max' => 'Management Fee tidak boleh lebih dari 100%.',
            'bpjs_metode.in' => 'Metode BPJS tidak valid.',
            'bpjs_nilai.required_with' => 'Nilai BPJS wajib diisi untuk metode yang dipilih.',
            'bpjs_nilai.numeric' => 'Nilai BPJS harus berupa angka.',
            'bpjs_nilai.decimal' => 'Nilai BPJS maksimal dua angka di belakang koma.',
            'bpjs_nilai.gt' => 'Nilai BPJS harus lebih dari 0. Pilih "Tanpa BPJS" bila klien tidak memakai BPJS.',
            'bpjs_nilai.max' => $request->input('bpjs_metode') === 'Persentase'
                ? 'Persentase BPJS tidak boleh lebih dari 100%.'
                : 'Nominal BPJS terlalu besar.',
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

<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrd\StoreKaryawanRequest;
use App\Http\Requests\Hrd\UpdateKaryawanRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class KaryawanController extends Controller
{
    use MenanganiSignalDatabase;

    private const FIELD_AKUN = ['buat_akun', 'username', 'password', 'id_role'];

    public function index(Request $request): View
    {
        $karyawan = DB::table('tbl_karyawan as k')
            ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
            ->leftJoin('tbl_users as u', 'u.id_karyawan', '=', 'k.id')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $kataKunci = '%'.$request->string('q').'%';
                $query->where(fn ($sub) => $sub->where('k.nama', 'like', $kataKunci)->orWhere('k.nip', 'like', $kataKunci));
            })
            ->when($request->filled('kategori'), fn ($query) => $query->where('k.kategori', $request->string('kategori')))
            ->when($request->filled('status'), fn ($query) => $query->where('k.status', $request->string('status')))
            ->orderByDesc('k.id')
            ->select('k.*', 'jl.nama_layanan', 'u.username')
            ->paginate(15)
            ->withQueryString();

        return view('hrd.karyawan.index', ['karyawan' => $karyawan]);
    }

    public function create(): View
    {
        return view('hrd.karyawan.form', [
            'karyawan' => null,
            'jenisLayanan' => $this->daftarJenisLayanan(),
            'roles' => DB::table('tbl_roles')
                ->where('nama_role', '<>', 'Karyawan Lapangan')
                ->orderBy('nama_role')
                ->get(['id', 'nama_role']),
        ]);
    }

    public function store(StoreKaryawanRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $dataKaryawan = Arr::except($validated, [...self::FIELD_AKUN, 'foto']);
        $pathFoto = $request->file('foto')?->store('foto-karyawan', 'public');
        $buatAkunInternal = $validated['kategori'] === 'Internal' && (bool) ($validated['buat_akun'] ?? false);

        try {
            DB::transaction(function () use ($dataKaryawan, $pathFoto, $buatAkunInternal, $validated): void {
                // Status awal & akun login Lapangan diurus trigger; PHP hanya menyimpan data.
                $idKaryawan = DB::table('tbl_karyawan')->insertGetId([...$dataKaryawan, 'foto' => $pathFoto]);

                if ($buatAkunInternal) {
                    DB::table('tbl_users')->insert([
                        'id_karyawan' => $idKaryawan,
                        'username' => $validated['username'],
                        'password' => Hash::make($validated['password']),
                        'id_role' => $validated['id_role'],
                    ]);
                }
            });
        } catch (QueryException $exception) {
            $this->hapusBerkas($pathFoto);

            return $this->kembaliDenganError($exception);
        }

        $pesan = $validated['kategori'] === 'Lapangan'
            ? 'Karyawan Lapangan berhasil ditambahkan. Akun login dibuat otomatis oleh sistem (username = NIP).'
            : 'Karyawan Internal berhasil ditambahkan.'.($buatAkunInternal ? ' Akun login juga sudah dibuat.' : '');

        return redirect()->route('hrd.karyawan.index')->with('success', $pesan);
    }

    public function edit(int $karyawan): View
    {
        $data = DB::table('tbl_karyawan')->where('id', $karyawan)->firstOrFail();

        return view('hrd.karyawan.form', [
            'karyawan' => $data,
            'jenisLayanan' => $this->daftarJenisLayanan(),
            'roles' => collect(),
            'penempatanAktif' => UpdateKaryawanRequest::punyaPenempatanAktif($karyawan),
            'pilihanStatus' => UpdateKaryawanRequest::pilihanStatus($data->kategori, $data->status),
        ]);
    }

    public function update(UpdateKaryawanRequest $request, int $karyawan): RedirectResponse
    {
        $dataLama = DB::table('tbl_karyawan')->where('id', $karyawan)->firstOrFail();
        $dataBaru = Arr::except($request->validated(), ['foto']);
        $pathFotoBaru = $request->file('foto')?->store('foto-karyawan', 'public');

        if ($pathFotoBaru !== null) {
            $dataBaru['foto'] = $pathFotoBaru;
        }

        try {
            DB::table('tbl_karyawan')->where('id', $karyawan)->update($dataBaru);
        } catch (QueryException $exception) {
            $this->hapusBerkas($pathFotoBaru);

            return $this->kembaliDenganError($exception);
        }

        if ($pathFotoBaru !== null) {
            $this->hapusBerkas($dataLama->foto);
        }

        return redirect()->route('hrd.karyawan.index')->with('success', 'Data karyawan berhasil diperbarui.');
    }

    public function destroy(int $karyawan): RedirectResponse
    {
        $data = DB::table('tbl_karyawan')->where('id', $karyawan)->firstOrFail();

        try {
            DB::transaction(function () use ($karyawan): void {
                DB::table('tbl_users')->where('id_karyawan', $karyawan)->delete();
                DB::table('tbl_karyawan')->where('id', $karyawan)->delete();
            });
        } catch (QueryException $exception) {
            if ($this->masihDirujuk($exception)) {
                return back()->with('error', "Karyawan {$data->nama} tidak dapat dihapus karena sudah memiliki riwayat (sertifikat, penempatan, absensi, atau payroll).");
            }

            return $this->kembaliDenganError($exception);
        }

        $this->hapusBerkas($data->foto);

        return redirect()->route('hrd.karyawan.index')->with('success', "Karyawan {$data->nama} berhasil dihapus.");
    }

    private function daftarJenisLayanan(): Collection
    {
        return DB::table('tbl_jenis_layanan')->orderBy('nama_layanan')->get(['id', 'nama_layanan']);
    }

    private function kembaliDenganError(QueryException $exception): RedirectResponse
    {
        $pesan = $this->pesanSignalDatabase($exception);

        if ($pesan === null && $this->dataDuplikat($exception)) {
            $pesan = 'Data bentrok dengan data yang sudah ada (NIP, nomor KTP, email, atau username sudah dipakai).';
        }

        if ($pesan === null) {
            throw $exception;
        }

        return back()->withInput()->with('error', $pesan);
    }

    private function hapusBerkas(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }
}

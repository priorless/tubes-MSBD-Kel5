<?php

namespace App\Http\Controllers\Hrd;

use App\Http\Controllers\Concerns\MenanganiSignalDatabase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrd\StoreKaryawanRequest;
use App\Http\Requests\Hrd\UpdateKaryawanRequest;
use App\Services\GajiPokokLapanganService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class KaryawanController extends Controller
{
    use MenanganiSignalDatabase;

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

    public function create(GajiPokokLapanganService $gajiPokok): View
    {
        return view('hrd.karyawan.form', [
            'karyawan' => null,
            'jenisLayanan' => $this->daftarJenisLayanan(),
            'gajiPokok' => ['tersedia' => $gajiPokok->tersedia(), 'nilai' => null, 'keterangan' => null],
        ]);
    }

    /**
     * Karyawan baru selalu kategori Lapangan (lihat StoreKaryawanRequest). Status awal dan akun login
     * (username & password awal = NIP) dibuat trigger database. Gaji pokok (opsional) disimpan ke Komponen
     * Gaji mulai bulan tanggal masuk, dalam transaksi yang sama dengan data karyawan.
     */
    public function store(StoreKaryawanRequest $request, GajiPokokLapanganService $gajiPokok): RedirectResponse
    {
        $validated = $request->validated();
        $dataKaryawan = Arr::except($validated, ['foto', 'gaji_pokok']);
        $pathFoto = $request->file('foto')?->store('foto-karyawan', 'public');

        try {
            DB::transaction(function () use ($dataKaryawan, $pathFoto, $validated, $gajiPokok): void {
                $idKaryawan = (int) DB::table('tbl_karyawan')->insertGetId([...$dataKaryawan, 'foto' => $pathFoto]);

                if (filled($validated['gaji_pokok'] ?? null)) {
                    $mulai = Carbon::parse($validated['tgl_masuk']);
                    $gajiPokok->simpan($idKaryawan, $mulai->month, $mulai->year, (string) $validated['gaji_pokok']);
                }
            });
        } catch (QueryException $exception) {
            $this->hapusBerkas($pathFoto);

            return $this->kembaliDenganError($exception);
        }

        return redirect()->route('hrd.karyawan.index')
            ->with('success', 'Karyawan Lapangan berhasil ditambahkan. Akun login dibuat otomatis oleh sistem (username = NIP).');
    }

    public function edit(int $karyawan, GajiPokokLapanganService $gajiPokok): View
    {
        $data = DB::table('tbl_karyawan')->where('id', $karyawan)->firstOrFail();

        return view('hrd.karyawan.form', [
            'karyawan' => $data,
            'jenisLayanan' => $this->daftarJenisLayanan(),
            'penempatanAktif' => UpdateKaryawanRequest::punyaPenempatanAktif($karyawan),
            'pilihanStatus' => UpdateKaryawanRequest::pilihanStatus($data->kategori, $data->status),
            'gajiPokok' => $this->infoGajiPokok($data, $gajiPokok),
        ]);
    }

    /**
     * Internal: gaji pokok di tbl_karyawan (seperti sebelumnya). Lapangan: gaji pokok baru berlaku mulai
     * periode bulan ini di Komponen Gaji; kosong = tidak diubah. Semua perubahan dalam satu transaksi.
     */
    public function update(UpdateKaryawanRequest $request, int $karyawan, GajiPokokLapanganService $gajiPokok): RedirectResponse
    {
        $dataLama = DB::table('tbl_karyawan')->where('id', $karyawan)->firstOrFail();
        $validated = $request->validated();
        $gajiLapangan = $dataLama->kategori === 'Lapangan' ? ($validated['gaji_pokok'] ?? null) : null;
        $dataBaru = Arr::except($validated, $dataLama->kategori === 'Lapangan' ? ['foto', 'gaji_pokok'] : ['foto']);
        $pathFotoBaru = $request->file('foto')?->store('foto-karyawan', 'public');

        if ($pathFotoBaru !== null) {
            $dataBaru['foto'] = $pathFotoBaru;
        }

        try {
            DB::transaction(function () use ($karyawan, $dataBaru, $gajiLapangan, $gajiPokok): void {
                DB::table('tbl_karyawan')->where('id', $karyawan)->update($dataBaru);

                if (filled($gajiLapangan)) {
                    $gajiPokok->simpan($karyawan, now()->month, now()->year, (string) $gajiLapangan);
                }
            });
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

    /**
     * Nilai & keterangan kolom Gaji Pokok pada form edit.
     *
     * @return array{tersedia: bool, nilai: float|string|null, keterangan: string|null}
     */
    private function infoGajiPokok(object $karyawan, GajiPokokLapanganService $gajiPokok): array
    {
        if ($karyawan->kategori === 'Internal') {
            return ['tersedia' => true, 'nilai' => $karyawan->gaji_pokok, 'keterangan' => null];
        }

        if (! $gajiPokok->tersedia()) {
            return ['tersedia' => false, 'nilai' => null, 'keterangan' => null];
        }

        $berlaku = $gajiPokok->berlaku((int) $karyawan->id, now()->month, now()->year);

        return [
            'tersedia' => true,
            'nilai' => $berlaku['nominal'] ?? null,
            'keterangan' => $berlaku === null
                ? 'Belum diinput. Selama kosong, payroll memakai gaji pada kontrak penempatan.'
                : 'Berlaku sejak '.$berlaku['label_periode'].'.',
        ];
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

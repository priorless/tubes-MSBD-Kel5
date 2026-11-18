<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KeuanganKomponenGajiController extends Controller
{
    private const array DAFTAR_BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function index(Request $request): View
    {
        $klien = DB::table('tbl_klien')->orderBy('nama_perusahaan')->get(['id', 'nama_perusahaan']);
        $jenisPekerjaan = $this->daftarJenisPekerjaan();
        $pilihanPenempatan = ['eksternal', ...$klien->map(fn (object $item): string => 'klien:'.$item->id)->all()];
        $validated = $request->validate([
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'tahun' => ['nullable', 'integer', 'between:2000,2100'],
            'penempatan' => ['nullable', 'string', Rule::in($pilihanPenempatan)],
            'jenis_pekerjaan' => ['nullable', 'integer', Rule::in($jenisPekerjaan->pluck('id')->all())],
        ], [
            'bulan.between' => 'Bulan harus antara 1 sampai 12.',
            'tahun.between' => 'Tahun harus antara 2000 sampai 2100.',
            'penempatan.in' => 'Penempatan tidak valid.',
        ]);

        $bulan = (int) ($validated['bulan'] ?? now()->month);
        $tahun = (int) ($validated['tahun'] ?? now()->year);
        $penempatan = $validated['penempatan'] ?? 'eksternal';
        $idJenisPekerjaan = isset($validated['jenis_pekerjaan']) ? (int) $validated['jenis_pekerjaan'] : null;
        $karyawan = $this->karyawanPeriode($bulan, $tahun, $penempatan, $idJenisPekerjaan);
        $manual = DB::table('tbl_komponen_gaji')
            ->where('periode_bulan', $bulan)
            ->where('periode_tahun', $tahun)
            ->whereIn('id_karyawan', $karyawan->pluck('id')->all())
            ->get()
            ->keyBy('id_karyawan');

        $baris = $karyawan->map(function (object $item) use ($bulan, $tahun, $manual): object {
            $otomatis = $this->komponenOtomatis($item, $bulan, $tahun);
            $tersimpan = $manual->get($item->id);

            return (object) [...(array) $item, ...$otomatis,
                'tunjangan' => (float) ($tersimpan->tunjangan ?? 0),
                'potongan_bpjs' => (float) ($tersimpan->potongan_bpjs ?? 0),
                'potongan_pinjaman' => (float) ($tersimpan->potongan_pinjaman ?? 0),
            ];
        });

        return view('keuangan.komponen-gaji.index', [
            'karyawan' => $baris,
            'klien' => $klien,
            'jenisPekerjaan' => $jenisPekerjaan,
            'bulan' => $bulan,
            'tahun' => $tahun,
            'penempatan' => $penempatan,
            'idJenisPekerjaan' => $idJenisPekerjaan,
            'daftarBulan' => self::DAFTAR_BULAN,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $klien = ['eksternal', ...DB::table('tbl_klien')->pluck('id')->map(fn ($id): string => 'klien:'.$id)->all()];
        $jenisPekerjaan = $this->daftarJenisPekerjaan();
        $validated = $request->validate([
            'bulan' => ['required', 'integer', 'between:1,12'],
            'tahun' => ['required', 'integer', 'between:2000,2100'],
            'penempatan' => ['required', 'string', Rule::in($klien)],
            'jenis_pekerjaan' => ['nullable', 'integer', Rule::in($jenisPekerjaan->pluck('id')->all())],
            'komponen' => ['required', 'array', 'min:1'],
            'komponen.*.tunjangan' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'komponen.*.potongan_bpjs' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'komponen.*.potongan_pinjaman' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
        ], [
            'komponen.required' => 'Komponen gaji tidak ditemukan untuk disimpan.',
            'komponen.*.*.min' => 'Nominal tidak boleh negatif.',
            'komponen.*.*.numeric' => 'Nominal harus berupa angka yang valid.',
        ]);

        $idJenisPekerjaan = isset($validated['jenis_pekerjaan']) ? (int) $validated['jenis_pekerjaan'] : null;
        $karyawanIds = $this->karyawanPeriode((int) $validated['bulan'], (int) $validated['tahun'], $validated['penempatan'], $idJenisPekerjaan)
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $idsDikirim = array_map('strval', array_keys($validated['komponen']));
        abort_if(array_diff($idsDikirim, $karyawanIds) !== [], 422, 'Daftar karyawan tidak sesuai dengan filter periode dan penempatan.');

        $sekarang = now();
        $baris = [];
        foreach ($validated['komponen'] as $idKaryawan => $nilai) {
            $baris[] = [
                'id_karyawan' => (int) $idKaryawan,
                'periode_bulan' => (int) $validated['bulan'],
                'periode_tahun' => (int) $validated['tahun'],
                'tunjangan' => $nilai['tunjangan'],
                'potongan_bpjs' => $nilai['potongan_bpjs'],
                'potongan_pinjaman' => $nilai['potongan_pinjaman'],
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ];
        }

        DB::transaction(fn () => DB::table('tbl_komponen_gaji')->upsert(
            $baris,
            ['id_karyawan', 'periode_bulan', 'periode_tahun'],
            ['tunjangan', 'potongan_bpjs', 'potongan_pinjaman', 'updated_at'],
        ));

        return redirect()->route('keuangan.komponen-gaji.index', [
            'bulan' => $validated['bulan'], 'tahun' => $validated['tahun'], 'penempatan' => $validated['penempatan'],
            'jenis_pekerjaan' => $validated['jenis_pekerjaan'] ?? null,
        ])->with('success', 'Komponen gaji berhasil disimpan. Payroll tidak dibuat atau diubah.');
    }

    private function karyawanPeriode(int $bulan, int $tahun, string $penempatan, ?int $idJenisPekerjaan = null)
    {
        $awal = Carbon::create($tahun, $bulan, 1)->toDateString();
        $akhir = Carbon::create($tahun, $bulan, 1)->endOfMonth()->toDateString();
        $idKlien = str_starts_with($penempatan, 'klien:') ? (int) substr($penempatan, 6) : null;

        return DB::table('tbl_karyawan as k')
            ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
            ->where('k.kategori', 'Lapangan')
            ->whereNotNull('k.id_jenis_layanan')
            ->whereExists(function ($penugasan) use ($idKlien, $awal, $akhir): void {
                $penugasan->selectRaw('1')->from('tbl_penempatan as p')
                    ->whereColumn('p.id_karyawan', 'k.id')
                    ->when($idKlien !== null, fn ($query) => $query->where('p.id_klien', $idKlien))
                    ->where('p.tgl_mulai', '<=', $akhir)
                    ->where(fn ($rentang) => $rentang->whereNull('p.tgl_selesai')->orWhere('p.tgl_selesai', '>=', $awal));
            })
            ->when($idJenisPekerjaan !== null, fn ($query) => $query->where('k.id_jenis_layanan', $idJenisPekerjaan))
            ->orderBy('k.nama')->orderBy('k.id')
            ->get(['k.id', 'k.nip', 'k.nama', 'k.kategori', 'k.status', 'k.gaji_pokok', 'jl.nama_layanan']);
    }

    private function daftarJenisPekerjaan()
    {
        $urutan = ['Satpam', 'Cleaning Service', 'Driver'];

        return DB::table('tbl_jenis_layanan')->whereIn('nama_layanan', $urutan)->get(['id', 'nama_layanan'])
            ->sortBy(fn (object $item): int => array_search($item->nama_layanan, $urutan, true))
            ->values();
    }

    /** Angka otomatis mengikuti rumus yang dipakai sp_generate_payroll_bulanan. */
    private function komponenOtomatis(object $karyawan, int $bulan, int $tahun): array
    {
        $awal = Carbon::create($tahun, $bulan, 1)->toDateString();
        $akhir = Carbon::create($tahun, $bulan, 1)->endOfMonth()->toDateString();
        $hariBulan = Carbon::create($tahun, $bulan, 1)->daysInMonth;
        $gaji = 0.0;
        $potongan = 0.0;
        $hariWajib = null;
        $hariAbsen = null;
        $gajiTersedia = false;

        if ($karyawan->kategori === 'Internal') {
            $gaji = (float) ($karyawan->gaji_pokok ?? 0);
            $gajiTersedia = $karyawan->gaji_pokok !== null;
            if ($karyawan->gaji_pokok !== null) {
                $hariWajib = 25;
                $hariAbsen = DB::table('tbl_absensi')->where('id_karyawan', $karyawan->id)
                    ->whereBetween('tgl', [$awal, $akhir])->whereIn('status_kehadiran', ['Izin', 'Alpha'])->count();
                $potongan = (float) DB::selectOne('SELECT fn_hitung_potongan_absen(?, 25, ?) AS nominal', [$gaji, $hariAbsen])->nominal;
            }
        } else {
            $kontrak = DB::table('tbl_kontrak as c')->join('tbl_penempatan as p', 'p.id', '=', 'c.id_penempatan')
                ->where('p.id_karyawan', $karyawan->id)->where('p.tgl_mulai', '<=', $akhir)
                ->where(fn ($query) => $query->whereNull('p.tgl_selesai')->orWhere('p.tgl_selesai', '>=', $awal))
                ->orderByDesc('p.tgl_mulai')->orderByDesc('p.id')->orderByDesc('c.id')
                ->first(['c.gaji_pokok', 'c.hari_kerja_per_bulan']);

            if ($kontrak !== null && (int) $kontrak->hari_kerja_per_bulan > 0) {
                $gajiTersedia = true;
                $hariPenempatan = (int) DB::selectOne('SELECT fn_hari_penempatan(?, NULL, ?, ?) AS jumlah', [$karyawan->id, $awal, $akhir])->jumlah;
                $hariWajib = (int) round((int) $kontrak->hari_kerja_per_bulan * $hariPenempatan / $hariBulan);
                $hariMasuk = DB::table('tbl_absensi')->where('id_karyawan', $karyawan->id)
                    ->whereBetween('tgl', [$awal, $akhir])->whereIn('status_kehadiran', ['Hadir', 'Terlambat', 'Sakit'])->count();
                $hariAbsen = max(0, $hariWajib - $hariMasuk);
                $tarif = (float) $kontrak->gaji_pokok / (int) $kontrak->hari_kerja_per_bulan;
                $gaji = round($tarif * $hariWajib, 2);
                $potongan = round($tarif * $hariAbsen, 2);
            }
        }

        $jamLembur = (int) DB::table('tbl_absensi')->where('id_karyawan', $karyawan->id)
            ->whereBetween('tgl', [$awal, $akhir])->where('status_lembur', 'Disetujui')->sum('jam_lembur');
        $lembur = (float) DB::selectOne('SELECT fn_hitung_lembur(?) AS nominal', [$jamLembur])->nominal;

        return [
            'gaji_pokok' => $gaji,
            'upah_lembur' => $lembur,
            'potongan_absen' => $potongan,
            'hari_wajib' => $hariWajib,
            'hari_absen' => $hariAbsen,
            'status_gaji' => $gajiTersedia ? null : 'Sumber gaji belum tersedia',
            'upah_backup' => 0.0,
        ];
    }
}

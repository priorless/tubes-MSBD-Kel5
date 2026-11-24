<?php

namespace App\Http\Controllers;

use App\Support\LabelBpjs;
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

    /** Pembagi upah per jam lembur: gaji pokok sebulan / 173. */
    private const int PEMBAGI_UPAH_PER_JAM = 173;

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
            'q' => ['nullable', 'string', 'max:100'],
        ], [
            'bulan.between' => 'Bulan harus antara 1 sampai 12.',
            'tahun.between' => 'Tahun harus antara 2000 sampai 2100.',
            'penempatan.in' => 'Penempatan tidak valid.',
        ]);

        $bulan = (int) ($validated['bulan'] ?? now()->month);
        $tahun = (int) ($validated['tahun'] ?? now()->year);
        $penempatan = $validated['penempatan'] ?? 'eksternal';
        $idJenisPekerjaan = isset($validated['jenis_pekerjaan']) ? (int) $validated['jenis_pekerjaan'] : null;
        $cari = trim((string) ($validated['q'] ?? ''));
        $karyawan = $this->karyawanPeriode($bulan, $tahun, $penempatan, $idJenisPekerjaan);
        $jumlahSebelumCari = $karyawan->count();

        // Pencarian NIP / nama karyawan (tanpa membedakan huruf besar-kecil) di antara karyawan periode & filter terpilih.
        if ($cari !== '') {
            $karyawan = $karyawan->filter(fn (object $item): bool => str_contains(mb_strtolower((string) $item->nip), mb_strtolower($cari))
                || str_contains(mb_strtolower((string) $item->nama), mb_strtolower($cari)))->values();
        }

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
            'cari' => $cari,
            'jumlahSebelumCari' => $jumlahSebelumCari,
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

        // Hanya Tunjangan & Potongan Pinjaman yang diambil dari request (field lain di request diabaikan oleh validate()).
        // Komponen otomatis (gaji pokok, lembur, back-up, potongan absensi, BPJS) tidak disimpan di sini:
        // Generate Payroll menghitungnya sendiri dari data sumber di database.
        $sekarang = now();
        $baris = [];
        foreach ($validated['komponen'] as $idKaryawan => $nilai) {
            $baris[] = [
                'id_karyawan' => (int) $idKaryawan,
                'periode_bulan' => (int) $validated['bulan'],
                'periode_tahun' => (int) $validated['tahun'],
                'tunjangan' => $nilai['tunjangan'],
                'potongan_pinjaman' => $nilai['potongan_pinjaman'],
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ];
        }

        DB::transaction(fn () => DB::table('tbl_komponen_gaji')->upsert(
            $baris,
            ['id_karyawan', 'periode_bulan', 'periode_tahun'],
            ['tunjangan', 'potongan_pinjaman', 'updated_at'],
        ));

        return redirect()->route('keuangan.komponen-gaji.index', [
            'bulan' => $validated['bulan'], 'tahun' => $validated['tahun'], 'penempatan' => $validated['penempatan'],
            'jenis_pekerjaan' => $validated['jenis_pekerjaan'] ?? null,
            'q' => filled($request->input('q')) ? mb_substr(trim((string) $request->input('q')), 0, 100) : null,
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

    /**
     * Komponen otomatis (read-only). Gaji pokok & potongan absensi mengikuti sp_generate_payroll_bulanan;
     * lembur, BPJS, dan back-up mengikuti aturan Komponen Gaji (lihat upahLembur(), potonganBpjs()).
     * Nilai ini selalu dihitung ulang di server dan tidak pernah diambil dari request.
     */
    private function komponenOtomatis(object $karyawan, int $bulan, int $tahun): array
    {
        $idKlien = null;
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
                ->first(['c.gaji_pokok', 'c.hari_kerja_per_bulan', 'p.id_klien']);

            if ($kontrak !== null && (int) $kontrak->hari_kerja_per_bulan > 0) {
                $gajiTersedia = true;
                // Klien BPJS = klien penempatan yang kontraknya dipakai sebagai sumber gaji (sama dengan Generate Payroll).
                $idKlien = (int) $kontrak->id_klien;
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

        $lembur = $this->upahLembur((int) $karyawan->id, $awal, $akhir, $gajiTersedia ? $gaji : null);
        $bpjs = $this->potonganBpjs($idKlien, $gajiTersedia ? $gaji : null);

        return [
            'gaji_pokok' => $gaji,
            'upah_lembur' => $lembur['nominal'],
            'jam_lembur' => $lembur['jam'],
            'potongan_absen' => $potongan,
            'potongan_bpjs' => $bpjs['nominal'],
            'keterangan_bpjs' => $bpjs['keterangan'],
            'hari_wajib' => $hariWajib,
            'hari_absen' => $hariAbsen,
            'status_gaji' => $gajiTersedia ? null : 'Sumber gaji belum tersedia',
            // Belum ada sumber data penugasan Back-up (Pengganti Kosong) + persetujuan Operasional + tarifnya.
            // Pertukaran Shift (barter) bukan Back-up, sehingga tidak pernah menghasilkan upah back-up.
            'upah_backup' => 0.0,
        ];
    }

    /**
     * Upah lembur dari absensi yang valid: sudah check-in dan check-out serta berstatus Hadir/Terlambat.
     * Jam lembur per absensi diambil dari tbl_absensi.jam_lembur yang diisi
     * trigger trg_auto_hitung_lembur saat check-out: FLOOR(menit melewati jam pulang jadwal / 60), dengan
     * jam pulang dari fn_jadwal_absensi (jadwal roster, termasuk shift lintas tengah malam). Akibatnya
     * kelebihan 0–59 menit = 0 jam; lembur baru dihitung setelah 1 jam penuh.
     *
     * Per absensi: jam pertama 1,5 × upah per jam, jam kedua dst. 2 × upah per jam; upah per jam = gaji pokok / 173.
     *
     * @return array{nominal: float, jam: int}
     */
    private function upahLembur(int $idKaryawan, string $awal, string $akhir, ?float $gajiPokok): array
    {
        $jamPerAbsensi = DB::table('tbl_absensi')
            ->where('id_karyawan', $idKaryawan)
            ->whereBetween('tgl', [$awal, $akhir])
            ->whereNotNull('jam_masuk')
            ->whereNotNull('jam_keluar')
            ->whereIn('status_kehadiran', ['Hadir', 'Terlambat'])
            ->where('jam_lembur', '>=', 1)
            ->pluck('jam_lembur')
            ->map(fn ($jam): int => (int) $jam);

        if ($gajiPokok === null || $gajiPokok <= 0) {
            return ['nominal' => 0.0, 'jam' => (int) $jamPerAbsensi->sum()];
        }

        // Urutan hitung sama dengan sp_generate_payroll_bulanan (sql/Komponen/15): gaji × kelipatan / 173.
        $kelipatan = $jamPerAbsensi->sum(fn (int $jam): float => 1.5 + 2 * ($jam - 1));

        return ['nominal' => round($gajiPokok * $kelipatan / self::PEMBAGI_UPAH_PER_JAM, 2), 'jam' => (int) $jamPerAbsensi->sum()];
    }

    /**
     * Potongan BPJS = persentase BPJS klien tempat karyawan bertugas × gaji pokok (dibulatkan 2 desimal
     * seperti routine database). Hanya persentase dari master klien yang dipakai: klien tanpa klien /
     * belum diatur / bukan persentase, atau gaji tidak tersedia -> tidak dihitung (tanpa nominal karangan).
     *
     * @return array{nominal: float|null, keterangan: string}
     */
    private function potonganBpjs(?int $idKlien, ?float $gajiPokok): array
    {
        if ($idKlien === null) {
            return ['nominal' => null, 'keterangan' => 'Tanpa klien penempatan'];
        }

        $klien = DB::table('tbl_klien')->where('id', $idKlien)->first(['nama_perusahaan', 'bpjs_metode', 'bpjs_nilai']);

        if ($klien === null || $klien->bpjs_metode === null || $klien->bpjs_nilai === null) {
            return ['nominal' => null, 'keterangan' => 'BPJS belum diatur pada data klien'];
        }

        if ($klien->bpjs_metode !== LabelBpjs::PERSENTASE) {
            return ['nominal' => null, 'keterangan' => 'Persentase BPJS belum diatur pada data klien'];
        }

        if ($gajiPokok === null) {
            return ['nominal' => null, 'keterangan' => 'Gaji pokok belum tersedia'];
        }

        $persen = (float) $klien->bpjs_nilai;

        return [
            'nominal' => round($gajiPokok * $persen / 100, 2),
            'keterangan' => LabelBpjs::aturan(LabelBpjs::PERSENTASE, $persen).' · '.$klien->nama_perusahaan,
        ];
    }
}

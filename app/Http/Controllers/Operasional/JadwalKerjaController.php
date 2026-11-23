<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use App\Services\JadwalKerjaService;
use App\Services\PertukaranShiftService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JadwalKerjaController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const array NAMA_BULAN = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    /**
     * @var list<string>
     */
    private const array NAMA_HARI = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

    /**
     * Kalender bulanan jadwal kerja untuk SATU karyawan.
     * Setiap tanggal dipetakan ke penempatan karyawan yang berlaku pada tanggal itu,
     * sehingga riwayat jadwal penempatan lama (sebelum Rotasi) tetap terlihat apa adanya.
     */
    public function __invoke(Request $request, JadwalKerjaService $jadwalKerjaService, PertukaranShiftService $pertukaranShiftService): View
    {
        $validated = $request->validate([
            'id_klien' => ['nullable', 'integer', 'min:1'],
            'id_karyawan' => ['nullable', 'integer', 'min:1'],
            'bulan' => ['nullable', 'date_format:Y-m'],
        ], [
            'id_klien.integer' => 'Pilihan klien tidak valid.',
            'id_karyawan.integer' => 'Pilihan karyawan tidak valid.',
            'bulan.date_format' => 'Format bulan tidak valid.',
        ]);

        $zonaWaktu = (string) config('app.timezone', 'UTC');
        $tanggalHariIni = CarbonImmutable::now($zonaWaktu)->startOfDay();
        $awalBulan = isset($validated['bulan'])
            ? CarbonImmutable::parse($validated['bulan'].'-01', $zonaWaktu)
            : $tanggalHariIni->startOfMonth();
        $akhirBulan = $awalBulan->endOfMonth()->startOfDay();
        $idKaryawan = isset($validated['id_karyawan']) ? (int) $validated['id_karyawan'] : null;
        $idKlien = isset($validated['id_klien']) ? (int) $validated['id_klien'] : null;
        $rosterTersedia = $this->rosterSchemaTersedia();

        $semuaKaryawan = DB::table('tbl_karyawan as k')
            ->join('tbl_penempatan as p', 'p.id_karyawan', '=', 'k.id')
            ->groupBy('k.id', 'k.nip', 'k.nama')
            ->orderBy('k.nama')
            ->selectRaw("k.id, k.nip, k.nama, SUM(CASE WHEN p.status = 'Aktif' THEN 1 ELSE 0 END) as jumlah_aktif")
            ->get();
        $karyawanTerpilih = $idKaryawan === null
            ? null
            : $semuaKaryawan->first(static fn (object $item): bool => (int) $item->id === $idKaryawan);
        $idKaryawanDiKlien = $idKlien === null
            ? null
            : DB::table('tbl_penempatan')->where('id_klien', $idKlien)->pluck('id_karyawan')->map(static fn (mixed $id): int => (int) $id)->all();
        $daftarKaryawan = $idKaryawanDiKlien === null
            ? $semuaKaryawan
            : $semuaKaryawan->filter(static fn (object $item): bool => in_array((int) $item->id, $idKaryawanDiKlien, true)
                || (int) $item->id === $idKaryawan)->values();

        $penempatan = collect();
        $jadwal = collect();
        $templateShift = collect();

        if ($karyawanTerpilih !== null) {
            $penempatan = DB::table('tbl_penempatan as p')
                ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
                ->where('p.id_karyawan', $karyawanTerpilih->id)
                ->orderBy('p.tgl_mulai')
                ->get(['p.id', 'p.id_klien', 'p.tgl_mulai', 'p.tgl_selesai', 'p.status', 'kl.nama_perusahaan']);

            if ($rosterTersedia) {
                $jadwal = DB::table('tbl_jadwal_kerja')
                    ->whereIn('id_penempatan', $penempatan->pluck('id')->all())
                    ->whereBetween('tanggal_kerja', [$awalBulan->toDateString(), $akhirBulan->toDateString()])
                    ->get()
                    ->keyBy(static fn (object $item): string => $item->id_penempatan.'|'.$item->tanggal_kerja);

                $templateShift = DB::table('tbl_template_shift')
                    ->whereIn('id_klien', $penempatan->where('status', 'Aktif')->pluck('id_klien')->all())
                    ->where('status', 'Aktif')
                    ->orderBy('nama_shift')
                    ->get(['id', 'id_klien', 'nama_shift', 'jam_masuk', 'jam_pulang', 'durasi_menit']);
            }
        }

        $hariKalender = [];
        $tanggalMulaiGrid = $awalBulan->startOfWeek(Carbon::MONDAY);
        $tanggalAkhirGrid = $akhirBulan->endOfWeek(Carbon::SUNDAY)->startOfDay();

        for ($tanggal = $tanggalMulaiGrid; $tanggal->lte($tanggalAkhirGrid); $tanggal = $tanggal->addDay()) {
            $tanggalString = $tanggal->toDateString();
            $dalamBulan = $tanggal->month === $awalBulan->month;
            $penempatanHari = $penempatan
                ->filter(static fn (object $item): bool => $jadwalKerjaService->tanggalBerlaku($tanggalString, $item))
                ->sortByDesc(static fn (object $item): int => $item->status === 'Aktif' ? 1 : 0)
                ->first();
            $jadwalHari = $penempatanHari === null ? null : $jadwal->get($penempatanHari->id.'|'.$tanggalString);

            $hariKalender[] = [
                'tanggal' => $tanggal,
                'tanggal_string' => $tanggalString,
                'dalam_bulan' => $dalamBulan,
                'hari_ini' => $tanggal->isSameDay($tanggalHariIni),
                'status' => match (true) {
                    $karyawanTerpilih === null || ! $dalamBulan => null,
                    $penempatanHari === null => 'Di Luar Penempatan',
                    $jadwalHari !== null => $jadwalHari->status,
                    default => 'Belum Diatur',
                },
                'jadwal' => $jadwalHari,
                'pulang_besok' => $jadwalHari !== null
                    && $jadwalKerjaService->pulangHariBerikutnya($jadwalHari->jam_masuk, $jadwalHari->jam_pulang),
                'penempatan' => $penempatanHari,
                'dapat_diatur' => $rosterTersedia && $dalamBulan && $penempatanHari?->status === 'Aktif',
            ];
        }

        $nomorBulan = (int) $awalBulan->format('n');
        $penempatanAktif = $penempatan->firstWhere('status', 'Aktif');
        $templateJadwalTersedia = $rosterTersedia && Schema::hasTable('tbl_template_jadwal');
        $idKlienKaryawan = $penempatan->pluck('id_klien')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();

        return view('operasional.jadwal-kerja.index', [
            'rosterTersedia' => $rosterTersedia,
            'jumlahPertukaranMenunggu' => $pertukaranShiftService->jumlahMenunggu(),
            'daftarKaryawan' => $daftarKaryawan,
            'daftarKlienFilter' => DB::table('tbl_klien as kl')
                ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('tbl_penempatan as p')->whereColumn('p.id_klien', 'kl.id'))
                ->orderBy('kl.nama_perusahaan')
                ->get(['kl.id', 'kl.nama_perusahaan']),
            'idKlien' => $idKlien,
            'idKaryawan' => $idKaryawan,
            'karyawanTerpilih' => $karyawanTerpilih,
            'penempatan' => $penempatan,
            'penempatanAktif' => $penempatanAktif,
            'templateJadwalTersedia' => $templateJadwalTersedia,
            'jumlahJadwalBulan' => $jadwal->count(),
            'templateJadwalTujuan' => $templateJadwalTersedia && $karyawanTerpilih !== null
                ? DB::table('tbl_template_jadwal')
                    ->where('status', 'Aktif')
                    ->where(fn ($query) => $query->where('id_karyawan', $karyawanTerpilih->id)->orWhereIn('id_klien', $idKlienKaryawan))
                    ->orderBy('jenis')
                    ->orderBy('nama_template')
                    ->get(['id', 'nama_template', 'jenis'])
                : collect(),
            'daftarKlienTemplate' => $templateJadwalTersedia && $karyawanTerpilih !== null
                ? DB::table('tbl_klien')->whereIn('id', $idKlienKaryawan)->orderBy('nama_perusahaan')->get(['id', 'nama_perusahaan'])
                : collect(),
            'daftarLayananTemplate' => $templateJadwalTersedia && $karyawanTerpilih !== null
                ? DB::table('tbl_jenis_layanan')->orderBy('nama_layanan')->get(['id', 'nama_layanan'])
                : collect(),
            'templateShift' => $templateShift,
            'riwayatJadwal' => $karyawanTerpilih !== null && Schema::hasView('v_riwayat_jadwal_kerja')
                ? DB::table('v_riwayat_jadwal_kerja')
                    ->where('id_karyawan', $karyawanTerpilih->id)
                    ->whereBetween('tanggal_kerja', [$awalBulan->toDateString(), $awalBulan->endOfMonth()->toDateString()])
                    ->orderByDesc('id')
                    ->limit(20)
                    ->get()
                : collect(),
            'awalBulan' => $awalBulan,
            'judulBulan' => self::NAMA_BULAN[$nomorBulan].' '.$awalBulan->format('Y'),
            'namaBulan' => self::NAMA_BULAN,
            'namaHari' => self::NAMA_HARI,
            'hariKalender' => $hariKalender,
            'navigasi' => [
                'sebelumnya' => $this->urlKalender($idKaryawan, $awalBulan->subMonth(), $idKlien),
                'berikutnya' => $this->urlKalender($idKaryawan, $awalBulan->addMonth(), $idKlien),
                'bulan_ini' => $this->urlKalender($idKaryawan, $tanggalHariIni, $idKlien),
            ],
        ]);
    }

    /**
     * Panel Pengaturan Tanggal: menerapkan satu aksi ke semua tanggal yang dipilih.
     */
    public function atur(Request $request, JadwalKerjaService $jadwalKerjaService): RedirectResponse
    {
        $this->pastikanSkemaRosterTersedia();
        $validated = $request->validate([
            'id_karyawan' => ['required', 'integer', 'exists:tbl_karyawan,id'],
            'bulan' => ['required', 'date_format:Y-m'],
            'aksi' => ['required', Rule::in([
                JadwalKerjaService::AKSI_KERJA,
                JadwalKerjaService::AKSI_LIBUR,
                JadwalKerjaService::AKSI_JADIKAN_KERJA,
            ])],
            'tanggal' => ['required', 'array', 'min:1', 'max:62'],
            'tanggal.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'id_template_shift' => ['nullable', 'integer', 'exists:tbl_template_shift,id'],
            'jam_masuk' => ['nullable', 'date_format:H:i', 'required_with:jam_pulang'],
            'jam_pulang' => ['nullable', 'date_format:H:i', 'required_with:jam_masuk'],
        ], [
            'aksi.required' => 'Tindakan wajib dipilih.',
            'aksi.in' => 'Tindakan tidak valid.',
            'tanggal.required' => 'Pilih minimal satu tanggal pada kalender.',
            'tanggal.min' => 'Pilih minimal satu tanggal pada kalender.',
            'tanggal.*.date_format' => 'Format tanggal tidak valid.',
            'tanggal.*.distinct' => 'Tanggal yang dipilih tidak boleh berulang.',
            'id_template_shift.exists' => 'Jam tersimpan yang dipilih tidak tersedia.',
            'jam_masuk.date_format' => 'Format jam masuk tidak valid.',
            'jam_masuk.required_with' => 'Jam masuk wajib diisi bila jam pulang diisi.',
            'jam_pulang.date_format' => 'Format jam pulang tidak valid.',
            'jam_pulang.required_with' => 'Jam pulang wajib diisi bila jam masuk diisi.',
        ]);

        try {
            $hasil = $jadwalKerjaService->aturTanggal(
                (int) $validated['id_karyawan'],
                $validated['tanggal'],
                $validated['aksi'],
                $validated,
                (int) $request->user()->id,
            );
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return back()->withInput()->with('error', 'Jadwal berubah saat disimpan. Muat ulang kalender lalu coba kembali.');
        }

        $pesan = match ($validated['aksi']) {
            JadwalKerjaService::AKSI_LIBUR => "{$hasil['disimpan']} tanggal ditandai Libur.",
            JadwalKerjaService::AKSI_JADIKAN_KERJA => "{$hasil['disimpan']} tanggal Libur dijadikan hari kerja."
                .($hasil['dilewati'] === [] ? '' : ' '.count($hasil['dilewati']).' tanggal lain bukan Libur sehingga tidak diubah.'),
            default => "Jam kerja diterapkan ke {$hasil['disimpan']} tanggal.",
        };

        return redirect()->to($this->urlKalender(
            (int) $validated['id_karyawan'],
            CarbonImmutable::parse($validated['bulan'].'-01'),
        ))->with('success', $pesan);
    }

    public function store(Request $request, JadwalKerjaService $jadwalKerjaService): RedirectResponse
    {
        $this->pastikanSkemaRosterTersedia();
        $validated = $request->validate($this->aturanJadwal(), $this->pesanValidasiJadwal());

        try {
            $jadwalKerjaService->buatJadwal($validated, (int) $request->user()->id);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return back()->withInput()->with('error', 'Jadwal untuk tanggal tersebut sudah dibuat.');
        }

        return $this->kembaliKeKalender((int) $validated['id_penempatan'], $validated['tanggal_kerja'])
            ->with('success', 'Jadwal kerja berhasil ditambahkan.');
    }

    public function update(Request $request, int $jadwal, JadwalKerjaService $jadwalKerjaService): RedirectResponse
    {
        $this->pastikanSkemaRosterTersedia();
        $validated = $request->validate($this->aturanJadwal(false), $this->pesanValidasiJadwal());
        $dataSebelum = DB::table('tbl_jadwal_kerja')->where('id', $jadwal)->firstOrFail();

        $jadwalKerjaService->ubahJadwal($jadwal, $validated, (int) $request->user()->id);

        return $this->kembaliKeKalender((int) $dataSebelum->id_penempatan, (string) $dataSebelum->tanggal_kerja)
            ->with('success', 'Jadwal kerja berhasil diperbarui.');
    }

    public function previewCopy(Request $request, JadwalKerjaService $jadwalKerjaService): View
    {
        $this->pastikanSkemaRosterTersedia();
        $validated = $request->validate([
            'id_penempatan' => ['required', 'integer', 'exists:tbl_penempatan,id'],
            'bulan_tujuan' => ['required', 'date_format:Y-m'],
            'bulan_sumber' => ['nullable', 'date_format:Y-m', 'different:bulan_tujuan'],
        ], $this->pesanValidasiSalin());

        $pratinjau = $jadwalKerjaService->pratinjauSalinBulanan(
            (int) $validated['id_penempatan'],
            $validated['bulan_tujuan'],
            $validated['bulan_sumber'] ?? null,
        );

        return view('operasional.jadwal-kerja.copy-preview', [
            ...$pratinjau,
            'idPenempatan' => (int) $validated['id_penempatan'],
            'judulBulanSumber' => $this->judulBulan($pratinjau['bulan_sumber']),
            'judulBulanTujuan' => $this->judulBulan($pratinjau['bulan_tujuan']),
            'urlKalender' => $this->urlKalender(
                (int) $pratinjau['penempatan']->id_karyawan,
                CarbonImmutable::parse($pratinjau['bulan_tujuan'].'-01'),
            ),
        ]);
    }

    public function copy(Request $request, JadwalKerjaService $jadwalKerjaService): RedirectResponse
    {
        $this->pastikanSkemaRosterTersedia();
        $validated = $request->validate([
            'id_penempatan' => ['required', 'integer', 'exists:tbl_penempatan,id'],
            'bulan_tujuan' => ['required', 'date_format:Y-m'],
            'bulan_sumber' => ['nullable', 'date_format:Y-m', 'different:bulan_tujuan'],
            'item' => ['required', 'array'],
            'item.*.tanggal_sumber' => ['required', 'date_format:Y-m-d', 'distinct'],
            'item.*.status' => ['required', Rule::in(['Kerja', 'Libur'])],
            'item.*.id_template_shift' => ['nullable', 'integer', 'exists:tbl_template_shift,id'],
            'item.*.jam_masuk' => ['nullable', 'date_format:H:i'],
            'item.*.jam_pulang' => ['nullable', 'date_format:H:i'],
            'mode_konflik' => ['nullable', Rule::in([JadwalKerjaService::KONFLIK_LEWATI, JadwalKerjaService::KONFLIK_GANTI])],
        ], [
            ...$this->pesanValidasiSalin(),
            'item.required' => 'Tidak ada jadwal sumber yang dapat disalin.',
            'item.*.tanggal_sumber.distinct' => 'Tanggal sumber tidak boleh berulang.',
            'mode_konflik.in' => 'Pilihan penanganan konflik tidak valid.',
        ]);

        try {
            $hasil = $jadwalKerjaService->simpanSalinanBulanan(
                (int) $validated['id_penempatan'],
                $validated['bulan_tujuan'],
                $validated['bulan_sumber'] ?? null,
                $validated['item'],
                $validated['mode_konflik'] ?? null,
                (int) $request->user()->id,
            );
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return back()->withInput()->with('error', 'Jadwal tujuan berubah saat proses salin. Muat ulang pratinjau dan coba kembali.');
        }

        return $this->kembaliKeKalender((int) $validated['id_penempatan'], $validated['bulan_tujuan'].'-01')->with(
            'success',
            "Salinan selesai: {$hasil['disalin']} jadwal ditambahkan, {$hasil['diganti']} jadwal diganti, dan {$hasil['dilewati']} tanggal berkonflik dilewati.",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function aturanJadwal(bool $membuat = true): array
    {
        return [
            ...($membuat ? ['id_penempatan' => ['required', 'integer', 'exists:tbl_penempatan,id']] : []),
            ...($membuat ? ['tanggal_kerja' => ['required', 'date_format:Y-m-d']] : []),
            'status' => ['required', Rule::in(['Kerja', 'Libur'])],
            'id_template_shift' => ['nullable', 'integer', 'exists:tbl_template_shift,id'],
            'jam_masuk' => ['nullable', 'date_format:H:i', 'required_with:jam_pulang'],
            'jam_pulang' => ['nullable', 'date_format:H:i', 'required_with:jam_masuk'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pesanValidasiJadwal(): array
    {
        return [
            'id_penempatan.required' => 'Penempatan karyawan wajib dipilih.',
            'id_penempatan.exists' => 'Penempatan yang dipilih tidak tersedia.',
            'tanggal_kerja.required' => 'Tanggal kerja wajib dipilih.',
            'tanggal_kerja.date_format' => 'Format tanggal kerja tidak valid.',
            'status.required' => 'Status jadwal wajib dipilih.',
            'status.in' => 'Status jadwal tidak valid.',
            'id_template_shift.exists' => 'Jam tersimpan yang dipilih tidak tersedia.',
            'jam_masuk.date_format' => 'Format jam masuk tidak valid.',
            'jam_masuk.required_with' => 'Jam masuk wajib diisi bila jam pulang diisi.',
            'jam_pulang.date_format' => 'Format jam pulang tidak valid.',
            'jam_pulang.required_with' => 'Jam pulang wajib diisi bila jam masuk diisi.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pesanValidasiSalin(): array
    {
        return [
            'id_penempatan.required' => 'Karyawan dengan penempatan aktif wajib dipilih.',
            'bulan_tujuan.required' => 'Bulan tujuan wajib dipilih.',
            'bulan_tujuan.date_format' => 'Format bulan tujuan tidak valid.',
            'bulan_sumber.date_format' => 'Format bulan sumber tidak valid.',
            'bulan_sumber.different' => 'Bulan sumber dan bulan tujuan tidak boleh sama.',
        ];
    }

    private function rosterSchemaTersedia(): bool
    {
        return Schema::hasTable('tbl_jadwal_kerja')
            && Schema::hasTable('tbl_template_shift')
            && Schema::hasTable('tbl_jadwal_kerja_riwayat');
    }

    private function pastikanSkemaRosterTersedia(): void
    {
        abort_unless($this->rosterSchemaTersedia(), 503, 'Fitur Jadwal Kerja belum tersedia sebelum skema roster diterapkan.');
    }

    private function kembaliKeKalender(int $idPenempatan, string $tanggal): RedirectResponse
    {
        $idKaryawan = (int) DB::table('tbl_penempatan')->where('id', $idPenempatan)->value('id_karyawan');

        return redirect()->to($this->urlKalender($idKaryawan, CarbonImmutable::parse($tanggal)));
    }

    private function judulBulan(string $bulan): string
    {
        $tanggal = CarbonImmutable::parse($bulan.'-01');

        return self::NAMA_BULAN[(int) $tanggal->format('n')].' '.$tanggal->format('Y');
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23505'
            || ($sqlState === '23000' && $driverCode === 1062)
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }

    private function urlKalender(?int $idKaryawan, CarbonImmutable $bulan, ?int $idKlien = null): string
    {
        return route('operasional.jadwal-kerja', array_filter([
            'id_klien' => $idKlien,
            'id_karyawan' => $idKaryawan,
            'bulan' => $bulan->format('Y-m'),
        ], static fn (mixed $nilai): bool => $nilai !== null));
    }
}

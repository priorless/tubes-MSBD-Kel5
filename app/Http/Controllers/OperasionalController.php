<?php

namespace App\Http\Controllers;

use App\Services\DatabaseService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperasionalController extends Controller
{
    /**
     * Daftar penempatan dengan pencarian dan filter klien, jenis layanan, serta status.
     */
    public function indexPenempatan(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'id_klien' => ['nullable', 'integer'],
            'id_jenis_layanan' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['Aktif', 'Selesai', 'Rotasi'])],
        ], [
            'q.max' => 'Kata pencarian maksimal 100 karakter.',
            'id_klien.integer' => 'Filter klien tidak valid.',
            'id_jenis_layanan.integer' => 'Filter jenis layanan tidak valid.',
            'status.in' => 'Filter status tidak valid.',
        ]);
        $filter = [
            'q' => trim((string) ($validated['q'] ?? '')),
            'id_klien' => isset($validated['id_klien']) ? (int) $validated['id_klien'] : null,
            'id_jenis_layanan' => isset($validated['id_jenis_layanan']) ? (int) $validated['id_jenis_layanan'] : null,
            'status' => $validated['status'] ?? null,
        ];
        $query = DB::table('tbl_penempatan as p')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
            ->when($filter['q'] !== '', fn ($q) => $q->where(fn ($cari) => $cari
                ->where('k.nama', 'like', '%'.$filter['q'].'%')
                ->orWhere('k.nip', 'like', '%'.$filter['q'].'%')
                ->orWhere('p.lokasi_kerja', 'like', '%'.$filter['q'].'%')))
            ->when($filter['id_klien'] !== null, fn ($q) => $q->where('p.id_klien', $filter['id_klien']))
            ->when($filter['id_jenis_layanan'] !== null, fn ($q) => $q->where('k.id_jenis_layanan', $filter['id_jenis_layanan']));

        return view('operasional.penempatan.index', [
            'filter' => $filter,
            'jumlahPerStatus' => (clone $query)->groupBy('p.status')->selectRaw('p.status, COUNT(*) as jumlah')->pluck('jumlah', 'status'),
            'penempatan' => $query
                ->when($filter['status'] !== null, fn ($q) => $q->where('p.status', $filter['status']))
                ->orderByRaw("CASE WHEN p.status = 'Aktif' THEN 0 ELSE 1 END")
                ->orderByDesc('p.tgl_mulai')
                ->orderBy('k.nama')
                ->select(['p.id', 'p.id_karyawan', 'p.lokasi_kerja', 'p.tgl_mulai', 'p.tgl_selesai', 'p.status', 'k.nip', 'k.nama', 'kl.nama_perusahaan', 'jl.nama_layanan'])
                ->paginate(20)
                ->withQueryString(),
            'daftarKlien' => DB::table('tbl_klien')->orderBy('nama_perusahaan')->get(['id', 'nama_perusahaan']),
            'daftarLayanan' => DB::table('tbl_jenis_layanan')->orderBy('nama_layanan')->get(['id', 'nama_layanan']),
        ]);
    }

    /**
     * Detail satu penempatan: karyawan, klien, periode, kontrak, jadwal bulan ini, dan riwayat penempatan karyawan.
     */
    public function showPenempatan(int $penempatan): View
    {
        $data = DB::table('tbl_penempatan as p')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
            ->where('p.id', $penempatan)
            ->first(['p.*', 'k.nip', 'k.nama', 'kl.nama_perusahaan', 'kl.status as status_klien', 'jl.nama_layanan']);
        abort_if($data === null, 404);

        $awalBulan = now()->startOfMonth();
        $jadwalBulanIni = Schema::hasTable('tbl_jadwal_kerja')
            ? DB::table('tbl_jadwal_kerja')
                ->where('id_penempatan', $penempatan)
                ->whereBetween('tanggal_kerja', [$awalBulan->toDateString(), $awalBulan->copy()->endOfMonth()->toDateString()])
                ->groupBy('status')
                ->selectRaw('status, COUNT(*) as jumlah')
                ->pluck('jumlah', 'status')
            : null;

        return view('operasional.penempatan.show', [
            'penempatan' => $data,
            'kontrak' => DB::table('tbl_kontrak')->where('id_penempatan', $penempatan)->orderByDesc('id')->first(['status']),
            'jadwalBulanIni' => $jadwalBulanIni,
            'namaBulan' => $awalBulan->locale('id')->translatedFormat('F Y'),
            'riwayatKaryawan' => DB::table('tbl_penempatan as p')
                ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
                ->where('p.id_karyawan', $data->id_karyawan)
                ->where('p.id', '!=', $penempatan)
                ->orderByDesc('p.tgl_mulai')
                ->get(['p.id', 'p.tgl_mulai', 'p.tgl_selesai', 'p.status', 'kl.nama_perusahaan']),
        ]);
    }

    public function createPenempatan(Request $request): View
    {
        return view('operasional.penempatan.create', [
            'karyawan' => DB::table('tbl_karyawan as k')
                ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 'k.id_jenis_layanan')
                ->where('k.kategori', 'Lapangan')
                ->where('k.status', 'Tersedia')
                ->orderBy('k.nama')
                ->get(['k.id', 'k.nip', 'k.nama', 'jl.nama_layanan']),
            'klien' => DB::table('tbl_klien')
                ->where('status', 'Aktif')
                ->orderBy('nama_perusahaan')
                ->get(['id', 'kode_klien', 'nama_perusahaan']),
            'idKlienAwal' => $request->integer('id_klien') ?: null,
        ]);
    }

    public function storePenempatan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id_karyawan' => ['bail', 'required', 'integer', 'exists:tbl_karyawan,id'],
            'id_klien' => ['bail', 'required', 'integer', 'exists:tbl_klien,id'],
            'tgl_mulai' => ['bail', 'required', 'date'],
            'tgl_selesai' => ['nullable', 'date', 'after_or_equal:tgl_mulai'],
            'lokasi' => ['bail', 'required', 'string', 'max:255'],
            'jam_datang' => ['bail', 'required', 'date_format:H:i'],
            'jam_pulang' => ['bail', 'required', 'date_format:H:i', 'different:jam_datang'],
        ], $this->operationValidationMessages());

        $userId = Auth::id();

        if ($userId === null) {
            return back()->withInput()->with('error', 'Silakan masuk terlebih dahulu untuk membuat penempatan.');
        }

        $akhirPenempatanSebelumnya = $this->akhirPenempatanYangBertumpuk((int) $validated['id_karyawan'], $validated['tgl_mulai']);

        if ($akhirPenempatanSebelumnya !== null) {
            return back()->withInput()->withErrors([
                'tgl_mulai' => 'Tanggal mulai harus setelah penempatan sebelumnya berakhir ('
                    .Carbon::parse($akhirPenempatanSebelumnya)->locale('id')->translatedFormat('d F Y')
                    .'), agar gaji dan tagihan tidak terhitung dua kali.',
            ]);
        }

        try {
            DatabaseService::callAssignPenempatan(
                (int) $validated['id_karyawan'],
                (int) $validated['id_klien'],
                $validated['tgl_mulai'],
                $validated['tgl_selesai'] ?? null,
                $validated['lokasi'],
                $validated['jam_datang'],
                $validated['jam_pulang'],
                (int) $userId,
            );
        } catch (QueryException $exception) {
            $message = $this->signalMessage($exception);

            if ($message === null) {
                throw $exception;
            }

            return back()->withInput()->with('error', $message);
        }

        return redirect()->route('operasional.penempatan.index')
            ->with('success', 'Penempatan karyawan berhasil dibuat. Langkah berikutnya: atur jadwal kerjanya di menu Jadwal Kerja.');
    }

    /**
     * Rotasi bertahap. Untuk tiap penempatan Aktif disertakan tanggal jadwal mulai hari ini, sehingga langkah ringkasan
     * dapat menunjukkan jadwal lama yang tidak ikut dipindahkan (roster tidak pernah dipindah otomatis).
     */
    public function createRotasi(Request $request): View
    {
        $penempatan = DB::table('tbl_penempatan as p')
            ->join('tbl_karyawan as k', 'k.id', '=', 'p.id_karyawan')
            ->join('tbl_klien as kl', 'kl.id', '=', 'p.id_klien')
            ->where('p.status', 'Aktif')
            ->orderBy('k.nama')
            ->get([
                'p.id',
                'p.id_klien',
                'p.lokasi_kerja',
                'p.jam_datang',
                'p.jam_pulang',
                'p.tgl_mulai',
                'p.tgl_selesai',
                'k.nip',
                'k.nama as nama_karyawan',
                'kl.nama_perusahaan',
            ]);
        $jadwalMendatang = Schema::hasTable('tbl_jadwal_kerja') && $penempatan->isNotEmpty()
            ? DB::table('tbl_jadwal_kerja')
                ->whereIn('id_penempatan', $penempatan->pluck('id')->all())
                ->where('tanggal_kerja', '>=', now()->toDateString())
                ->orderBy('tanggal_kerja')
                ->get(['id_penempatan', 'tanggal_kerja'])
                ->groupBy('id_penempatan')
                ->map(static fn ($baris): array => $baris->pluck('tanggal_kerja')->all())
                ->all()
            : [];

        return view('operasional.rotasi.create', [
            'penempatan' => $penempatan,
            'klien' => DB::table('tbl_klien')
                ->where('status', 'Aktif')
                ->orderBy('nama_perusahaan')
                ->get(['id', 'kode_klien', 'nama_perusahaan']),
            'jadwalMendatang' => $jadwalMendatang,
            'idPenempatanAwal' => $request->integer('id_penempatan') ?: null,
        ]);
    }

    public function storeRotasi(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id_penempatan_lama' => ['bail', 'required', 'integer', 'exists:tbl_penempatan,id'],
            'id_klien_baru' => ['bail', 'required', 'integer', 'exists:tbl_klien,id'],
            'tgl_mulai_baru' => ['bail', 'required', 'date'],
            'tgl_selesai_baru' => ['nullable', 'date', 'after_or_equal:tgl_mulai_baru'],
            'lokasi_baru' => ['bail', 'required', 'string', 'max:255'],
            'jam_datang_baru' => ['bail', 'nullable', 'required_with:jam_pulang_baru', 'date_format:H:i'],
            'jam_pulang_baru' => ['bail', 'nullable', 'required_with:jam_datang_baru', 'date_format:H:i', 'different:jam_datang_baru'],
        ], $this->operationValidationMessages());

        $userId = Auth::id();

        if ($userId === null) {
            return back()->withInput()->with('error', 'Silakan masuk terlebih dahulu untuk memproses rotasi.');
        }

        try {
            DatabaseService::callRotasiKaryawan(
                $validated['id_penempatan_lama'],
                $validated['id_klien_baru'],
                $validated['tgl_mulai_baru'],
                $validated['tgl_selesai_baru'] ?? null,
                $validated['lokasi_baru'],
                $validated['jam_datang_baru'] ?? null,
                $validated['jam_pulang_baru'] ?? null,
                $userId,
            );
        } catch (QueryException $exception) {
            $message = $this->signalMessage($exception);

            if ($message === null) {
                throw $exception;
            }

            return back()->withInput()->with('error', $message);
        }

        return redirect()->route('operasional.monitoring')->with('success', 'Rotasi karyawan berhasil diproses.');
    }

    /**
     * Monitoring sebaran dari view v_monitoring_sebaran_operasional (hanya penempatan Aktif).
     * Ringkasan per klien dan per jenis layanan, serta penempatan yang perlu diperiksa
     * (sisa kontrak < 30 hari atau masa penempatan sudah lewat).
     */
    public function monitoring(): View
    {
        $semua = DB::table('v_monitoring_sebaran_operasional')
            ->get(['id_penempatan', 'nip', 'nama', 'nama_layanan', 'nama_perusahaan', 'tgl_selesai', 'sisa_hari_kontrak']);
        $hitung = static fn (string $kolom) => $semua->countBy($kolom)->sortDesc()
            ->map(static fn (int $jumlah, string $nama): object => (object) ['nama' => $nama, 'jumlah' => $jumlah])
            ->values();

        return view('operasional.monitoring.index', [
            'penempatan' => DB::table('v_monitoring_sebaran_operasional')
                ->orderBy('sisa_hari_kontrak')
                ->paginate(15),
            'ringkasan' => [
                'jumlah_karyawan' => $semua->pluck('nip')->unique()->count(),
                'jumlah_penempatan' => $semua->count(),
                'per_klien' => $hitung('nama_perusahaan'),
                'per_layanan' => $hitung('nama_layanan'),
                'perlu_diperiksa' => $semua
                    ->filter(static fn (object $item): bool => (int) $item->sisa_hari_kontrak < 30)
                    ->sortBy(static fn (object $item): int => (int) $item->sisa_hari_kontrak)
                    ->values(),
            ],
        ]);
    }

    /**
     * Tanggal selesai terakhir dari penempatan karyawan yang sudah berakhir (Selesai/Rotasi) tetapi periodenya
     * masih mencakup atau melewati $tglMulai. Periode yang bertumpuk membuat payroll dan invoice prorata
     * (fn_hari_penempatan) menghitung hari yang sama di dua klien. Null = tidak bertumpuk.
     *
     * Penempatan Aktif tidak diperiksa di sini: sp_assign_penempatan sudah menolak karyawan yang tidak Tersedia.
     * Memakai DB::connection() agar tetap berjalan saat facade DB di-mock pada pengujian.
     */
    private function akhirPenempatanYangBertumpuk(int $idKaryawan, string $tglMulai): ?string
    {
        $akhir = DB::connection()->table('tbl_penempatan')
            ->where('id_karyawan', $idKaryawan)
            ->where('status', '!=', 'Aktif')
            ->where('tgl_selesai', '>=', Carbon::parse($tglMulai)->toDateString())
            ->max('tgl_selesai');

        return $akhir === null ? null : (string) $akhir;
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
            'id_klien_baru.required' => 'Klien wajib dipilih.',
            'id_klien_baru.integer' => 'Pilihan klien tidak valid.',
            'id_klien_baru.exists' => 'Klien yang dipilih tidak ditemukan.',
            'tgl_mulai.required' => 'Tanggal mulai wajib diisi.',
            'tgl_mulai.date' => 'Tanggal mulai tidak valid.',
            'tgl_selesai.date' => 'Tanggal selesai tidak valid.',
            'tgl_selesai.after_or_equal' => 'Tanggal selesai tidak boleh lebih awal daripada tanggal mulai.',
            'tgl_mulai_baru.required' => 'Tanggal Mulai Penempatan wajib diisi.',
            'tgl_mulai_baru.date' => 'Tanggal Mulai Penempatan tidak valid.',
            'tgl_selesai_baru.date' => 'Tanggal Selesai Kontrak tidak valid.',
            'tgl_selesai_baru.after_or_equal' => 'Tanggal Selesai Kontrak tidak boleh lebih awal daripada Tanggal Mulai Penempatan.',
            'lokasi.required' => 'Lokasi kerja wajib diisi.',
            'lokasi.max' => 'Lokasi kerja maksimal 255 karakter.',
            'lokasi_baru.required' => 'Lokasi Penempatan wajib diisi.',
            'lokasi_baru.max' => 'Lokasi Penempatan maksimal 255 karakter.',
            'jam_datang.required' => 'Jam datang wajib diisi.',
            'jam_datang.date_format' => 'Format jam datang tidak valid (JJ:MM).',
            'jam_pulang.required' => 'Jam pulang wajib diisi.',
            'jam_pulang.date_format' => 'Format jam pulang tidak valid (JJ:MM).',
            'jam_pulang.different' => 'Jam pulang tidak boleh sama dengan jam datang.',
            'jam_datang_baru.required_with' => 'Jam Datang wajib diisi bila Jam Pulang diisi.',
            'jam_datang_baru.date_format' => 'Format Jam Datang tidak valid (JJ:MM).',
            'jam_pulang_baru.required_with' => 'Jam Pulang wajib diisi bila Jam Datang diisi.',
            'jam_pulang_baru.date_format' => 'Format Jam Pulang tidak valid (JJ:MM).',
            'jam_pulang_baru.different' => 'Jam Pulang tidak boleh sama dengan Jam Datang.',
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
        $databaseMessage = match ($databaseMessage) {
            'Tanggal mulai penempatan baru wajib diisi' => 'Tanggal Mulai Penempatan wajib diisi.',
            'Tanggal selesai kontrak baru tidak boleh sebelum tanggal mulai' => 'Tanggal Selesai Kontrak tidak boleh lebih awal daripada Tanggal Mulai Penempatan.',
            'Tanggal mulai baru harus setelah tanggal mulai penempatan lama' => 'Tanggal Mulai Penempatan harus setelah tanggal mulai penempatan lama.',
            'Lokasi kerja baru wajib diisi' => 'Lokasi Penempatan wajib diisi.',
            'Klien tujuan tidak ditemukan' => 'Klien yang dipilih tidak ditemukan.',
            'Klien tujuan berstatus Nonaktif' => 'Klien yang dipilih berstatus Nonaktif.',
            default => $databaseMessage,
        };

        return $databaseMessage === ''
            ? 'Operasi ditolak oleh aturan database. Periksa kembali status karyawan atau penempatan.'
            : 'Operasi ditolak oleh aturan database: '.$databaseMessage;
    }
}

<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use App\Services\TemplateJadwalService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Template Jadwal Bulanan (Pribadi & Bersama). Seluruh aturan bisnis ada di TemplateJadwalService;
 * controller hanya memvalidasi format input dan meneruskan hasil ke pengguna.
 */
class TemplateJadwalController extends Controller
{
    public function __construct(private TemplateJadwalService $templateJadwalService) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'jenis' => ['nullable', Rule::in([TemplateJadwalService::JENIS_PRIBADI, TemplateJadwalService::JENIS_BERSAMA])],
        ], ['jenis.in' => 'Filter jenis pola tidak valid.']);
        $jenis = $validated['jenis'] ?? null;

        return view('operasional.jadwal-kerja.template-jadwal.index', [
            'jenis' => $jenis,
            'jumlahPerJenis' => DB::table('tbl_template_jadwal')->groupBy('jenis')->selectRaw('jenis, COUNT(*) as jumlah')->pluck('jumlah', 'jenis'),
            'daftarTemplate' => $this->queryTemplate()
                ->when($jenis !== null, fn ($query) => $query->where('t.jenis', $jenis))
                ->selectSub(fn ($query) => $query->from('tbl_template_jadwal_detail as d')->whereColumn('d.id_template_jadwal', 't.id')->selectRaw('COUNT(*)'), 'jumlah_hari')
                ->selectSub(fn ($query) => $query->from('tbl_template_jadwal_penerapan as pn')->whereColumn('pn.id_template_jadwal', 't.id')->selectRaw('COUNT(*)'), 'jumlah_penerapan')
                ->orderByRaw("CASE WHEN t.status = 'Aktif' THEN 0 ELSE 1 END")
                ->orderBy('t.nama_template')
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('operasional.jadwal-kerja.template-jadwal.form', [
            ...$this->pilihanForm(),
            'template' => null,
            'jenisAwal' => in_array($request->query('jenis'), [TemplateJadwalService::JENIS_PRIBADI, TemplateJadwalService::JENIS_BERSAMA], true)
                ? $request->query('jenis')
                : TemplateJadwalService::JENIS_PRIBADI,
            'pola' => [],
        ]);
    }

    public function show(int $templateJadwal): View
    {
        $template = $this->ambilTemplateLengkap($templateJadwal);

        return view('operasional.jadwal-kerja.template-jadwal.show', [
            'template' => $template,
            'pola' => $this->polaTemplate($templateJadwal),
            'riwayatPenerapan' => DB::table('tbl_template_jadwal_penerapan as pn')
                ->join('tbl_karyawan as k', 'k.id', '=', 'pn.id_karyawan')
                ->leftJoin('tbl_users as u', 'u.id', '=', 'pn.diterapkan_oleh')
                ->where('pn.id_template_jadwal', $templateJadwal)
                ->orderByDesc('pn.created_at')
                ->orderByDesc('pn.id')
                ->get(['pn.*', 'k.nip', 'k.nama as nama_karyawan', 'u.nama as nama_user']),
        ]);
    }

    public function edit(int $templateJadwal): View
    {
        return view('operasional.jadwal-kerja.template-jadwal.form', [
            ...$this->pilihanForm(),
            'template' => $this->ambilTemplateLengkap($templateJadwal),
            'jenisAwal' => null,
            'pola' => $this->polaTemplate($templateJadwal),
        ]);
    }

    /**
     * Langkah 1: pilih bulan (dan karyawan untuk Template Bersama). Langkah 2: pratinjau per tanggal,
     * lalu formulir konfirmasi dengan pilihan Lewati/Timpa dikirim ke aksi apply().
     */
    public function applyPreview(Request $request, int $templateJadwal): View|RedirectResponse
    {
        $template = $this->ambilTemplateLengkap($templateJadwal);

        if ($template->status !== 'Aktif') {
            return $this->keDetail($templateJadwal)->with('error', 'Pola yang dinonaktifkan tidak dapat dipakai.');
        }

        $validated = $request->validate([
            'bulan' => ['nullable', 'date_format:Y-m'],
            'id_karyawan' => ['nullable', 'array'],
            'id_karyawan.*' => ['integer', 'distinct'],
        ], $this->pesanValidasi());
        $bulan = $validated['bulan'] ?? null;
        $idKaryawanTerpilih = array_map('intval', $validated['id_karyawan'] ?? []);
        $pribadi = $template->jenis === TemplateJadwalService::JENIS_PRIBADI;
        $pratinjau = $bulan !== null && ($pribadi || $idKaryawanTerpilih !== [])
            ? $this->templateJadwalService->pratinjauPenerapan($templateJadwal, $bulan, $idKaryawanTerpilih)
            : null;

        return view('operasional.jadwal-kerja.template-jadwal.apply', [
            'template' => $template,
            'bulan' => $bulan,
            'bulanAwal' => $bulan ?? now()->addMonthNoOverflow()->format('Y-m'),
            'idKaryawanTerpilih' => $idKaryawanTerpilih,
            'kandidatKaryawan' => $pribadi ? collect() : DB::table('tbl_karyawan as k')
                ->whereExists(fn ($query) => $query->select(DB::raw(1))
                    ->from('tbl_penempatan as p')
                    ->whereColumn('p.id_karyawan', 'k.id')
                    ->where('p.status', 'Aktif')
                    ->where('p.id_klien', $template->id_klien))
                ->when($template->id_jenis_layanan !== null, fn ($query) => $query->where('k.id_jenis_layanan', $template->id_jenis_layanan))
                ->orderBy('k.nama')
                ->get(['k.id', 'k.nip', 'k.nama']),
            'pratinjau' => $pratinjau,
            'semuaLayak' => $pratinjau !== null && collect($pratinjau['karyawan'])->every(static fn (array $baris): bool => $baris['layak']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nama_template' => ['required', 'string', 'max:100'],
            'jenis' => ['required', Rule::in([TemplateJadwalService::JENIS_PRIBADI, TemplateJadwalService::JENIS_BERSAMA])],
            'id_karyawan' => [
                'nullable',
                'required_if:jenis,'.TemplateJadwalService::JENIS_PRIBADI,
                'prohibited_if:jenis,'.TemplateJadwalService::JENIS_BERSAMA,
                'integer',
                'exists:tbl_karyawan,id',
            ],
            'id_klien' => [
                'nullable',
                'required_if:jenis,'.TemplateJadwalService::JENIS_BERSAMA,
                'prohibited_if:jenis,'.TemplateJadwalService::JENIS_PRIBADI,
                'integer',
                'exists:tbl_klien,id',
            ],
            'id_jenis_layanan' => [
                'nullable',
                'prohibited_if:jenis,'.TemplateJadwalService::JENIS_PRIBADI,
                'integer',
                'exists:tbl_jenis_layanan,id',
            ],
            'keterangan' => ['nullable', 'string', 'max:1000'],
            ...$this->aturanPola(),
        ], $this->pesanValidasi());

        $idTemplate = $this->templateJadwalService->buatTemplate($validated, $validated['pola'], (int) $request->user()->id);

        return $this->keDetail($idTemplate)->with('success', "Pola jadwal \"{$validated['nama_template']}\" berhasil dibuat.");
    }

    public function update(Request $request, int $templateJadwal): RedirectResponse
    {
        $this->pastikanTemplateAda($templateJadwal);
        $validated = $request->validate([
            'nama_template' => ['required', 'string', 'max:100'],
            'id_jenis_layanan' => ['nullable', 'integer', 'exists:tbl_jenis_layanan,id'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
            ...$this->aturanPola(),
        ], $this->pesanValidasi());

        $this->templateJadwalService->ubahTemplate(
            $templateJadwal,
            $request->only(['nama_template', 'keterangan', 'id_jenis_layanan', 'jenis', 'id_karyawan', 'id_klien']),
            $validated['pola'],
            (int) $request->user()->id,
        );

        return $this->keDetail($templateJadwal)->with('success', 'Pola berhasil diperbarui. Jadwal yang sudah dibuat dari pola ini tidak berubah.');
    }

    public function deactivate(Request $request, int $templateJadwal): RedirectResponse
    {
        $this->pastikanTemplateAda($templateJadwal);

        $this->templateJadwalService->nonaktifkanTemplate($templateJadwal, (int) $request->user()->id);

        return back()->with('success', 'Pola dinonaktifkan. Jadwal yang sudah dibuat dari pola ini tetap tersimpan.');
    }

    public function apply(Request $request, int $templateJadwal): RedirectResponse
    {
        $this->pastikanTemplateAda($templateJadwal);
        $validated = $request->validate([
            'bulan' => ['required', 'date_format:Y-m'],
            'id_karyawan' => ['nullable', 'array'],
            'id_karyawan.*' => ['integer', 'distinct', 'exists:tbl_karyawan,id'],
            'mode_konflik' => ['required', Rule::in([TemplateJadwalService::KONFLIK_LEWATI, TemplateJadwalService::KONFLIK_TIMPA])],
        ], $this->pesanValidasi());

        $hasil = $this->templateJadwalService->terapkan(
            $templateJadwal,
            $validated['bulan'],
            array_map('intval', $validated['id_karyawan'] ?? []),
            $validated['mode_konflik'],
            (int) $request->user()->id,
        );

        return $this->keDetail($templateJadwal)->with('success', $this->ringkasanPenerapan($hasil));
    }

    /**
     * "Simpan Jadwal sebagai Template": pola diambil dari jadwal aktual satu karyawan pada satu bulan,
     * lalu dibuat menjadi template baru atau mengganti pola template yang sudah ada.
     */
    public function storeFromSchedule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id_karyawan' => ['required', 'integer', 'exists:tbl_karyawan,id'],
            'bulan' => ['required', 'date_format:Y-m'],
            'tindakan' => ['required', Rule::in(['buat_baru', 'perbarui'])],
            'id_template_jadwal' => ['nullable', 'required_if:tindakan,perbarui', 'integer', 'exists:tbl_template_jadwal,id'],
            'nama_template' => ['nullable', 'required_if:tindakan,buat_baru', 'string', 'max:100'],
            'jenis' => ['nullable', 'required_if:tindakan,buat_baru', Rule::in([TemplateJadwalService::JENIS_PRIBADI, TemplateJadwalService::JENIS_BERSAMA])],
            'id_klien' => ['nullable', 'required_if:jenis,'.TemplateJadwalService::JENIS_BERSAMA, 'integer', 'exists:tbl_klien,id'],
            'id_jenis_layanan' => ['nullable', 'integer', 'exists:tbl_jenis_layanan,id'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
        ], $this->pesanValidasi());

        $idUser = (int) $request->user()->id;
        $pola = $this->templateJadwalService->polaDariJadwal((int) $validated['id_karyawan'], $validated['bulan']);

        if ($validated['tindakan'] === 'perbarui') {
            $this->templateJadwalService->gantiPolaTemplate((int) $validated['id_template_jadwal'], $pola, $idUser);

            return $this->keDetail((int) $validated['id_template_jadwal'])
                ->with('success', 'Pola jadwal diperbarui dari jadwal bulan '.$validated['bulan'].'.');
        }

        $pribadi = $validated['jenis'] === TemplateJadwalService::JENIS_PRIBADI;
        $idTemplate = $this->templateJadwalService->buatTemplate([
            'nama_template' => $validated['nama_template'],
            'jenis' => $validated['jenis'],
            'id_karyawan' => $pribadi ? (int) $validated['id_karyawan'] : null,
            'id_klien' => $pribadi ? null : $validated['id_klien'],
            'id_jenis_layanan' => $pribadi ? null : ($validated['id_jenis_layanan'] ?? null),
            'keterangan' => $validated['keterangan'] ?? null,
        ], $pola, $idUser);

        return $this->keDetail($idTemplate)
            ->with('success', "Pola jadwal \"{$validated['nama_template']}\" dibuat dari jadwal bulan {$validated['bulan']}.");
    }

    /**
     * Pola dikirim sebagai pola[hari_ke][status|jam_masuk|jam_pulang]; hari tanpa entri = Belum Diatur.
     *
     * @return array<string, list<mixed>>
     */
    private function aturanPola(): array
    {
        return [
            'pola' => ['required', 'array', 'min:1', 'max:31'],
            'pola.*' => ['array'],
            'pola.*.status' => ['required', Rule::in(['Kerja', 'Libur'])],
            'pola.*.jam_masuk' => ['nullable', 'required_if:pola.*.status,Kerja', 'date_format:H:i'],
            'pola.*.jam_pulang' => ['nullable', 'required_if:pola.*.status,Kerja', 'date_format:H:i', 'different:pola.*.jam_masuk'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pesanValidasi(): array
    {
        return [
            'nama_template.required' => 'Nama pola wajib diisi.',
            'nama_template.required_if' => 'Nama pola wajib diisi untuk pola baru.',
            'nama_template.max' => 'Nama pola maksimal 100 karakter.',
            'jenis.required' => 'Jenis pola wajib dipilih.',
            'jenis.required_if' => 'Jenis pola wajib dipilih untuk pola baru.',
            'jenis.in' => 'Jenis pola harus Pribadi atau Bersama.',
            'id_karyawan.required' => 'Karyawan wajib dipilih.',
            'id_karyawan.required_if' => 'Pola Pribadi wajib memilih karyawannya.',
            'id_karyawan.prohibited_if' => 'Pola Bersama tidak terikat pada satu karyawan.',
            'id_karyawan.exists' => 'Karyawan tidak ditemukan.',
            'id_karyawan.array' => 'Daftar karyawan penerima tidak valid.',
            'id_karyawan.*.distinct' => 'Karyawan penerima tidak boleh dipilih dua kali.',
            'id_karyawan.*.exists' => 'Salah satu karyawan penerima tidak ditemukan.',
            'id_klien.required_if' => 'Pola Bersama wajib memilih klien.',
            'id_klien.prohibited_if' => 'Pola Pribadi tidak terikat pada klien.',
            'id_klien.exists' => 'Klien tidak ditemukan.',
            'id_jenis_layanan.prohibited_if' => 'Jenis layanan hanya berlaku untuk Pola Bersama.',
            'id_jenis_layanan.exists' => 'Jenis layanan tidak ditemukan.',
            'keterangan.max' => 'Keterangan maksimal 1000 karakter.',
            'pola.required' => 'Isi minimal satu tanggal pada pola.',
            'pola.min' => 'Isi minimal satu tanggal pada pola.',
            'pola.max' => 'Pola maksimal 31 hari.',
            'pola.*.status.required' => 'Status setiap hari pada pola wajib diisi.',
            'pola.*.status.in' => 'Status hari pada pola harus Kerja atau Libur.',
            'pola.*.jam_masuk.required_if' => 'Jam masuk wajib diisi untuk hari Kerja.',
            'pola.*.jam_masuk.date_format' => 'Format jam masuk harus JJ:MM.',
            'pola.*.jam_pulang.required_if' => 'Jam pulang wajib diisi untuk hari Kerja.',
            'pola.*.jam_pulang.date_format' => 'Format jam pulang harus JJ:MM.',
            'pola.*.jam_pulang.different' => 'Jam pulang tidak boleh sama dengan jam masuk.',
            'bulan.required' => 'Bulan wajib dipilih.',
            'bulan.date_format' => 'Format bulan tidak valid.',
            'mode_konflik.required' => 'Pilih pertahankan atau ganti untuk jadwal yang sudah ada.',
            'mode_konflik.in' => 'Pilih pertahankan atau ganti untuk jadwal yang sudah ada.',
            'tindakan.required' => 'Pilih buat pola baru atau perbarui pola yang ada.',
            'tindakan.in' => 'Pilih buat pola baru atau perbarui pola yang ada.',
            'id_template_jadwal.required_if' => 'Pilih pola yang akan diperbarui.',
            'id_template_jadwal.exists' => 'Pola jadwal tidak ditemukan.',
        ];
    }

    private function pastikanTemplateAda(int $idTemplate): void
    {
        abort_unless(DB::table('tbl_template_jadwal')->where('id', $idTemplate)->exists(), 404);
    }

    private function keDetail(int $idTemplate): RedirectResponse
    {
        return redirect()->route('operasional.jadwal-kerja.template-jadwal.show', $idTemplate);
    }

    /**
     * Template beserta nama pemilik/klien/layanan; 404 bila tidak ada.
     */
    private function ambilTemplateLengkap(int $idTemplate): object
    {
        $template = $this->queryTemplate()->where('t.id', $idTemplate)->first();
        abort_if($template === null, 404);

        return $template;
    }

    private function queryTemplate(): Builder
    {
        return DB::table('tbl_template_jadwal as t')
            ->leftJoin('tbl_karyawan as k', 'k.id', '=', 't.id_karyawan')
            ->leftJoin('tbl_klien as kl', 'kl.id', '=', 't.id_klien')
            ->leftJoin('tbl_jenis_layanan as jl', 'jl.id', '=', 't.id_jenis_layanan')
            ->select('t.*', 'k.nip', 'k.nama as nama_karyawan', 'kl.nama_perusahaan', 'jl.nama_layanan');
    }

    /**
     * Pola template dikunci dengan hari_ke, berisi status dan jam (JJ:MM).
     *
     * @return array<int, array{status: string, jam_masuk: string|null, jam_pulang: string|null}>
     */
    private function polaTemplate(int $idTemplate): array
    {
        return DB::table('tbl_template_jadwal_detail')
            ->where('id_template_jadwal', $idTemplate)
            ->orderBy('hari_ke')
            ->get()
            ->mapWithKeys(static fn (object $hari): array => [(int) $hari->hari_ke => [
                'status' => $hari->status,
                'jam_masuk' => $hari->jam_masuk === null ? null : substr($hari->jam_masuk, 0, 5),
                'jam_pulang' => $hari->jam_pulang === null ? null : substr($hari->jam_pulang, 0, 5),
            ]])
            ->all();
    }

    /**
     * @return array{daftarKaryawan: Collection<int, object>, daftarKlien: Collection<int, object>, daftarLayanan: Collection<int, object>}
     */
    private function pilihanForm(): array
    {
        return [
            'daftarKaryawan' => DB::table('tbl_karyawan as k')
                ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('tbl_penempatan as p')->whereColumn('p.id_karyawan', 'k.id'))
                ->orderBy('k.nama')
                ->get(['k.id', 'k.nip', 'k.nama']),
            'daftarKlien' => DB::table('tbl_klien')->where('status', 'Aktif')->orderBy('nama_perusahaan')->get(['id', 'kode_klien', 'nama_perusahaan']),
            'daftarLayanan' => DB::table('tbl_jenis_layanan')->orderBy('nama_layanan')->get(['id', 'nama_layanan']),
        ];
    }

    /**
     * @param  array{hari_tidak_tersedia: list<int>, karyawan: list<array{nama: string|null, dibuat: int, ditimpa: int, dilewati_konflik: int, dilewati_di_luar_penempatan: int}>}  $hasil
     */
    private function ringkasanPenerapan(array $hasil): string
    {
        $baris = array_map(
            static fn (array $karyawan): string => "{$karyawan['nama']}: {$karyawan['dibuat']} tanggal baru, {$karyawan['ditimpa']} diganti, "
                ."{$karyawan['dilewati_konflik']} jadwal lama dipertahankan, {$karyawan['dilewati_di_luar_penempatan']} di luar penempatan",
            $hasil['karyawan'],
        );
        $pesan = 'Pola dipakai. '.implode('; ', $baris).'.';

        if ($hasil['hari_tidak_tersedia'] !== []) {
            $pesan .= ' Hari ke-'.implode(', ', $hasil['hari_tidak_tersedia']).' dilewati karena tidak ada pada bulan tujuan.';
        }

        return $pesan;
    }
}

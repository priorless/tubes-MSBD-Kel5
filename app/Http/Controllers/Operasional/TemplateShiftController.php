<?php

namespace App\Http\Controllers\Operasional;

use App\Http\Controllers\Controller;
use App\Services\JadwalKerjaService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TemplateShiftController extends Controller
{
    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array TEMPLATE_STANDAR = [
        ['Shift Pagi', '08:00', '16:00'],
        ['Shift Malam', '20:00', '04:00'],
        ['Shift Siang', '08:00', '20:00'],
        ['Shift Malam 12 Jam', '20:00', '08:00'],
    ];

    public function index(): View
    {
        $this->pastikanSkemaRosterTersedia();

        return view('operasional.jadwal-kerja.template-shift.index', [
            'klien' => DB::table('tbl_klien')->orderBy('nama_perusahaan')->get(['id', 'kode_klien', 'nama_perusahaan']),
            'templateShift' => DB::table('tbl_template_shift as ts')
                ->join('tbl_klien as kl', 'kl.id', '=', 'ts.id_klien')
                ->orderBy('kl.nama_perusahaan')
                ->orderBy('ts.nama_shift')
                ->get([
                    'ts.id',
                    'ts.id_klien',
                    'ts.nama_shift',
                    'ts.jam_masuk',
                    'ts.jam_pulang',
                    'ts.durasi_menit',
                    'ts.status',
                    'kl.kode_klien',
                    'kl.nama_perusahaan',
                ]),
        ]);
    }

    public function store(Request $request, JadwalKerjaService $jadwalKerjaService): RedirectResponse
    {
        $this->pastikanSkemaRosterTersedia();
        $validated = $request->validate([
            'id_klien' => ['required', 'integer', 'exists:tbl_klien,id'],
            'nama_shift' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tbl_template_shift', 'nama_shift')
                    ->where('id_klien', $request->input('id_klien')),
            ],
            'jam_masuk' => ['required', 'date_format:H:i'],
            'jam_pulang' => ['required', 'date_format:H:i'],
        ], $this->validationMessages());

        $durasiMenit = $jadwalKerjaService->hitungDurasiMenit(
            $validated['jam_masuk'],
            $validated['jam_pulang'],
        );

        try {
            DB::table('tbl_template_shift')->insert([
                'id_klien' => $validated['id_klien'],
                'nama_shift' => $validated['nama_shift'],
                'jam_masuk' => $validated['jam_masuk'],
                'jam_pulang' => $validated['jam_pulang'],
                'durasi_menit' => $durasiMenit,
                'status' => 'Aktif',
                'dibuat_oleh' => $request->user()->id,
                'diubah_oleh' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return back()->withInput()->with('error', 'Nama jam tersimpan tersebut sudah digunakan untuk klien ini.');
        }

        return redirect()->route('operasional.jadwal-kerja.template-shift.index')
            ->with('success', 'Jam tersimpan berhasil ditambahkan.');
    }

    public function update(Request $request, int $templateShift, JadwalKerjaService $jadwalKerjaService): RedirectResponse
    {
        $this->pastikanSkemaRosterTersedia();
        $template = DB::table('tbl_template_shift')->where('id', $templateShift)->firstOrFail();
        $validated = $request->validate([
            'nama_shift' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tbl_template_shift', 'nama_shift')
                    ->where('id_klien', $template->id_klien)
                    ->ignore($template->id),
            ],
            'jam_masuk' => ['required', 'date_format:H:i'],
            'jam_pulang' => ['required', 'date_format:H:i'],
        ], $this->validationMessages());

        $durasiMenit = $jadwalKerjaService->hitungDurasiMenit(
            $validated['jam_masuk'],
            $validated['jam_pulang'],
        );

        try {
            DB::table('tbl_template_shift')->where('id', $template->id)->update([
                'nama_shift' => $validated['nama_shift'],
                'jam_masuk' => $validated['jam_masuk'],
                'jam_pulang' => $validated['jam_pulang'],
                'durasi_menit' => $durasiMenit,
                'diubah_oleh' => $request->user()->id,
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return back()->withInput()->with('error', 'Nama jam tersimpan tersebut sudah digunakan untuk klien ini.');
        }

        return redirect()->route('operasional.jadwal-kerja.template-shift.index')
            ->with('success', 'Jam tersimpan berhasil diperbarui.');
    }

    /**
     * Membuat template awal (Shift Pagi, Malam, Siang, Malam 12 Jam) untuk satu klien.
     * Nama yang sudah ada pada klien tersebut dilewati sehingga aman diulang.
     */
    public function storeStandar(Request $request, JadwalKerjaService $jadwalKerjaService): RedirectResponse
    {
        $this->pastikanSkemaRosterTersedia();
        $validated = $request->validate([
            'id_klien' => ['required', 'integer', 'exists:tbl_klien,id'],
        ], $this->validationMessages());

        $namaTersedia = DB::table('tbl_template_shift')
            ->where('id_klien', $validated['id_klien'])
            ->pluck('nama_shift')
            ->all();
        $jumlahDibuat = 0;

        foreach (self::TEMPLATE_STANDAR as [$namaShift, $jamMasuk, $jamPulang]) {
            if (in_array($namaShift, $namaTersedia, true)) {
                continue;
            }

            DB::table('tbl_template_shift')->insert([
                'id_klien' => $validated['id_klien'],
                'nama_shift' => $namaShift,
                'jam_masuk' => $jamMasuk,
                'jam_pulang' => $jamPulang,
                'durasi_menit' => $jadwalKerjaService->hitungDurasiMenit($jamMasuk, $jamPulang),
                'status' => 'Aktif',
                'dibuat_oleh' => $request->user()->id,
                'diubah_oleh' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $jumlahDibuat++;
        }

        return redirect()->route('operasional.jadwal-kerja.template-shift.index')
            ->with('success', $jumlahDibuat === 0
                ? 'Semua jam standar sudah tersedia untuk klien ini.'
                : "{$jumlahDibuat} jam standar berhasil ditambahkan.");
    }

    public function deactivate(Request $request, int $templateShift): RedirectResponse
    {
        $this->pastikanSkemaRosterTersedia();
        $template = DB::table('tbl_template_shift')->where('id', $templateShift)->firstOrFail();
        DB::table('tbl_template_shift')->where('id', $template->id)->update([
            'status' => 'Nonaktif',
            'diubah_oleh' => $request->user()->id,
            'updated_at' => now(),
        ]);

        return redirect()->route('operasional.jadwal-kerja.template-shift.index')
            ->with('success', 'Jam tersimpan dinonaktifkan tanpa menghapus riwayatnya.');
    }

    /**
     * @return array<string, string>
     */
    private function validationMessages(): array
    {
        return [
            'id_klien.required' => 'Klien wajib dipilih.',
            'id_klien.exists' => 'Klien yang dipilih tidak tersedia.',
            'nama_shift.required' => 'Nama wajib diisi.',
            'nama_shift.max' => 'Nama maksimal 100 karakter.',
            'nama_shift.unique' => 'Nama jam tersimpan tersebut sudah digunakan untuk klien ini.',
            'jam_masuk.required' => 'Jam masuk wajib diisi.',
            'jam_masuk.date_format' => 'Format jam masuk tidak valid.',
            'jam_pulang.required' => 'Jam pulang wajib diisi.',
            'jam_pulang.date_format' => 'Format jam pulang tidak valid.',
        ];
    }

    private function pastikanSkemaRosterTersedia(): void
    {
        abort_unless(
            Schema::hasTable('tbl_template_shift')
                && Schema::hasTable('tbl_jadwal_kerja')
                && Schema::hasTable('tbl_jadwal_kerja_riwayat'),
            503,
            'Fitur Jam Tersimpan belum tersedia sebelum skema roster diterapkan.',
        );
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23505'
            || ($sqlState === '23000' && $driverCode === 1062)
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }
}

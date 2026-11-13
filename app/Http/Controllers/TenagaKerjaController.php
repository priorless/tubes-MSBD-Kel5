<?php

namespace App\Http\Controllers;

use App\Services\TenagaKerjaService;
use App\Support\FormatKolom;
use App\Support\KaryawanAktif;
use App\Support\KolomTidakDitemukan;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Modul Tenaga Kerja — Employee Self-Service (Orang 4).
 * Semua data selalu milik karyawan yang sedang aktif (KaryawanAktif::id()).
 * Tidak ada parameter id_karyawan dari URL/form, sehingga data karyawan lain
 * tidak bisa diakses dengan mengubah URL.
 */
class TenagaKerjaController extends Controller
{
    public function __construct(private TenagaKerjaService $db)
    {
    }

    /* =====================================================================
     * Absensi
     * ===================================================================== */

    public function absensi()
    {
        $id = KaryawanAktif::id();
        $data = ['hariIni' => null, 'waktuServer' => now()->toDateTimeString(), 'status' => 'belum'];

        try {
            $data['hariIni'] = $this->db->absensiHariIni($id);
            $data['waktuServer'] = $this->db->waktuServer();
            $data['status'] = $this->statusAbsensi($data['hariIni']);
        } catch (QueryException $e) {
            session()->now('gagal', TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan $e) {
            session()->now('gagal', $e->getMessage());
        }

        return view('tenaga-kerja.absensi', $data + $this->dataUmum() + $this->kolomAbsensi());
    }

    public function checkIn(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $this->db->checkIn(KaryawanAktif::id());
        } catch (QueryException $e) {
            // SQLSTATE 45000 dari trigger (mis. absensi ganda) muncul di sini
            return $this->balasAbsensi($request, false, TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan $e) {
            return $this->balasAbsensi($request, false, $e->getMessage());
        }

        return $this->balasAbsensi($request, true, 'Check-in berhasil. Selamat bekerja!');
    }

    public function checkOut(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $jumlah = $this->db->checkOut(KaryawanAktif::id());
        } catch (QueryException $e) {
            return $this->balasAbsensi($request, false, TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan $e) {
            return $this->balasAbsensi($request, false, $e->getMessage());
        }

        if ($jumlah === 0) {
            return $this->balasAbsensi($request, false,
                'Tidak ada data check-in hari ini yang dapat di-check-out. Pastikan Anda sudah check-in dan belum check-out.');
        }

        return $this->balasAbsensi($request, true, 'Check-out berhasil. Terima kasih atas kerja keras Anda hari ini!');
    }

    /** Balasan JSON (untuk tombol AJAX) atau redirect + notifikasi (tanpa JavaScript). */
    private function balasAbsensi(Request $request, bool $ok, string $pesan): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            $hariIni = null;
            try {
                $hariIni = $this->db->absensiHariIni(KaryawanAktif::id());
            } catch (QueryException|KolomTidakDitemukan) {
                // status tidak bisa dimuat ulang; pesan utama tetap dikirim
            }
            $kolom = $this->kolomAbsensi();

            return response()->json([
                'ok'         => $ok,
                'pesan'      => $pesan,
                'status'     => $this->statusAbsensi($hariIni),
                'jam_masuk'  => $hariIni && $kolom['kolomMasuk'] ? FormatKolom::jam($hariIni->{$kolom['kolomMasuk']}) : null,
                'jam_keluar' => $hariIni && $kolom['kolomKeluar'] ? FormatKolom::jam($hariIni->{$kolom['kolomKeluar']}) : null,
                'masuk_iso'  => $hariIni && $kolom['kolomMasuk'] ? $this->isoHariIni($hariIni->{$kolom['kolomMasuk']}) : null,
            ], $ok ? 200 : 422);
        }

        return redirect()->route('tenaga-kerja.absensi')->with($ok ? 'sukses' : 'gagal', $pesan);
    }

    /** belum | masuk | selesai — hanya membaca isi baris dari database. */
    private function statusAbsensi(?object $hariIni): string
    {
        if (! $hariIni) {
            return 'belum';
        }
        $kolomKeluar = $this->kolomAbsensi()['kolomKeluar'];

        return $kolomKeluar && filled($hariIni->{$kolomKeluar} ?? null) ? 'selesai' : 'masuk';
    }

    private function kolomAbsensi(): array
    {
        try {
            $tabel = $this->db->tabel('absensi');

            return [
                'kolomMasuk'  => $this->db->kolom($tabel, 'jam_masuk'),
                'kolomKeluar' => $this->db->kolom($tabel, 'jam_keluar'),
                'kolomStatus' => $this->db->kolom($tabel, 'status'),
            ];
        } catch (QueryException) {
            return ['kolomMasuk' => null, 'kolomKeluar' => null, 'kolomStatus' => null];
        }
    }

    /** Nilai jam (TIME atau DATETIME) → ISO 8601 hari ini, untuk penghitung durasi di browser. */
    private function isoHariIni(mixed $nilai): ?string
    {
        if (blank($nilai)) {
            return null;
        }
        $teks = (string) $nilai;

        return strlen($teks) <= 8 ? now()->toDateString().'T'.$teks : str_replace(' ', 'T', $teks);
    }

    /* =====================================================================
     * Riwayat absensi
     * ===================================================================== */

    public function riwayat(Request $request)
    {
        $valid = $request->validate([
            'dari'   => 'nullable|date',
            'sampai' => 'nullable|date|after_or_equal:dari',
        ], [
            'dari.date'             => 'Tanggal awal tidak valid.',
            'sampai.date'           => 'Tanggal akhir tidak valid.',
            'sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ]);
        $dari = $valid['dari'] ?? null;
        $sampai = $valid['sampai'] ?? null;
        $id = KaryawanAktif::id();

        $riwayat = null;
        $ringkasan = collect();
        $kolom = [];
        $kolomStatus = null;

        try {
            $view = $this->db->tabel('rekap_absensi');
            $riwayat = $this->db->riwayatAbsensi($id, $dari, $sampai, (int) config('tenaga_kerja.per_halaman_riwayat', 15));
            $ringkasan = $this->db->ringkasanStatus($id, $dari, $sampai);
            $kolom = $this->kolomTampil($view);
            $kolomStatus = $this->db->kolom($view, 'status');
        } catch (QueryException $e) {
            session()->now('gagal', TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan $e) {
            session()->now('gagal', $e->getMessage());
        }

        return view('tenaga-kerja.riwayat', compact('riwayat', 'ringkasan', 'kolom', 'kolomStatus', 'dari', 'sampai')
            + $this->dataUmum());
    }

    /** Unduh riwayat absensi milik sendiri sebagai CSV (bisa dibuka di Excel). */
    public function riwayatCsv(Request $request): StreamedResponse|RedirectResponse
    {
        $valid = $request->validate(['dari' => 'nullable|date', 'sampai' => 'nullable|date|after_or_equal:dari']);
        $id = KaryawanAktif::id();

        try {
            $baris = $this->db->semuaRiwayatAbsensi($id, $valid['dari'] ?? null, $valid['sampai'] ?? null);
            $kolom = $this->kolomTampil($this->db->tabel('rekap_absensi'));
        } catch (QueryException $e) {
            return back()->with('gagal', TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan $e) {
            return back()->with('gagal', $e->getMessage());
        }

        $namaFile = 'riwayat-absensi-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($baris, $kolom) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            fputcsv($out, array_map([FormatKolom::class, 'label'], $kolom), ';');
            foreach ($baris as $b) {
                fputcsv($out, array_map(fn ($k) => $b->{$k} ?? '', $kolom), ';');
            }
            fclose($out);
        }, $namaFile, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* =====================================================================
     * Slip gaji
     * ===================================================================== */

    public function slipGaji(Request $request)
    {
        $id = KaryawanAktif::id();
        $daftar = collect();
        $kolom = ['bulan' => null, 'tahun' => null, 'periode' => null, 'kunci' => null, 'total' => null];

        try {
            $view = $this->db->tabel('slip_gaji');
            $daftar = $this->db->slipGaji($id);
            $kolom = [
                'bulan'   => $this->db->kolom($view, 'periode_bulan'),
                'tahun'   => $this->db->kolom($view, 'periode_tahun'),
                'periode' => $this->db->kolom($view, 'periode'),
                'kunci'   => $this->db->kolom($view, 'id_payroll'),
                'total'   => $this->db->kolom($view, 'total_gaji'),
            ];
        } catch (QueryException $e) {
            session()->now('gagal', TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan $e) {
            session()->now('gagal', $e->getMessage());
        }

        // Kunci & label setiap periode (hanya untuk navigasi tampilan)
        $periode = $daftar->map(fn ($s, $i) => [
            'kunci' => $this->kunciSlip($s, $kolom, $i),
            'label' => $this->labelPeriode($s, $kolom),
        ])->values();

        $dipilih = $request->query('periode');
        $indeks = $dipilih !== null ? $periode->search(fn ($p) => (string) $p['kunci'] === (string) $dipilih) : 0;
        $slip = $indeks === false ? null : $daftar->values()->get($indeks);

        $rincian = $slip ? $this->rincianSlip($slip, $kolom) : null;

        return view('tenaga-kerja.slip-gaji', [
            'periode'      => $periode,
            'slip'         => $slip,
            'rincian'      => $rincian,
            'kunciDipilih' => $slip ? $periode[$indeks]['kunci'] : null,
            'labelPeriode' => $slip ? $periode[$indeks]['label'] : null,
        ] + $this->dataUmum());
    }

    private function kunciSlip(object $s, array $kolom, int $i): string
    {
        if ($kolom['kunci']) {
            return (string) $s->{$kolom['kunci']};
        }
        if ($kolom['tahun'] && $kolom['bulan']) {
            return $s->{$kolom['tahun']}.'-'.str_pad((string) $s->{$kolom['bulan']}, 2, '0', STR_PAD_LEFT);
        }

        return $kolom['periode'] ? (string) $s->{$kolom['periode']} : (string) $i;
    }

    private function labelPeriode(object $s, array $kolom): string
    {
        if ($kolom['bulan'] && $kolom['tahun']) {
            return FormatKolom::namaBulan($s->{$kolom['bulan']}).' '.$s->{$kolom['tahun']};
        }
        if ($kolom['periode']) {
            return FormatKolom::nilai($kolom['periode'], $s->{$kolom['periode']});
        }

        return 'Slip Gaji';
    }

    /**
     * Kelompokkan kolom slip untuk TAMPILAN: penghasilan, potongan, total, dan info lain.
     * Nilai diambil apa adanya dari v_slip_gaji_detail — tidak ada penjumlahan di PHP.
     */
    private function rincianSlip(object $slip, array $kolom): array
    {
        $abaikan = array_merge(
            config('tenaga_kerja.kolom_tersembunyi'),
            array_filter([$kolom['bulan'], $kolom['tahun'], $kolom['periode'], $kolom['total']]),
            (array) config('tenaga_kerja.kolom.id_karyawan')
        );

        $hasil = ['penghasilan' => [], 'potongan' => [], 'info' => [], 'total' => null];

        foreach ((array) $slip as $k => $v) {
            if (in_array($k, $abaikan, true) || str_starts_with($k, 'id_')) {
                continue;
            }
            $item = ['label' => FormatKolom::label($k), 'nilai' => FormatKolom::nilai($k, $v), 'kolom' => $k, 'mentah' => $v];

            if (FormatKolom::isUang($k, $v)) {
                $hasil[FormatKolom::isPotongan($k) ? 'potongan' : 'penghasilan'][] = $item;
            } else {
                $hasil['info'][] = $item;
            }
        }

        if ($kolom['total']) {
            $hasil['total'] = [
                'label'  => FormatKolom::label($kolom['total']),
                'nilai'  => FormatKolom::rupiah($slip->{$kolom['total']}),
                'mentah' => (float) $slip->{$kolom['total']},
            ];
        }

        return $hasil;
    }

    /* =====================================================================
     * Profil
     * ===================================================================== */

    public function profil()
    {
        $kelompok = ['identitas' => [], 'pekerjaan' => [], 'kompensasi' => [], 'lainnya' => []];

        $karyawan = $this->dataUmum()['karyawan'];
        if ($karyawan) {
            $abaikan = array_merge(config('tenaga_kerja.kolom_tersembunyi'), ['foto', 'foto_profil']);

            foreach ((array) $karyawan as $k => $v) {
                if (in_array($k, $abaikan, true) || preg_match('/^id_|_id$/', $k)) {
                    continue;
                }
                $grup = match (true) {
                    FormatKolom::isUang($k, $v) || (bool) preg_match('/gaji|tunjangan|rekening|bank/i', $k) => 'kompensasi',
                    (bool) preg_match('/jabatan|posisi|kategori|jenis|status|masuk|bergabung|divisi|layanan|kontrak/i', $k) => 'pekerjaan',
                    (bool) preg_match('/nama|nik|ktp|lahir|kelamin|gender|alamat|hp|telp|email|agama|pendidikan|npwp/i', $k) => 'identitas',
                    default => 'lainnya',
                };
                $kelompok[$grup][] = [
                    'label' => FormatKolom::label($k),
                    'nilai' => FormatKolom::nilai($k, $v),
                    'ikon'  => FormatKolom::ikon($k),
                ];
            }
        }

        return view('tenaga-kerja.profil', compact('kelompok') + $this->dataUmum());
    }

    /* =====================================================================
     * Data umum untuk layout (nama, kategori, inisial)
     * ===================================================================== */

    private ?array $dataUmumCache = null;

    private function dataUmum(): array
    {
        if ($this->dataUmumCache !== null) {
            return $this->dataUmumCache;
        }

        $karyawan = null;
        try {
            $karyawan = $this->db->karyawan(KaryawanAktif::id());
        } catch (QueryException $e) {
            session()->now('gagal', TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan $e) {
            session()->now('gagal', $e->getMessage());
        }

        $tabel = $this->db->tabel('karyawan');
        $ambil = function (string $kunci) use ($karyawan, $tabel) {
            if (! $karyawan) {
                return null;
            }
            try {
                $k = $this->db->kolom($tabel, $kunci);
            } catch (QueryException) {
                return null;
            }

            return $k ? $karyawan->{$k} : null;
        };

        $nama = $ambil('nama') ?: 'Karyawan';

        return $this->dataUmumCache = [
            'karyawan'     => $karyawan,
            'namaKaryawan' => $nama,
            'kategori'     => $ambil('kategori'),
            'jabatan'      => $ambil('jabatan'),
            'inisial'      => collect(explode(' ', trim($nama)))->filter()->take(2)
                ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''),
            'modeUjiCoba'  => KaryawanAktif::modeUjiCoba(),
        ];
    }

    /** Kolom view yang layak ditampilkan (tanpa kolom id & kolom sensitif). */
    private function kolomTampil(string $view): array
    {
        $sembunyi = array_merge(config('tenaga_kerja.kolom_tersembunyi'), (array) config('tenaga_kerja.kolom.id_karyawan'));

        return array_values(array_filter(
            array_keys($this->db->kolomTabel($view)),
            fn ($k) => ! in_array($k, $sembunyi, true) && ! str_starts_with($k, 'id_')
        ));
    }
}

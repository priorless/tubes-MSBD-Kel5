<?php

namespace App\Http\Controllers;

use App\Services\PertukaranShiftService;
use App\Services\TenagaKerjaService;
use App\Support\FormatKolom;
use App\Support\FotoAbsensiTidakValid;
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
    public function __construct(private TenagaKerjaService $db) {}

    /* =====================================================================
     * Absensi
     * ===================================================================== */

    public function absensi(PertukaranShiftService $pertukaranShiftService)
    {
        $id = KaryawanAktif::id();
        $data = ['hariIni' => null, 'waktuServer' => now()->toDateTimeString(), 'status' => 'belum', 'masukIso' => null, 'penugasan' => null];

        try {
            $data['hariIni'] = $this->db->absensiHariIni($id);
            $data['waktuServer'] = $this->db->waktuServer();
            $data['status'] = $this->statusAbsensi($data['hariIni']);
            $data['masukIso'] = $this->isoMasuk($data['hariIni']);
            $data['penugasan'] = $this->db->penugasanAktif($id);
        } catch (QueryException $e) {
            session()->now('gagal', TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan $e) {
            session()->now('gagal', $e->getMessage());
        }

        $data['foto'] = $this->urlFoto($data['hariIni']);
        $data['tukarShift'] = $this->dataTukarShift($pertukaranShiftService, $id);

        return view('tenaga-kerja.absensi', $data + $this->dataUmum() + $this->kolomAbsensi());
    }

    public function checkIn(Request $request): JsonResponse|RedirectResponse
    {
        $foto = $this->fotoDariRequest($request);

        if ($foto === null) {
            return $this->balasAbsensi($request, false, self::pesanFotoWajib());
        }

        try {
            $this->db->checkIn(KaryawanAktif::id(), $foto);
        } catch (QueryException $e) {
            // 1062 = UNIQUE (id_karyawan, tgl) — terjadi jika dua permintaan check-in
            // masuk bersamaan sehingga trigger belum melihat baris pertama.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return $this->balasAbsensi($request, false, 'Anda sudah check-in hari ini.');
            }

            // SQLSTATE 45000 dari trigger (absensi ganda, belum ditempatkan, dsb.)
            return $this->balasAbsensi($request, false, TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan|FotoAbsensiTidakValid $e) {
            return $this->balasAbsensi($request, false, $e->getMessage());
        }

        return $this->balasAbsensi($request, true, $this->pesanSukses('masuk'));
    }

    public function checkOut(Request $request): JsonResponse|RedirectResponse
    {
        $foto = $this->fotoDariRequest($request);

        if ($foto === null) {
            return $this->balasAbsensi($request, false, self::pesanFotoWajib());
        }

        try {
            // Simpan id baris yang akan di-check-out: untuk shift malam, setelah check-out baris
            // kemarin tidak lagi "aktif", padahal respons harus menampilkan hasil baris tersebut.
            $idAbsensi = $this->idBaris($this->db->absensiHariIni(KaryawanAktif::id()));
            $jumlah = $this->db->checkOut(KaryawanAktif::id(), $foto);
        } catch (QueryException $e) {
            return $this->balasAbsensi($request, false, TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan|FotoAbsensiTidakValid $e) {
            return $this->balasAbsensi($request, false, $e->getMessage());
        }

        if ($jumlah === 0) {
            return $this->balasAbsensi($request, false,
                'Tidak ada data check-in hari ini yang dapat di-check-out. Pastikan Anda sudah check-in dan belum check-out.');
        }

        $selesai = null;
        if ($idAbsensi !== null) {
            try {
                $selesai = $this->db->absensiMilik(KaryawanAktif::id(), $idAbsensi);
            } catch (QueryException) {
                $selesai = null;
            }
        }

        return $this->balasAbsensi($request, true, $this->pesanSukses('keluar', $selesai), baris: $selesai);
    }

    /**
     * Data bagian Tukar Shift di halaman Absensi. Kegagalan di sini tidak boleh mengganggu absensi.
     *
     * @return array{tersedia: bool, opsi: list<array<string, mixed>>, riwayat: Collection<int, object>, layanan: PertukaranShiftService}
     */
    private function dataTukarShift(PertukaranShiftService $pertukaranShiftService, int $idKaryawan): array
    {
        $hasil = ['tersedia' => false, 'opsi' => [], 'riwayat' => collect(), 'layanan' => $pertukaranShiftService];

        try {
            if ($pertukaranShiftService->tersedia()) {
                $hasil['tersedia'] = true;
                $hasil['opsi'] = $pertukaranShiftService->opsiPengajuan($idKaryawan);
                $hasil['riwayat'] = $pertukaranShiftService->riwayatKaryawan($idKaryawan);
            }
        } catch (QueryException) {
            $hasil['tersedia'] = false;
        }

        return $hasil;
    }

    /** Primary key baris absensi (kolom sesuai config), atau null. */
    private function idBaris(?object $baris): ?int
    {
        $kolom = $baris ? $this->db->kolom($this->db->tabel('absensi'), 'id_absensi') : null;

        return $kolom && filled($baris->{$kolom} ?? null) ? (int) $baris->{$kolom} : null;
    }

    /**
     * Pesan sukses berdasarkan hasil trigger (keterlambatan check-in).
     *
     * @param  'masuk'|'keluar'  $jenis
     * @param  object|null  $baris  baris hasil absensi; null = baca absensi aktif
     */
    private function pesanSukses(string $jenis, ?object $baris = null): string
    {
        if ($jenis !== 'masuk') {
            return 'Check-out berhasil. Terima kasih atas kerja keras Anda hari ini!';
        }

        $pesan = 'Check-in berhasil. Selamat bekerja!';

        try {
            $baris ??= $this->db->absensiHariIni(KaryawanAktif::id());
            $kolomTerlambat = $this->db->kolom($this->db->tabel('absensi'), 'menit_terlambat');
        } catch (QueryException|KolomTidakDitemukan) {
            return $pesan;
        }

        $menit = $baris && $kolomTerlambat ? (int) ($baris->{$kolomTerlambat} ?? 0) : 0;

        return $menit > 0 ? "Check-in berhasil. Anda tercatat terlambat $menit menit dari jadwal kerja." : $pesan;
    }

    /* =====================================================================
     * Foto absensi
     * Foto diunggah karyawan (galeri/kamera perangkat). Sistem hanya menerima dan menyimpan
     * foto; keterangan tanggal, jam, dan lokasi pada foto adalah tanggung jawab karyawan
     * dan diperiksa HRD. Lokasi resmi absensi = penempatan aktif.
     * ===================================================================== */

    private const string PESAN_FOTO_WAJIB = 'Foto absensi wajib diunggah (JPG, PNG, atau WEBP, maksimal %d MB).';

    private static function pesanFotoWajib(): string
    {
        return sprintf(self::PESAN_FOTO_WAJIB, intdiv((int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360), 1024));
    }

    /** Isi file foto yang valid, atau null. */
    private function fotoDariRequest(Request $request): ?string
    {
        $piksel = (int) config('tenaga_kerja.foto.piksel_maks', 8000);
        $validator = validator($request->only('foto'), [
            'foto' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360),
                "dimensions:min_width=160,min_height=120,max_width=$piksel,max_height=$piksel"],
        ]);

        if ($validator->fails()) {
            return null;
        }

        $isi = $request->file('foto')->get();

        return $isi === false || $isi === '' ? null : $isi;
    }

    /**
     * URL foto check-in/check-out yang tersimpan pada baris absensi.
     *
     * @return array{masuk: string|null, keluar: string|null}
     */
    private function urlFoto(?object $baris): array
    {
        $hasil = ['masuk' => null, 'keluar' => null];
        $id = $this->idBaris($baris);

        if ($id === null) {
            return $hasil;
        }

        try {
            $tabel = $this->db->tabel('absensi');
            foreach (array_keys($hasil) as $jenis) {
                $kolom = $this->db->kolom($tabel, "foto_$jenis");
                if ($kolom && filled($baris->{$kolom} ?? null)) {
                    $hasil[$jenis] = route('absensi.foto', ['absensi' => $id, 'jenis' => $jenis]);
                }
            }
        } catch (QueryException) {
            return $hasil;
        }

        return $hasil;
    }

    /** Balasan JSON (untuk tombol AJAX) atau redirect + notifikasi (tanpa JavaScript). */
    private function balasAbsensi(Request $request, bool $ok, string $pesan, ?object $baris = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            $hariIni = $baris;
            try {
                $hariIni ??= $this->db->absensiHariIni(KaryawanAktif::id());
            } catch (QueryException|KolomTidakDitemukan) {
                // status tidak bisa dimuat ulang; pesan utama tetap dikirim
            }
            $kolom = $this->kolomAbsensi();

            return response()->json([
                'ok' => $ok,
                'pesan' => $pesan,
                'status' => $this->statusAbsensi($hariIni),
                'jam_masuk' => $hariIni && $kolom['kolomMasuk'] ? FormatKolom::jam($hariIni->{$kolom['kolomMasuk']}) : null,
                'jam_keluar' => $hariIni && $kolom['kolomKeluar'] ? FormatKolom::jam($hariIni->{$kolom['kolomKeluar']}) : null,
                'masuk_iso' => $this->isoMasuk($hariIni),
                'status_kehadiran' => $hariIni && $kolom['kolomStatus'] ? ($hariIni->{$kolom['kolomStatus']} ?? null) : null,
                'foto' => $this->urlFoto($hariIni),
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
                'kolomMasuk' => $this->db->kolom($tabel, 'jam_masuk'),
                'kolomKeluar' => $this->db->kolom($tabel, 'jam_keluar'),
                'kolomStatus' => $this->db->kolom($tabel, 'status'),
                'kolomTanggal' => $this->db->kolom($tabel, 'tanggal'),
            ];
        } catch (QueryException) {
            return ['kolomMasuk' => null, 'kolomKeluar' => null, 'kolomStatus' => null, 'kolomTanggal' => null];
        }
    }

    /**
     * Waktu check-in → ISO 8601, untuk penghitung durasi di browser.
     * Tanggal diambil dari baris absensi itu sendiri (diisi CURDATE() database),
     * BUKAN dari jam PHP, karena zona waktu aplikasi (UTC) berbeda dengan database.
     */
    private function isoMasuk(?object $baris): ?string
    {
        $k = $this->kolomAbsensi();
        if (! $baris || ! $k['kolomMasuk'] || blank($baris->{$k['kolomMasuk']} ?? null)) {
            return null;
        }
        $masuk = (string) $baris->{$k['kolomMasuk']};

        if (strlen($masuk) > 8) {
            return str_replace(' ', 'T', $masuk);   // kolom DATETIME
        }

        $tanggal = $k['kolomTanggal'] ? ($baris->{$k['kolomTanggal']} ?? null) : null;

        return blank($tanggal) ? null : substr((string) $tanggal, 0, 10).'T'.$masuk;
    }

    /* =====================================================================
     * Riwayat absensi
     * ===================================================================== */

    /** Filter riwayat: tahun & bulan (sesuai kolom v_rekap_absensi_pribadi). */
    private function validasiPeriode(Request $request): array
    {
        $valid = $request->validate([
            'tahun' => 'nullable|integer|between:2000,2100',
            'bulan' => 'nullable|integer|between:1,12',
        ], [
            'tahun.integer' => 'Tahun tidak valid.',
            'tahun.between' => 'Tahun harus antara 2000 dan 2100.',
            'bulan.integer' => 'Bulan tidak valid.',
            'bulan.between' => 'Bulan harus antara 1 dan 12.',
        ]);

        return [
            isset($valid['tahun']) ? (int) $valid['tahun'] : null,
            isset($valid['bulan']) ? (int) $valid['bulan'] : null,
        ];
    }

    public function riwayat(Request $request)
    {
        [$tahun, $bulan] = $this->validasiPeriode($request);
        $id = KaryawanAktif::id();

        $riwayat = null;
        $rekap = [];
        $kolom = [];
        $kolomStatus = null;
        $harian = collect();
        $periodeHarian = null;

        try {
            $view = $this->db->tabel('rekap_absensi');
            $riwayat = $this->db->riwayatAbsensi($id, $tahun, $bulan, (int) config('tenaga_kerja.per_halaman_riwayat', 15));
            $rekap = $this->db->ringkasanRekap($id, $tahun, $bulan);
            $kolom = $this->kolomTampil($view);
            $kolomStatus = $this->db->kolom($view, 'status');

            // Detail harian: bulan yang difilter, atau bulan berjalan menurut server database.
            $sekarang = $this->db->waktuServer();
            $periodeHarian = [
                'tahun' => $tahun ?? (int) substr($sekarang, 0, 4),
                'bulan' => $bulan ?? (int) substr($sekarang, 5, 2),
            ];
            $harian = $this->db->absensiHarian($id, $periodeHarian['tahun'], $periodeHarian['bulan']);
        } catch (QueryException $e) {
            session()->now('gagal', TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan $e) {
            session()->now('gagal', $e->getMessage());
        }

        return view('tenaga-kerja.riwayat', compact('riwayat', 'rekap', 'kolom', 'kolomStatus', 'tahun', 'bulan', 'harian', 'periodeHarian')
            + $this->dataUmum());
    }

    /** Unduh riwayat absensi milik sendiri sebagai CSV (bisa dibuka di Excel). */
    public function riwayatCsv(Request $request): StreamedResponse|RedirectResponse
    {
        [$tahun, $bulan] = $this->validasiPeriode($request);
        $id = KaryawanAktif::id();

        try {
            $baris = $this->db->semuaRiwayatAbsensi($id, $tahun, $bulan);
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
                'bulan' => $this->db->kolom($view, 'periode_bulan'),
                'tahun' => $this->db->kolom($view, 'periode_tahun'),
                'periode' => $this->db->kolom($view, 'periode'),
                'kunci' => $this->db->kolom($view, 'id_payroll'),
                'total' => $this->db->kolom($view, 'total_gaji'),
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
            'periode' => $periode,
            'slip' => $slip,
            'rincian' => $rincian,
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
                'label' => FormatKolom::label($kolom['total']),
                'nilai' => FormatKolom::rupiah($slip->{$kolom['total']}),
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
                    'ikon' => FormatKolom::ikon($k),
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

        $layanan = null;
        if ($karyawan) {
            try {
                $layanan = $this->db->namaLayanan(KaryawanAktif::id());
            } catch (QueryException) {
                $layanan = null;
            }
        }

        return $this->dataUmumCache = [
            'karyawan' => $karyawan,
            'namaKaryawan' => $nama,
            'kategori' => $ambil('kategori'),
            'jabatan' => $ambil('jabatan') ?: $layanan,
            'layanan' => $layanan,
            'inisial' => collect(explode(' ', trim($nama)))->filter()->take(2)
                ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''),
            'modeUjiCoba' => KaryawanAktif::modeUjiCoba(),
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

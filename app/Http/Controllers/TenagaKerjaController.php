<?php

namespace App\Http\Controllers;

use App\Services\FotoAbsensiService;
use App\Services\JadwalAbsensiService;
use App\Services\KoreksiCheckOutService;
use App\Services\LaporanAktivitasService;
use App\Services\PertukaranShiftService;
use App\Services\TenagaKerjaService;
use App\Support\AbsensiDitolak;
use App\Support\FormatKolom;
use App\Support\FotoAbsensiTidakValid;
use App\Support\JamServer;
use App\Support\KaryawanAktif;
use App\Support\KolomTidakDitemukan;
use App\Support\LabelBpjs;
use App\Support\ShiftAbsensi;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

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

    public function absensi(JadwalAbsensiService $jadwal, LaporanAktivitasService $laporan, JamServer $jam)
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
        $data['monitoring'] = $this->dataMonitoring($jadwal, $laporan, $jam, $id, $data['hariIni']);

        return view('tenaga-kerja.absensi', $data + $this->dataUmum() + $this->kolomAbsensi());
    }

    /**
     * Data shift & monitoring untuk halaman Absensi. Kegagalan di sini tidak boleh mengganggu absensi.
     * Laporan monitoring (Berkala maupun Perjalanan) dapat dikirim kapan saja selama sesi berjalan.
     *
     * @return array{jenis: string|null, tersedia: bool, shift: ShiftAbsensi|null, statusJadwal: string|null,
     *     pesanJadwal: string|null, jendela: string|null, laporan: Collection<int, object>,
     *     p2h: array{p2h: object, item: Collection<string, object>}|null, muatUlangPada: string|null}
     */
    private function dataMonitoring(JadwalAbsensiService $jadwal, LaporanAktivitasService $laporan, JamServer $jam, int $idKaryawan, ?object $hariIni): array
    {
        $hasil = ['jenis' => null, 'tersedia' => false, 'shift' => null, 'statusJadwal' => null, 'pesanJadwal' => null, 'jendela' => null,
            'laporan' => collect(), 'p2h' => null, 'muatUlangPada' => null];

        try {
            $sekarang = $jam->sekarang();
            $hasil['jenis'] = LaporanAktivitasService::jenisMonitoring($this->db->namaLayanan($idKaryawan));
            $hasil['tersedia'] = $laporan->tersedia();
            $idAbsensi = $this->idBaris($hariIni);

            if ($idAbsensi !== null && filled($hariIni->jam_masuk ?? null)) {
                $hasil['shift'] = $jadwal->shiftAbsensi($hariIni);
                $hasil['statusJadwal'] = $hasil['shift'] ? JadwalAbsensiService::STATUS_KERJA : null;
            } else {
                try {
                    $hasil['shift'] = $jadwal->shiftUntukCheckIn($idKaryawan, $sekarang);
                    $hasil['statusJadwal'] = JadwalAbsensiService::STATUS_KERJA;
                    $hasil['jendela'] = 'dibuka';
                } catch (AbsensiDitolak $e) {
                    ['status' => $hasil['statusJadwal'], 'shift' => $hasil['shift']] = $jadwal->jadwalPada($idKaryawan, $sekarang->toDateString());
                    $hasil['pesanJadwal'] = $e->getMessage();
                    $hasil['jendela'] = $hasil['shift'] === null ? null : ($sekarang->lt($hasil['shift']->bukaCheckIn()) ? 'belum' : 'berakhir');
                    if ($hasil['jendela'] === 'belum') {
                        $hasil['muatUlangPada'] = $hasil['shift']->bukaCheckIn()->format('Y-m-d\TH:i:s');
                    }
                }
            }

            if ($hasil['tersedia'] && $idAbsensi !== null && $hasil['jenis'] !== null) {
                $hasil['laporan'] = $laporan->laporanAbsensi($idKaryawan, $idAbsensi);
            }

            if ($hasil['tersedia'] && $idAbsensi !== null && $hasil['jenis'] === LaporanAktivitasService::JENIS_PERJALANAN) {
                $hasil['p2h'] = $laporan->p2hAbsensi($idKaryawan, $idAbsensi);
            }
        } catch (QueryException) {
            $hasil['tersedia'] = false;
        }

        return $hasil;
    }

    /**
     * Check-in hanya untuk shift yang berlaku bagi karyawan (miliknya sendiri atau backup shift yang ditugaskan
     * kepadanya; bukan Libur / Digantikan / tanpa jadwal), mulai tepat 30 menit sebelum shift dimulai sampai
     * shift berakhir — diperiksa dengan waktu server. Absensi dicatat pada penempatan shift tersebut.
     * Driver wajib melengkapi P2H (9 komponen) dan foto kendaraan; semuanya tersimpan dalam satu transaksi.
     */
    public function checkIn(Request $request, JadwalAbsensiService $jadwal, LaporanAktivitasService $laporan, FotoAbsensiService $fotoService, JamServer $jam): JsonResponse|RedirectResponse
    {
        $foto = $this->fotoDariRequest($request);

        if ($foto === null) {
            return $this->balasAbsensi($request, false, self::pesanFotoWajib());
        }

        $idKaryawan = KaryawanAktif::id();
        $pathTambahan = [];

        try {
            $shift = $jadwal->shiftUntukCheckIn($idKaryawan, $jam->sekarang());
            $lanjutan = null;

            if (LaporanAktivitasService::jenisMonitoring($this->db->namaLayanan($idKaryawan)) === LaporanAktivitasService::JENIS_PERJALANAN) {
                $itemP2h = $this->validasiDokumentasiKendaraan($request, awal: true);
                $jpegKendaraan = $fotoService->siapkan((string) $this->fotoDariRequest($request, 'foto_kendaraan'));
                $lanjutan = function (int $idAbsensi, object $waktu) use ($laporan, $idKaryawan, $itemP2h, $jpegKendaraan, &$pathTambahan): void {
                    $pathTambahan[] = $laporan->simpanP2hAwal($idAbsensi, $idKaryawan, $itemP2h['p2h'], $jpegKendaraan, $waktu);
                };
            }

            $this->db->checkIn($idKaryawan, $foto, $shift->tanggal, $lanjutan, $shift->idPenempatan);
        } catch (QueryException $e) {
            $this->hapusFotoTambahan($fotoService, $pathTambahan);
            // 1062 = UNIQUE (id_karyawan, tgl) — terjadi jika dua permintaan check-in
            // masuk bersamaan sehingga trigger belum melihat baris pertama.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return $this->balasAbsensi($request, false, 'Anda sudah check-in untuk shift ini.');
            }

            // SQLSTATE 45000 dari trigger (absensi ganda, belum ditempatkan, dsb.)
            return $this->balasAbsensi($request, false, TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan|FotoAbsensiTidakValid|AbsensiDitolak $e) {
            $this->hapusFotoTambahan($fotoService, $pathTambahan);

            return $this->balasAbsensi($request, false, $e->getMessage());
        }

        return $this->balasAbsensi($request, true, $this->pesanSukses('masuk'));
    }

    /**
     * Check-out sesi aktif (minimal 30 menit setelah check-in — trigger database). Driver wajib foto kendaraan
     * dan kondisi akhir kendaraan; catatan perjalanan tidak memblokir check-out. Setelah check-out monitoring berhenti.
     */
    public function checkOut(Request $request, LaporanAktivitasService $laporan, FotoAbsensiService $fotoService): JsonResponse|RedirectResponse
    {
        $foto = $this->fotoDariRequest($request);

        if ($foto === null) {
            return $this->balasAbsensi($request, false, self::pesanFotoWajib());
        }

        $idKaryawan = KaryawanAktif::id();
        $pathTambahan = [];

        try {
            // Simpan id baris yang akan di-check-out: untuk shift malam, setelah check-out baris
            // kemarin tidak lagi "aktif", padahal respons harus menampilkan hasil baris tersebut.
            $idAbsensi = $this->idBaris($this->db->absensiHariIni($idKaryawan));
            $lanjutan = null;

            if ($idAbsensi !== null
                && LaporanAktivitasService::jenisMonitoring($this->db->namaLayanan($idKaryawan)) === LaporanAktivitasService::JENIS_PERJALANAN) {
                $akhir = $this->validasiDokumentasiKendaraan($request, awal: false);
                $jpegKendaraan = $fotoService->siapkan((string) $this->fotoDariRequest($request, 'foto_kendaraan'));
                $lanjutan = function (int $idBaris, object $waktu) use ($laporan, $idKaryawan, $akhir, $jpegKendaraan, &$pathTambahan): void {
                    $pathTambahan[] = $laporan->simpanP2hAkhir($idBaris, $idKaryawan, $akhir['kondisi_akhir'],
                        isset($akhir['catatan_akhir']) ? trim($akhir['catatan_akhir']) : null, $jpegKendaraan, $waktu);
                };
            }

            $jumlah = $this->db->checkOut($idKaryawan, $foto, $lanjutan);
        } catch (QueryException $e) {
            $this->hapusFotoTambahan($fotoService, $pathTambahan);

            return $this->balasAbsensi($request, false, TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan|FotoAbsensiTidakValid|AbsensiDitolak $e) {
            $this->hapusFotoTambahan($fotoService, $pathTambahan);

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

    /* =====================================================================
     * Backup Shift (roker 1 arah) dan Jadwal Saya
     * ===================================================================== */

    /**
     * Halaman Backup Shift: pilih jadwal sendiri -> pilih rekan pengganti yang tersedia, permintaan masuk
     * (sebagai rekan), dan riwayat. Rekan dimuat ulang lewat parameter ?id_jadwal= agar tetap jalan tanpa JavaScript.
     */
    public function tukarShift(Request $request, PertukaranShiftService $pertukaranShiftService): View
    {
        $idKaryawan = (int) KaryawanAktif::id();
        $idJadwal = $request->integer('id_jadwal') ?: null;
        $data = ['tersedia' => false, 'jadwalSaya' => [], 'idJadwalDipilih' => null, 'calonRekan' => [],
            'permintaanMasuk' => collect(), 'riwayat' => null, 'layananTukar' => $pertukaranShiftService];

        try {
            if ($pertukaranShiftService->tersedia()) {
                $data['tersedia'] = true;
                $data['jadwalSaya'] = $pertukaranShiftService->jadwalDapatDiajukan($idKaryawan);
                if ($idJadwal !== null && collect($data['jadwalSaya'])->contains('id', $idJadwal)) {
                    $data['idJadwalDipilih'] = $idJadwal;
                    $data['calonRekan'] = $pertukaranShiftService->calonRekan($idKaryawan, $idJadwal);
                }
                $data['permintaanMasuk'] = $pertukaranShiftService->permintaanMasuk($idKaryawan);
                $data['riwayat'] = $pertukaranShiftService->riwayatKaryawan($idKaryawan);
            }
        } catch (QueryException $e) {
            $data['tersedia'] = false;
            session()->now('gagal', TenagaKerjaService::pesanError($e));
        }

        return view('tenaga-kerja.tukar-shift', $data + $this->dataUmum());
    }

    /** Jadwal Saya: roster ke depan (termasuk libur, backup, digantikan) dan riwayat perubahan jadwal. */
    public function jadwal(JadwalAbsensiService $jadwal): View
    {
        $idKaryawan = (int) KaryawanAktif::id();
        $hariIni = CarbonImmutable::now()->startOfDay();
        $roster = [];
        $perubahan = collect();

        try {
            $roster = $jadwal->rosterKaryawan($idKaryawan, $hariIni->toDateString(), $hariIni->addDays(self::HARI_JADWAL_KE_DEPAN - 1)->toDateString());
            $perubahan = $this->db->perubahanJadwal($idKaryawan, $hariIni->subDays(30)->toDateString());
        } catch (QueryException $e) {
            session()->now('gagal', TenagaKerjaService::pesanError($e));
        }

        return view('tenaga-kerja.jadwal', ['roster' => $roster, 'perubahan' => $perubahan] + $this->dataUmum());
    }

    /** Jumlah hari roster yang ditampilkan di Jadwal Saya. */
    private const int HARI_JADWAL_KE_DEPAN = 30;

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

    /**
     * Aturan validasi file foto (dipakai foto absensi, foto kendaraan, dan foto laporan aktivitas).
     *
     * @return list<string>
     */
    public static function aturanFoto(): array
    {
        $piksel = (int) config('tenaga_kerja.foto.piksel_maks', 8000);

        return ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360),
            "dimensions:min_width=160,min_height=120,max_width=$piksel,max_height=$piksel"];
    }

    /** Isi file foto yang valid, atau null. */
    private function fotoDariRequest(Request $request, string $kolom = 'foto'): ?string
    {
        $validator = validator($request->only($kolom), [$kolom => self::aturanFoto()]);

        if ($validator->fails()) {
            return null;
        }

        $isi = $request->file($kolom)->get();

        return $isi === false || $isi === '' ? null : $isi;
    }

    /**
     * Validasi dokumentasi kendaraan Driver.
     * Check-in: 9 komponen P2H (kondisi wajib, keterangan wajib bila tidak "Baik") + foto kendaraan.
     * Check-out: kondisi akhir kendaraan (catatan wajib bila tidak "Baik") + foto kendaraan setelah digunakan.
     *
     * @return array<string, mixed>
     *
     * @throws AbsensiDitolak
     */
    private function validasiDokumentasiKendaraan(Request $request, bool $awal): array
    {
        $kondisi = Rule::in(LaporanAktivitasService::KONDISI);
        $aturan = ['foto_kendaraan' => self::aturanFoto()];
        $pesan = [
            'foto_kendaraan.required' => $awal ? 'Foto kondisi kendaraan wajib diunggah sebelum check-in.' : 'Foto kondisi kendaraan setelah digunakan wajib diunggah.',
            'foto_kendaraan.*' => 'Foto kendaraan harus berupa foto JPG, PNG, atau WEBP yang valid (maksimal '.intdiv((int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360), 1024).' MB).',
        ];

        if ($awal) {
            $aturan['p2h'] = ['required', 'array'];
            foreach (LaporanAktivitasService::KOMPONEN_P2H as $kunci => $label) {
                $aturan["p2h.$kunci.kondisi"] = ['required', $kondisi];
                $aturan["p2h.$kunci.keterangan"] = ['nullable', 'string', 'max:255', "required_unless:p2h.$kunci.kondisi,Baik"];
                $pesan["p2h.$kunci.kondisi.required"] = "P2H belum lengkap: pilih kondisi \"$label\".";
                $pesan["p2h.$kunci.kondisi.in"] = "Kondisi \"$label\" tidak valid.";
                $pesan["p2h.$kunci.keterangan.required_unless"] = "Isi keterangan untuk \"$label\" karena kondisinya tidak Baik.";
                $pesan["p2h.$kunci.keterangan.max"] = "Keterangan \"$label\" maksimal 255 karakter.";
            }
            $pesan['p2h.required'] = 'Checklist P2H wajib diisi sebelum check-in.';
        } else {
            $aturan['kondisi_akhir'] = ['required', $kondisi];
            $aturan['catatan_akhir'] = ['nullable', 'string', 'max:500', 'required_unless:kondisi_akhir,Baik'];
            $pesan['kondisi_akhir.required'] = 'Pilih kondisi akhir kendaraan sebelum check-out.';
            $pesan['kondisi_akhir.in'] = 'Kondisi akhir kendaraan tidak valid.';
            $pesan['catatan_akhir.required_unless'] = 'Isi catatan kondisi akhir kendaraan karena kondisinya tidak Baik.';
            $pesan['catatan_akhir.max'] = 'Catatan kondisi akhir maksimal 500 karakter.';
        }

        $validator = validator($request->all(), $aturan, $pesan);

        if ($validator->fails()) {
            throw new AbsensiDitolak($validator->errors()->first());
        }

        return $validator->validated();
    }

    /**
     * Hapus foto tambahan yang sudah ditulis bila transaksi absensi gagal.
     *
     * @param  list<string>  $paths
     */
    private function hapusFotoTambahan(FotoAbsensiService $fotoService, array $paths): void
    {
        foreach ($paths as $path) {
            $fotoService->hapus($path);
        }
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

    /** Balasan JSON (untuk tombol AJAX) atau redirect + pesan hasil (tanpa JavaScript). */
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
     * Tanggal diambil dari baris absensi itu sendiri (diisi CURDATE() database), sehingga shift malam
     * yang lewat tengah malam tetap memakai tanggal shift-nya.
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

    public function riwayat(Request $request, JadwalAbsensiService $jadwal, KoreksiCheckOutService $koreksi)
    {
        [$tahun, $bulan] = $this->validasiPeriode($request);
        $id = KaryawanAktif::id();

        $riwayat = null;
        $rekap = [];
        $kolom = [];
        $kolomStatus = null;
        $harian = collect();
        $periodeHarian = null;
        $kehadiran = null;
        $lupaCheckOut = collect();
        $riwayatKoreksi = collect();

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
            $kehadiran = $jadwal->rekapKehadiran($id, $periodeHarian['tahun'], $periodeHarian['bulan'], substr($sekarang, 0, 10));
            $lupaCheckOut = $koreksi->absensiLupaCheckOut($id);
            $riwayatKoreksi = $koreksi->riwayatKaryawan($id);
        } catch (QueryException $e) {
            session()->now('gagal', TenagaKerjaService::pesanError($e));
        } catch (KolomTidakDitemukan $e) {
            session()->now('gagal', $e->getMessage());
        }

        return view('tenaga-kerja.riwayat', compact('riwayat', 'rekap', 'kolom', 'kolomStatus', 'tahun', 'bulan', 'harian',
            'periodeHarian', 'kehadiran', 'lupaCheckOut', 'riwayatKoreksi') + $this->dataUmum());
    }

    /**
     * Unduh rekap absensi bulanan sebagai PDF: kolom eksplisit, ringkasan hari wajib vs kehadiran,
     * nama file rekap-absensi-{NIP}-{YYYY-MM}.pdf, waktu cetak WIB.
     */
    public function riwayatPdf(Request $request, JadwalAbsensiService $jadwal): Response|RedirectResponse
    {
        $valid = $request->validate([
            'tahun' => 'required|integer|between:2000,2100',
            'bulan' => 'required|integer|between:1,12',
        ], [
            'tahun.required' => 'Pilih tahun rekap yang akan diunduh.',
            'bulan.required' => 'Pilih bulan rekap yang akan diunduh.',
            'tahun.between' => 'Tahun harus antara 2000 dan 2100.',
            'bulan.between' => 'Bulan harus antara 1 dan 12.',
        ]);
        $id = (int) KaryawanAktif::id();
        $tahun = (int) $valid['tahun'];
        $bulan = (int) $valid['bulan'];

        try {
            $karyawan = $this->db->karyawan($id);
            $kehadiran = $jadwal->rekapKehadiran($id, $tahun, $bulan, substr($this->db->waktuServer(), 0, 10));
        } catch (QueryException $e) {
            return back()->with('gagal', TenagaKerjaService::pesanError($e));
        }

        $nip = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($karyawan->nip ?? $id));
        $periode = sprintf('%04d-%02d', $tahun, $bulan);

        return Pdf::loadView('tenaga-kerja.riwayat-pdf', [
            'karyawan' => $karyawan,
            'layanan' => $this->dataUmum()['layanan'],
            'kehadiran' => $kehadiran,
            'labelPeriode' => FormatKolom::namaBulan($bulan).' '.$tahun,
            'dicetakPada' => CarbonImmutable::now()->format('d/m/Y H:i').' WIB',
        ])->setPaper('a4', 'landscape')->download("rekap-absensi-{$nip}-{$periode}.pdf");
    }

    /** Karyawan mengajukan jam keluar untuk absensi yang lupa check-out. */
    public function ajukanKoreksiCheckOut(Request $request, KoreksiCheckOutService $koreksi): RedirectResponse
    {
        $valid = $request->validate([
            'id_absensi' => ['required', 'integer', 'min:1'],
            'jam_keluar' => ['required', 'date_format:H:i'],
            'alasan' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'id_absensi.*' => 'Absensi yang dipilih tidak valid.',
            'jam_keluar.required' => 'Jam keluar wajib diisi.',
            'jam_keluar.date_format' => 'Format jam keluar harus JJ:MM.',
            'alasan.required' => 'Alasan wajib diisi.',
            'alasan.min' => 'Alasan minimal :min karakter.',
            'alasan.max' => 'Alasan maksimal :max karakter.',
        ]);

        try {
            $koreksi->ajukan((int) KaryawanAktif::id(), (int) $valid['id_absensi'], $valid['jam_keluar'], trim($valid['alasan']), (int) $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withInput()->with('gagal', $e->validator->errors()->first());
        }

        return back()->with('sukses', 'Pengajuan koreksi check-out terkirim dan menunggu pemeriksaan HRD.');
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
            (array) config('tenaga_kerja.kolom.id_karyawan'),
            // bpjs_nilai bisa berupa persen; ditampilkan sebagai satu baris "Aturan BPJS" di bawah, bukan sebagai rupiah.
            ['bpjs_metode', 'bpjs_nilai']
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

        if (($slip->bpjs_metode ?? null) !== null) {
            $aturan = LabelBpjs::aturan($slip->bpjs_metode, $slip->bpjs_nilai ?? null);
            $hasil['info'][] = ['label' => 'Aturan BPJS', 'nilai' => $aturan, 'kolom' => 'bpjs_metode', 'mentah' => $aturan];
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

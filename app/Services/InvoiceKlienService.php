<?php

namespace App\Services;

use App\Support\DokumenInvoiceDitolak;
use App\Support\Terbilang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/**
 * Data dokumen tagihan klien (surat permohonan, invoice, lampiran) -- hanya MEMBACA.
 *
 * Semua nominal berasal dari tbl_invoice dan snapshot tbl_invoice_detail yang dibuat sp_generate_invoice_klien
 * (sql/Komponen/17) dari payroll tersimpan, sehingga surat, invoice, dan lampiran selalu memakai angka yang sama.
 * Log Tukar Shift hanya informasi transparansi; tidak pernah menambah tagihan.
 */
class InvoiceKlienService
{
    private const array BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    private const array ROMAWI = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /** Teks surat yang boleh diedit Admin Keuangan beserta teks bawaannya. */
    public const array TEKS_BAWAAN = [
        'penerima' => 'Bapak/Ibu Pimpinan',
        'hal' => 'Tagihan Jasa Tenaga Kerja Bulan {periode}',
        'paragraf_pembuka' => 'Bersama ini kami kirimkan 1 (satu) berkas Tagihan Jasa Tenaga Kerja {klien} Bulan {periode}, dengan perincian tagihan sebagai berikut:',
        'paragraf_penutup' => 'Demikian tagihan ini kami sampaikan kepada Bapak/Ibu. Atas kerja samanya kami ucapkan terima kasih.',
        'salam_penutup' => 'Semoga Bapak/Ibu dalam keadaan sehat, sukses, dan bahagia selalu.',
    ];

    public function __construct(private PertukaranShiftService $pertukaranShift) {}

    /**
     * @return array{
     *     invoice: object,
     *     teksSurat: array<string, string>,
     *     catatanManual: list<array{id: int, isi: string, dibuat_oleh: string, dibuat_pada: string}>,
     *     versiDokumen: int,
     *     perubahanTerakhir: array{oleh: string, pada: string}|null,
     *     punyaRincian: bool,
     *     kelompok: Collection<string, Collection<int, object>>,
     *     rincian: Collection<int, object>,
     *     ringkasan: list<array{uraian: string, nominal: float}>,
     *     totalLampiran: array<string, float>,
     *     catatanTukarShift: list<array{tanggal: string, teks: string}>,
     *     terbilang: string,
     *     nomorSurat: string,
     *     tanggalSurat: string,
     *     labelPeriode: string,
     *     rentangPeriode: string,
     *     perusahaan: array<string, mixed>
     * }|null
     */
    public function dokumen(int $idInvoice): ?array
    {
        $invoice = DB::table('tbl_invoice as i')
            ->join('tbl_klien as k', 'k.id', '=', 'i.id_klien')
            ->where('i.id', $idInvoice)
            ->first([
                'i.*',
                'k.kode_klien', 'k.nama_perusahaan', 'k.alamat', 'k.pic_nama', 'k.pic_jabatan', 'k.pic_telp', 'k.pic_email',
            ]);

        if ($invoice === null) {
            return null;
        }

        $rincian = DB::table('tbl_invoice_detail')
            ->where('id_invoice', $idInvoice)
            ->orderBy('jabatan')
            ->orderBy('nama')
            ->get();

        $kelompok = $rincian->groupBy('jabatan');
        $awal = CarbonImmutable::create((int) $invoice->periode_tahun, (int) $invoice->periode_bulan, 1);
        $akhir = $awal->endOfMonth();
        $labelPeriode = self::BULAN[(int) $invoice->periode_bulan].' '.$invoice->periode_tahun;
        $rentangPeriode = $this->tanggal($awal).' s/d '.$this->tanggal($akhir);
        $tanggalSurat = CarbonImmutable::parse($invoice->created_at ?? now());

        $ringkasan = [];
        foreach ($kelompok as $jabatan => $baris) {
            $lembur = (float) $baris->sum('upah_lembur');
            $ringkasan[] = ['uraian' => 'Tagihan '.$jabatan.' ('.$baris->count().' orang)', 'nominal' => (float) $baris->sum('biaya_tenaga_kerja') - $lembur];
            if ($lembur > 0) {
                $ringkasan[] = ['uraian' => 'Lembur '.$jabatan.' periode '.$rentangPeriode, 'nominal' => $lembur];
            }
        }

        $totalLampiran = [];
        foreach (['gaji_pokok', 'tunjangan', 'upah_backup', 'upah_lembur', 'potongan_absen', 'biaya_tenaga_kerja',
            'potongan_pinjaman', 'potongan_bpjs', 'pph21', 'total_penerimaan'] as $kolom) {
            $totalLampiran[$kolom] = (float) $rincian->sum($kolom);
        }

        $tersimpan = $this->dokumenTersimpan($idInvoice);

        return [
            'invoice' => $invoice,
            'teksSurat' => $this->teksSurat($tersimpan, $this->teksBawaan($invoice->nama_perusahaan, $labelPeriode)),
            'catatanManual' => $this->catatanManual($idInvoice),
            'versiDokumen' => (int) ($tersimpan->versi ?? 0),
            'perubahanTerakhir' => $tersimpan !== null && $tersimpan->diubah_pada !== null
                ? ['oleh' => $tersimpan->nama_pengubah ?? 'Pengguna #'.$tersimpan->diubah_oleh, 'pada' => $tersimpan->diubah_pada]
                : null,
            'punyaRincian' => $rincian->isNotEmpty(),
            'kelompok' => $kelompok,
            'rincian' => $rincian,
            'ringkasan' => $ringkasan,
            'totalLampiran' => $totalLampiran,
            'catatanTukarShift' => $this->catatanTukarShift((int) $invoice->id_klien, $awal, $akhir),
            'terbilang' => Terbilang::rupiah($invoice->total_tagihan),
            'nomorSurat' => sprintf('%d/%s/%s/%d', $invoice->id, config('perusahaan.kode_surat'),
                self::ROMAWI[$tanggalSurat->month], $tanggalSurat->year),
            'tanggalSurat' => $this->tanggal($tanggalSurat),
            'labelPeriode' => $labelPeriode,
            'rentangPeriode' => $rentangPeriode,
            'perusahaan' => config('perusahaan'),
        ];
    }

    /**
     * Tukar Shift (barter) yang disetujui Operasional untuk karyawan klien ini pada periode invoice.
     * Tiap pertukaran menghasilkan dua catatan "A menggantikan B" (tanpa biaya tambahan).
     *
     * @return list<array{tanggal: string, teks: string}>
     */
    private function catatanTukarShift(int $idKlien, CarbonImmutable $awal, CarbonImmutable $akhir): array
    {
        if (! Schema::hasTable('tbl_pertukaran_shift') || ! Schema::hasColumn('tbl_pertukaran_shift', 'tanggal_kerja_rekan')) {
            return [];
        }

        $mulai = $awal->toDateString();
        $selesai = $akhir->toDateString();

        $daftar = DB::table('tbl_pertukaran_shift as ps')
            ->join('tbl_jadwal_kerja as jp', 'jp.id', '=', 'ps.id_jadwal_pengaju')
            ->join('tbl_penempatan as pp', 'pp.id', '=', 'jp.id_penempatan')
            ->join('tbl_karyawan as kp', 'kp.id', '=', 'ps.id_karyawan_pengaju')
            ->join('tbl_karyawan as kr', 'kr.id', '=', 'ps.id_karyawan_rekan')
            ->where('ps.status', PertukaranShiftService::STATUS_DISETUJUI)
            ->where('pp.id_klien', $idKlien)
            ->where(fn ($q) => $q->whereBetween('ps.tanggal_kerja', [$mulai, $selesai])
                ->orWhereBetween('ps.tanggal_kerja_rekan', [$mulai, $selesai]))
            ->get(['ps.tanggal_kerja', 'ps.tanggal_kerja_rekan', 'ps.snapshot_pengaju', 'ps.snapshot_rekan',
                'kp.nama as nama_pengaju', 'kr.nama as nama_rekan']);

        $catatan = [];
        foreach ($daftar as $item) {
            $tanggalRekan = (string) ($item->tanggal_kerja_rekan ?? $item->tanggal_kerja);
            $tanggalPengaju = (string) $item->tanggal_kerja;

            if ($tanggalRekan >= $mulai && $tanggalRekan <= $selesai) {
                $catatan[] = ['tanggal' => $tanggalRekan, 'teks' => sprintf('%s menggantikan %s (%s)',
                    $item->nama_pengaju, $item->nama_rekan, $this->pertukaranShift->labelShift($item->snapshot_rekan))];
            }
            if ($tanggalPengaju >= $mulai && $tanggalPengaju <= $selesai) {
                $catatan[] = ['tanggal' => $tanggalPengaju, 'teks' => sprintf('%s menggantikan %s (%s)',
                    $item->nama_rekan, $item->nama_pengaju, $this->pertukaranShift->labelShift($item->snapshot_pengaju))];
            }
        }

        usort($catatan, fn (array $a, array $b): int => [$a['tanggal'], $a['teks']] <=> [$b['tanggal'], $b['teks']]);

        return array_map(fn (array $baris): array => [
            'tanggal' => $this->tanggal(CarbonImmutable::parse($baris['tanggal'])),
            'teks' => $baris['teks'],
        ], $catatan);
    }

    /**
     * Simpan mode edit (teks surat & catatan manual) untuk SATU invoice. Nominal tidak pernah disentuh.
     * Mengembalikan false bila tidak ada perubahan. Setiap simpan menaikkan versi dan menulis riwayat audit.
     *
     * @param  array<string, mixed>  $isian  hasil UpdateInvoiceDokumenRequest::validated()
     *
     * @throws DokumenInvoiceDitolak
     */
    public function simpanDokumen(int $idInvoice, array $isian, int $idUser): bool
    {
        return DB::transaction(function () use ($idInvoice, $isian, $idUser): bool {
            $invoice = DB::table('tbl_invoice as i')
                ->join('tbl_klien as k', 'k.id', '=', 'i.id_klien')
                ->where('i.id', $idInvoice)
                ->lockForUpdate()
                ->first(['i.id', 'i.status', 'i.periode_bulan', 'i.periode_tahun', 'k.nama_perusahaan']);

            if ($invoice === null) {
                throw new DokumenInvoiceDitolak('Invoice tidak ditemukan.');
            }
            if ($invoice->status === 'Lunas') {
                throw new DokumenInvoiceDitolak('Invoice yang sudah lunas tidak dapat diedit.');
            }

            $tersimpan = DB::table('tbl_invoice_dokumen')->where('id_invoice', $idInvoice)->lockForUpdate()->first();
            $versi = (int) ($tersimpan->versi ?? 0);
            if ((int) $isian['versi'] !== $versi) {
                throw new DokumenInvoiceDitolak('Dokumen invoice ini sudah diubah oleh pengguna lain. Muat ulang lalu ulangi perubahan Anda.');
            }

            $bawaan = $this->teksBawaan($invoice->nama_perusahaan, self::BULAN[(int) $invoice->periode_bulan].' '.$invoice->periode_tahun);
            $teksBaru = [];
            foreach (array_keys(self::TEKS_BAWAAN) as $kolom) {
                $nilai = trim((string) ($isian[$kolom] ?? ''));
                // Kosong atau sama dengan teks bawaan -> NULL (memakai teks bawaan).
                $teksBaru[$kolom] = $nilai === '' || $nilai === $bawaan[$kolom] ? null : $nilai;
            }

            $catatanLama = DB::table('tbl_invoice_catatan')->where('id_invoice', $idInvoice)->orderBy('id')->lockForUpdate()->get(['id', 'isi']);
            $catatanDikirim = collect($isian['catatan'] ?? [])
                ->map(fn (array $baris): array => ['id' => isset($baris['id']) ? (int) $baris['id'] : null, 'isi' => trim((string) ($baris['isi'] ?? ''))])
                ->filter(fn (array $baris): bool => $baris['isi'] !== '')
                ->values();

            $idLama = $catatanLama->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $idAsing = $catatanDikirim->pluck('id')->filter()->diff($idLama);
            if ($idAsing->isNotEmpty()) {
                throw new DokumenInvoiceDitolak('Catatan yang dikirim bukan milik invoice ini. Muat ulang halaman lalu coba lagi.');
            }

            $sebelum = ['teks' => $this->teksKolom($tersimpan), 'catatan' => $catatanLama->map(fn ($c): array => ['id' => (int) $c->id, 'isi' => $c->isi])->all()];
            if ($sebelum == ['teks' => $teksBaru, 'catatan' => $catatanDikirim->all()]) {
                return false;
            }

            $sekarang = now();
            $idDipertahankan = $catatanDikirim->pluck('id')->filter()->all();
            DB::table('tbl_invoice_catatan')->where('id_invoice', $idInvoice)->whereNotIn('id', $idDipertahankan ?: [0])->delete();

            $isiLama = $catatanLama->pluck('isi', 'id');
            $catatanTersimpan = [];
            foreach ($catatanDikirim as $catatan) {
                if ($catatan['id'] === null) {
                    $catatan['id'] = DB::table('tbl_invoice_catatan')->insertGetId([
                        'id_invoice' => $idInvoice, 'isi' => $catatan['isi'], 'dibuat_oleh' => $idUser, 'dibuat_pada' => $sekarang,
                    ]);
                } elseif ($isiLama[$catatan['id']] !== $catatan['isi']) {
                    DB::table('tbl_invoice_catatan')->where('id', $catatan['id'])->where('id_invoice', $idInvoice)
                        ->update(['isi' => $catatan['isi'], 'diubah_oleh' => $idUser, 'diubah_pada' => $sekarang]);
                }
                $catatanTersimpan[] = $catatan;
            }

            DB::table('tbl_invoice_dokumen')->updateOrInsert(
                ['id_invoice' => $idInvoice],
                [...$teksBaru, 'versi' => $versi + 1, 'diubah_oleh' => $idUser, 'diubah_pada' => $sekarang],
            );

            DB::table('tbl_invoice_dokumen_riwayat')->insert([
                'id_invoice' => $idInvoice,
                'versi' => $versi + 1,
                'data_sebelum' => json_encode($sebelum, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'data_sesudah' => json_encode(['teks' => $teksBaru, 'catatan' => $catatanTersimpan], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'diubah_oleh' => $idUser,
                'waktu' => $sekarang,
            ]);

            return true;
        });
    }

    /**
     * Subjek & isi email penagihan bawaan untuk satu invoice (dapat diedit Admin Keuangan sebelum dikirim).
     * Nominal dibaca dari tbl_invoice, bukan dari form.
     *
     * @param  object  $invoice  baris tbl_invoice + nama_perusahaan, pic_nama
     * @return array{subjek: string, pesan: string}
     */
    public function templateEmail(object $invoice): array
    {
        $periode = self::BULAN[(int) $invoice->periode_bulan].' '.$invoice->periode_tahun;
        $rekening = config('perusahaan.rekening');
        $nilai = fn (?string $isi, string $label): string => filled($isi) ? $isi : '['.$label.' belum tersedia]';
        $sapaan = filled($invoice->pic_nama ?? null) ? 'Bapak/Ibu '.$invoice->pic_nama : 'Bapak/Ibu Pimpinan';

        $pesan = implode("\n", [
            'Yth. '.$sapaan,
            $invoice->nama_perusahaan,
            '',
            'Dengan hormat,',
            '',
            'Bersama email ini kami sampaikan tagihan jasa tenaga kerja PT Satu Jaya Indonesia untuk periode '.$periode
                .' dengan nomor invoice '.$invoice->nomor_invoice.' sebesar Rp '.number_format((float) $invoice->total_tagihan, 0, ',', '.')
                .' ('.Terbilang::rupiah($invoice->total_tagihan).').',
            'Surat permohonan, invoice, dan lampiran rincian tagihan kami lampirkan dalam satu berkas PDF.',
            '',
            'Pembayaran dapat ditransfer ke rekening berikut:',
            'Bank: '.$nilai($rekening['bank'] ?? null, 'Nama bank'),
            'Nama Rekening: '.$nilai($rekening['nama'] ?? null, 'Nama rekening'),
            'Nomor Rekening: '.$nilai($rekening['nomor'] ?? null, 'Nomor rekening'),
            '',
            'Mohon mencantumkan nomor invoice '.$invoice->nomor_invoice.' pada berita transfer. Apabila terdapat pertanyaan mengenai tagihan ini, silakan membalas email ini.',
            '',
            'Atas perhatian dan kerja samanya kami ucapkan terima kasih.',
            '',
            'Hormat kami,',
            'Bagian Keuangan',
            'PT Satu Jaya Indonesia',
        ]);

        return [
            'subjek' => 'Tagihan Invoice Jasa PT Satu Jaya Indonesia - '.$invoice->nama_perusahaan.' - Periode '.$periode,
            'pesan' => $pesan,
        ];
    }

    /**
     * Nomor WhatsApp format internasional Indonesia tanpa "+": 62 + 8–13 digit (digit pertama setelah 62 bukan 0).
     */
    public const string POLA_NOMOR_WHATSAPP = '/^62[1-9][0-9]{7,12}$/';

    /** Masa berlaku tautan publik PDF tagihan yang dibagikan lewat WhatsApp. */
    public const int MASA_BERLAKU_TAUTAN_HARI = 30;

    /**
     * Normalisasi nomor telepon ke format wa.me: hapus karakter nonangka, 08xx -> 628xx, 8xx -> 628xx, 62xx tetap.
     * Mengembalikan null bila kosong atau tidak valid.
     */
    public static function normalisasiNomorWhatsApp(?string $nomor): ?string
    {
        $angka = (string) preg_replace('/\D+/', '', (string) $nomor);

        if (str_starts_with($angka, '08')) {
            $angka = '62'.substr($angka, 1);
        } elseif (str_starts_with($angka, '8')) {
            $angka = '62'.$angka;
        }

        return preg_match(self::POLA_NOMOR_WHATSAPP, $angka) === 1 ? $angka : null;
    }

    /**
     * Tautan wa.me berisi pesan penagihan untuk satu invoice, atau pesan galat bila nomor PIC / rekening resmi
     * belum valid (tautan tidak dibuat). Tautan PDF di dalam pesan adalah signed URL yang hanya berlaku untuk
     * invoice ini dan kedaluwarsa setelah MASA_BERLAKU_TAUTAN_HARI hari.
     *
     * @param  object  $invoice  baris tbl_invoice + nama_perusahaan, pic_telp
     * @return array{url: string|null, galat: string|null}
     */
    public function tautanWhatsApp(object $invoice): array
    {
        $nomor = self::normalisasiNomorWhatsApp($invoice->pic_telp ?? null);

        if ($nomor === null) {
            return ['url' => null, 'galat' => blank($invoice->pic_telp ?? null)
                ? 'Nomor WhatsApp PIC klien '.$invoice->nama_perusahaan.' belum diisi. Lengkapi nomor telepon PIC pada data klien.'
                : 'Nomor WhatsApp PIC klien '.$invoice->nama_perusahaan.' ('.$invoice->pic_telp.') tidak valid. Gunakan nomor seluler, misalnya 081234567890.'];
        }

        $rekening = (array) config('perusahaan.rekening');
        if (blank($rekening['bank'] ?? null) || blank($rekening['nomor'] ?? null) || blank($rekening['nama'] ?? null)) {
            return ['url' => null, 'galat' => 'Informasi rekening resmi perusahaan belum lengkap (nama bank, nomor rekening, atas nama). '
                .'Lengkapi konfigurasi rekening (SJI_REKENING_*) sebelum mengirim tagihan via WhatsApp.'];
        }

        $urlPdf = URL::temporarySignedRoute('invoice.publik.pdf', now()->addDays(self::MASA_BERLAKU_TAUTAN_HARI), ['id' => $invoice->id]);

        return ['url' => 'https://wa.me/'.$nomor.'?text='.rawurlencode($this->pesanWhatsApp($invoice, $urlPdf, $rekening)), 'galat' => null];
    }

    /**
     * Pesan penagihan WhatsApp. Nominal & periode dari tbl_invoice, rekening dari config/perusahaan.php.
     *
     * @param  array<string, string|null>  $rekening
     */
    public function pesanWhatsApp(object $invoice, string $urlPdf, array $rekening): string
    {
        return implode("\n", [
            'Yth. Bapak/Ibu PIC '.$invoice->nama_perusahaan.',',
            '',
            'Dengan hormat,',
            '',
            'Kami dari Bagian Keuangan PT Satu Jaya Indonesia menyampaikan tagihan invoice jasa bulanan dengan rincian berikut:',
            '',
            'Nama Klien: '.$invoice->nama_perusahaan,
            'Nomor Invoice: '.$invoice->nomor_invoice,
            'Periode Tagihan: '.self::BULAN[(int) $invoice->periode_bulan].' '.$invoice->periode_tahun,
            'Total Tagihan: Rp '.number_format((float) $invoice->total_tagihan, 0, ',', '.'),
            '',
            'Dokumen invoice lengkap (PDF 3 halaman) dapat dilihat atau diunduh melalui tautan berikut:',
            '',
            $urlPdf,
            '',
            'Pembayaran dapat dilakukan melalui rekening resmi PT Satu Jaya Indonesia:',
            '',
            (string) $rekening['bank'],
            (string) $rekening['nomor'],
            (string) $rekening['nama'],
            '',
            'Mohon konfirmasi setelah pembayaran dilakukan.',
            '',
            'Terima kasih atas perhatian dan kerja sama Bapak/Ibu.',
            '',
            'Hormat kami,',
            'Bagian Keuangan',
            'PT Satu Jaya Indonesia',
        ]);
    }

    /**
     * Teks bawaan surat untuk klien & periode invoice.
     *
     * @return array<string, string>
     */
    public function teksBawaan(string $namaKlien, string $labelPeriode): array
    {
        return array_map(fn (string $teks): string => strtr($teks, ['{klien}' => $namaKlien, '{periode}' => $labelPeriode]), self::TEKS_BAWAAN);
    }

    private function dokumenTersimpan(int $idInvoice): ?object
    {
        if (! Schema::hasTable('tbl_invoice_dokumen')) {
            return null;
        }

        return DB::table('tbl_invoice_dokumen as d')
            ->leftJoin('tbl_users as u', 'u.id', '=', 'd.diubah_oleh')
            ->where('d.id_invoice', $idInvoice)
            ->first(['d.*', 'u.nama as nama_pengubah']);
    }

    /**
     * @param  array<string, string>  $bawaan
     * @return array<string, string>
     */
    private function teksSurat(?object $tersimpan, array $bawaan): array
    {
        $hasil = [];
        foreach ($bawaan as $kolom => $teks) {
            $hasil[$kolom] = filled($tersimpan?->{$kolom} ?? null) ? (string) $tersimpan->{$kolom} : $teks;
        }

        return $hasil;
    }

    /**
     * @return array<string, string|null>
     */
    private function teksKolom(?object $tersimpan): array
    {
        $hasil = [];
        foreach (array_keys(self::TEKS_BAWAAN) as $kolom) {
            $hasil[$kolom] = $tersimpan?->{$kolom} ?? null;
        }

        return $hasil;
    }

    /**
     * Catatan tambahan manual Admin Keuangan (bukan log Operasional).
     *
     * @return list<array{id: int, isi: string, dibuat_oleh: string, dibuat_pada: string}>
     */
    private function catatanManual(int $idInvoice): array
    {
        if (! Schema::hasTable('tbl_invoice_catatan')) {
            return [];
        }

        return DB::table('tbl_invoice_catatan as c')
            ->leftJoin('tbl_users as u', 'u.id', '=', 'c.dibuat_oleh')
            ->where('c.id_invoice', $idInvoice)
            ->orderBy('c.id')
            ->get(['c.id', 'c.isi', 'c.dibuat_oleh', 'c.dibuat_pada', 'u.nama as nama_pembuat'])
            ->map(fn (object $c): array => [
                'id' => (int) $c->id,
                'isi' => (string) $c->isi,
                'dibuat_oleh' => $c->nama_pembuat ?? 'Pengguna #'.$c->dibuat_oleh,
                'dibuat_pada' => (string) $c->dibuat_pada,
            ])
            ->all();
    }

    private function tanggal(CarbonImmutable $tanggal): string
    {
        return sprintf('%02d %s %d', $tanggal->day, self::BULAN[$tanggal->month], $tanggal->year);
    }
}

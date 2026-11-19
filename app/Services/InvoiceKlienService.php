<?php

namespace App\Services;

use App\Support\Terbilang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    public function __construct(private PertukaranShiftService $pertukaranShift) {}

    /**
     * @return array{
     *     invoice: object,
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

        return [
            'invoice' => $invoice,
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

    private function tanggal(CarbonImmutable $tanggal): string
    {
        return sprintf('%02d %s %d', $tanggal->day, self::BULAN[$tanggal->month], $tanggal->year);
    }
}

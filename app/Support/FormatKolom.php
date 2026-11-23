<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/**
 * Helper TAMPILAN untuk kolom dinamis dari view/tabel database.
 * Hanya memformat (label, Rupiah, tanggal Indonesia, ikon). Tidak ada perhitungan.
 */
class FormatKolom
{
    private const NAMA_BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** Kata kunci kolom bernilai uang. */
    private const POLA_UANG = '/(gaji|tunjangan|upah|bonus|insentif|lembur|potongan|pph|pajak|bpjs|denda|kasbon|thr|nominal|uang|biaya|total|bersih|kotor|pendapatan|penghasilan)/i';

    /** Kata kunci kolom angka yang BUKAN uang (jumlah hari, jam, dll.). */
    private const POLA_BUKAN_UANG = '/(hari|jam|baris|jumlah_hadir|kehadiran|hadir|izin|sakit|alpha|alpa|cuti|terlambat|menit|bulan|tahun|^id_|_id$)/i';

    /** Kata kunci kolom potongan (untuk pengelompokan tampilan slip). */
    private const POLA_POTONGAN = '/(potongan|pph|pajak|bpjs|denda|kasbon|iuran)/i';

    private const LABEL_KHUSUS = [
        'nik' => 'NIK', 'npwp' => 'NPWP', 'bpjs' => 'BPJS', 'pph' => 'PPh', 'pph21' => 'PPh 21',
        'no_hp' => 'No. HP', 'no_telp' => 'No. Telepon', 'no_rekening' => 'No. Rekening',
        'tgl_lahir' => 'Tanggal Lahir', 'tgl_masuk' => 'Tanggal Masuk', 'email' => 'Email',
        'jam_masuk' => 'Jam Masuk', 'jam_keluar' => 'Jam Keluar', 'thr' => 'THR',
        'nip' => 'NIP', 'no_ktp' => 'No. KTP',
        'potongan_bpjs' => 'Potongan BPJS', 'nama_klien_bpjs' => 'Klien BPJS',
        'total_baris' => 'Hari Tercatat', 'total_jam_lembur' => 'Indikasi Lembur (Jam)',
        'hari_tidak_hadir' => 'Hari Tidak Hadir', 'total_menit_terlambat' => 'Total Menit Terlambat',
    ];

    public static function label(string $kolom): string
    {
        if (isset(self::LABEL_KHUSUS[$kolom])) {
            return self::LABEL_KHUSUS[$kolom];
        }

        $teks = preg_replace(['/^tgl_/', '/^no_/'], ['tanggal_', 'no._'], $kolom);

        return ucwords(str_replace('_', ' ', $teks));
    }

    public static function isUang(string $kolom, mixed $nilai): bool
    {
        return is_numeric($nilai)
            && preg_match(self::POLA_UANG, $kolom)
            && ! preg_match(self::POLA_BUKAN_UANG, $kolom);
    }

    public static function isPotongan(string $kolom): bool
    {
        return (bool) preg_match(self::POLA_POTONGAN, $kolom);
    }

    public static function rupiah(mixed $nilai): string
    {
        return 'Rp '.number_format((float) $nilai, 0, ',', '.');
    }

    public static function namaBulan(mixed $bulan): string
    {
        return is_numeric($bulan) ? (self::NAMA_BULAN[(int) $bulan] ?? (string) $bulan) : (string) $bulan;
    }

    public static function tanggal(mixed $nilai, string $format = 'd F Y'): string
    {
        if (blank($nilai)) {
            return '—';
        }
        try {
            return Carbon::parse($nilai)->locale('id')->translatedFormat($format);
        } catch (Throwable) {
            return (string) $nilai;
        }
    }

    public static function jam(mixed $nilai): string
    {
        if (blank($nilai)) {
            return '—';
        }
        try {
            return Carbon::parse($nilai)->format('H:i');
        } catch (Throwable) {
            return (string) $nilai;
        }
    }

    /** Format otomatis berdasarkan nama kolom & isi nilai. */
    public static function nilai(string $kolom, mixed $nilai): string
    {
        if ($nilai === null || $nilai === '') {
            return '—';
        }
        if (self::isUang($kolom, $nilai)) {
            return self::rupiah($nilai);
        }
        if (preg_match('/^(jam|waktu)_|check_(in|out)/i', $kolom)) {
            return self::jam($nilai);
        }
        if (preg_match('/(tanggal|tgl)/i', $kolom) || preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $nilai)) {
            return self::tanggal($nilai);
        }
        if (preg_match('/^bulan$|_bulan$/i', $kolom)) {
            return self::namaBulan($nilai);
        }

        return (string) $nilai;
    }

    /** Warna badge untuk nilai status (tampilan saja). */
    public static function warnaStatus(mixed $status): string
    {
        $s = strtolower((string) $status);

        return match (true) {
            str_contains($s, 'hadir') || str_contains($s, 'tepat') || str_contains($s, 'lunas')
                || str_contains($s, 'aktif') || str_contains($s, 'dibayar') => 'sukses',
            str_contains($s, 'terlambat') || str_contains($s, 'izin') || str_contains($s, 'cuti') || str_contains($s, 'sakit')
                || str_contains($s, 'pending') || str_contains($s, 'proses') => 'peringatan',
            str_contains($s, 'alpa') || str_contains($s, 'alpha') || str_contains($s, 'tidak')
                || str_contains($s, 'belum') || str_contains($s, 'nonaktif') => 'bahaya',
            default => 'netral',
        };
    }

    /** Ikon Bootstrap Icons berdasarkan nama kolom. */
    public static function ikon(string $kolom): string
    {
        return match (true) {
            (bool) preg_match('/nik|ktp|npwp/i', $kolom) => 'bi-person-vcard',
            (bool) preg_match('/email/i', $kolom) => 'bi-envelope',
            (bool) preg_match('/hp|telp|telepon|phone/i', $kolom) => 'bi-telephone',
            (bool) preg_match('/alamat/i', $kolom) => 'bi-geo-alt',
            (bool) preg_match('/lahir/i', $kolom) => 'bi-cake2',
            (bool) preg_match('/kelamin|gender/i', $kolom) => 'bi-gender-ambiguous',
            (bool) preg_match('/jabatan|posisi/i', $kolom) => 'bi-briefcase',
            (bool) preg_match('/kategori|jenis/i', $kolom) => 'bi-tags',
            (bool) preg_match('/status/i', $kolom) => 'bi-patch-check',
            (bool) preg_match('/rekening|bank/i', $kolom) => 'bi-bank',
            (bool) preg_match('/pendidikan/i', $kolom) => 'bi-mortarboard',
            (bool) preg_match('/gaji|tunjangan|upah/i', $kolom) => 'bi-cash-stack',
            (bool) preg_match('/tanggal|tgl|masuk/i', $kolom) => 'bi-calendar-event',
            (bool) preg_match('/nama/i', $kolom) => 'bi-person',
            default => 'bi-info-circle',
        };
    }
}

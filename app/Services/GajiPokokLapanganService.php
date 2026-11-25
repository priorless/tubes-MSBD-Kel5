<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji pokok sebulan karyawan Lapangan yang diinput HRD (form Data Karyawan).
 *
 * Tidak ada penyimpanan kedua: nilai ditulis ke tbl_komponen_gaji.gaji_pokok, sumber yang sama dengan
 * Komponen Gaji Keuangan dan fn_gaji_pokok_berlaku / sp_generate_payroll_bulanan
 * (sql/Komponen/19_gaji_pokok_komponen.sql). Satu baris per karyawan per periode; nilai berlaku mulai
 * periode itu sampai diubah. NULL pada suatu periode = mengikuti gaji periode sebelumnya. Karyawan
 * Internal tetap memakai tbl_karyawan.gaji_pokok dan tidak lewat layanan ini.
 */
class GajiPokokLapanganService
{
    private const array NAMA_BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /** Kolom dibuat migration 2026_11_25_043434_gaji_pokok_komponen_gaji. */
    public function tersedia(): bool
    {
        return Schema::hasColumn('tbl_komponen_gaji', 'gaji_pokok');
    }

    /**
     * Gaji pokok yang berlaku pada periode (input terakhir pada periode <= periode ini), sama dengan
     * fn_gaji_pokok_berlaku. Null bila belum pernah diinput (payroll memakai gaji kontrak penempatan).
     *
     * @return array{nominal: float, bulan: int, tahun: int, label_periode: string}|null
     */
    public function berlaku(int $idKaryawan, int $bulan, int $tahun): ?array
    {
        $baris = DB::table('tbl_komponen_gaji')
            ->where('id_karyawan', $idKaryawan)
            ->whereNotNull('gaji_pokok')
            ->whereRaw('periode_tahun * 12 + periode_bulan <= ?', [$tahun * 12 + $bulan])
            ->orderByDesc('periode_tahun')
            ->orderByDesc('periode_bulan')
            ->first(['gaji_pokok', 'periode_bulan', 'periode_tahun']);

        return $baris === null ? null : [
            'nominal' => (float) $baris->gaji_pokok,
            'bulan' => (int) $baris->periode_bulan,
            'tahun' => (int) $baris->periode_tahun,
            'label_periode' => self::labelPeriode((int) $baris->periode_bulan, (int) $baris->periode_tahun),
        ];
    }

    /**
     * Menyimpan gaji pokok yang berlaku mulai periode ini. Tidak menulis apa pun bila nilainya sama dengan
     * yang sudah berlaku; sama dengan gaji periode sebelumnya = NULL (seperti Komponen Gaji Keuangan), sehingga
     * riwayat hanya berisi periode perubahan gaji. Tunjangan & potongan pinjaman pada baris yang ada tidak disentuh.
     */
    public function simpan(int $idKaryawan, int $bulan, int $tahun, string $nominal): void
    {
        $sekarang = $this->berlaku($idKaryawan, $bulan, $tahun);

        if ($sekarang !== null && $this->sama($sekarang['nominal'], $nominal)) {
            return;
        }

        $periodeLalu = $bulan === 1 ? [12, $tahun - 1] : [$bulan - 1, $tahun];
        $sebelumnya = $this->berlaku($idKaryawan, ...$periodeLalu);
        $nilai = $sebelumnya !== null && $this->sama($sebelumnya['nominal'], $nominal) ? null : $nominal;

        $periode = ['id_karyawan' => $idKaryawan, 'periode_bulan' => $bulan, 'periode_tahun' => $tahun];

        if (DB::table('tbl_komponen_gaji')->where($periode)->exists()) {
            DB::table('tbl_komponen_gaji')->where($periode)->update(['gaji_pokok' => $nilai, 'updated_at' => now()]);
        } elseif ($nilai !== null) {
            DB::table('tbl_komponen_gaji')->insert([
                ...$periode,
                'gaji_pokok' => $nilai,
                'tunjangan' => 0,
                'potongan_pinjaman' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public static function labelPeriode(int $bulan, int $tahun): string
    {
        return self::NAMA_BULAN[$bulan].' '.$tahun;
    }

    private function sama(float $tersimpan, string $nominal): bool
    {
        return abs($tersimpan - (float) $nominal) < 0.005;
    }
}

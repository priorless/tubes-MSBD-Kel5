<?php

namespace App\Http\Requests\Hrd;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi bentuk isian saja. Aturan bisnis (tanggal tidak di masa depan, karyawan tidak Nonaktif,
 * belum ada absensi, penempatan pada tanggal itu) diputuskan sp_input_ketidakhadiran di database.
 *
 * Karyawan diketik bebas (nama atau NIP, atau "Nama (NIP)" dari saran) lalu dicocokkan persis ke
 * satu baris tbl_karyawan. Tidak ada pencocokan sebagian: isian yang tidak cocok atau cocok ke lebih
 * dari satu karyawan ditolak, sehingga ketidakhadiran tidak pernah tercatat pada orang yang salah.
 */
class StoreKetidakhadiranRequest extends FormRequest
{
    public const array STATUS = ['Izin', 'Sakit', 'Alpha'];

    private ?int $idKaryawanCocok = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'karyawan' => ['bail', 'required', 'string', 'max:150'],
            'tgl' => ['bail', 'required', 'date_format:Y-m-d'],
            'status_kehadiran' => ['required', Rule::in(self::STATUS)],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('karyawan')) {
                    return;
                }

                $hasil = $this->cocokkanKaryawan(preg_replace('/\s+/', ' ', trim((string) $this->input('karyawan'))));

                if (is_string($hasil)) {
                    $validator->errors()->add('karyawan', $hasil);

                    return;
                }

                $this->idKaryawanCocok = $hasil;
            },
        ];
    }

    /** id tbl_karyawan hasil pencocokan isian karyawan (hanya dipanggil setelah validasi lolos). */
    public function idKaryawan(): int
    {
        return (int) $this->idKaryawanCocok;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':Attribute wajib diisi.',
            'integer' => ':Attribute tidak valid.',
            'date_format' => ':Attribute bukan tanggal yang valid.',
            'in' => ':Attribute hanya boleh Izin, Sakit, atau Alpha.',
            'string' => ':Attribute harus berupa teks.',
            'max' => ':Attribute maksimal :max karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'karyawan' => 'karyawan',
            'tgl' => 'tanggal',
            'status_kehadiran' => 'status ketidakhadiran',
            'keterangan' => 'keterangan',
        ];
    }

    /**
     * Urutan: "Nama (NIP)" dari saran (nama harus sesuai NIP-nya), NIP saja, lalu nama lengkap yang unik.
     *
     * @return int|string id karyawan, atau pesan kesalahan
     */
    private function cocokkanKaryawan(string $isian): int|string
    {
        $tidakDitemukan = "Karyawan \"{$isian}\" tidak ditemukan. Ketik nama lengkap atau NIP yang terdaftar, atau pilih dari saran.";

        if (preg_match('/^(.*?)\s*\(([^()]+)\)$/u', $isian, $bagian) === 1) {
            $karyawan = $this->karyawanDenganNip($bagian[2]);

            if ($karyawan === null) {
                return $tidakDitemukan;
            }

            if ($bagian[1] !== '' && mb_strtolower($bagian[1]) !== mb_strtolower((string) $karyawan->nama)) {
                return "Nama dan NIP tidak cocok: NIP {$karyawan->nip} terdaftar atas nama {$karyawan->nama}.";
            }

            return (int) $karyawan->id;
        }

        $karyawan = $this->karyawanDenganNip($isian);

        if ($karyawan !== null) {
            return (int) $karyawan->id;
        }

        $samaNama = DB::table('tbl_karyawan')->whereRaw('LOWER(nama) = ?', [mb_strtolower($isian)])->limit(2)->pluck('id');

        return match ($samaNama->count()) {
            0 => $tidakDitemukan,
            1 => (int) $samaNama->first(),
            default => "Ada lebih dari satu karyawan bernama \"{$isian}\". Ketik NIP-nya atau pilih dari saran agar tidak tertukar.",
        };
    }

    private function karyawanDenganNip(string $nip): ?object
    {
        return DB::table('tbl_karyawan')->whereRaw('LOWER(nip) = ?', [mb_strtolower(trim($nip))])->first(['id', 'nip', 'nama']);
    }
}

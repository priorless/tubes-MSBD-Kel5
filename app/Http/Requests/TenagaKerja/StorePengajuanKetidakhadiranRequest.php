<?php

namespace App\Http\Requests\TenagaKerja;

use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Pengajuan Izin/Sakit/Cuti oleh karyawan. Rentang & tabrakan tanggal juga dijaga database
 * (CHECK chk_pengajuan_ketidakhadiran_tanggal dan trigger trg_pengajuan_ketidakhadiran_insert).
 */
class StorePengajuanKetidakhadiranRequest extends FormRequest
{
    /** @var list<string> */
    public const array JENIS = ['Izin', 'Sakit', 'Cuti'];

    /** Sakit/izin mendadak masih boleh diajukan sampai sekian hari setelahnya. */
    public const int MUNDUR_HARI = 7;

    /** Pengajuan paling jauh untuk tanggal sekian hari ke depan. */
    public const int MAJU_HARI = 90;

    /** Satu pengajuan paling banyak sekian hari (sama dengan CHECK database). */
    public const int MAKS_HARI = 31;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hariIni = Carbon::today();

        return [
            'jenis' => ['required', Rule::in(self::JENIS)],
            'tgl_mulai' => ['bail', 'required', 'date_format:Y-m-d',
                'after_or_equal:'.$hariIni->copy()->subDays(self::MUNDUR_HARI)->toDateString(),
                'before_or_equal:'.$hariIni->copy()->addDays(self::MAJU_HARI)->toDateString()],
            'tgl_selesai' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:tgl_mulai', $this->batasRentang(...)],
            'alasan' => ['required', 'string', 'max:500'],
            'file_bukti' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jenis.required' => 'Pilih jenis ketidakhadiran.',
            'jenis.in' => 'Jenis ketidakhadiran hanya Izin, Sakit, atau Cuti.',
            'tgl_mulai.required' => 'Isi tanggal mulai.',
            'tgl_mulai.after_or_equal' => 'Tanggal mulai paling lambat '.self::MUNDUR_HARI.' hari yang lalu.',
            'tgl_mulai.before_or_equal' => 'Tanggal mulai paling jauh '.self::MAJU_HARI.' hari ke depan.',
            'tgl_selesai.required' => 'Isi tanggal selesai.',
            'tgl_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            '*.date_format' => 'Format tanggal tidak valid.',
            'alasan.required' => 'Isi alasan ketidakhadiran.',
            'alasan.max' => 'Alasan maksimal 500 karakter.',
            'file_bukti.mimes' => 'Bukti harus berformat PDF, JPG, atau PNG.',
            'file_bukti.max' => 'Bukti maksimal 4 MB.',
            'file_bukti.file' => 'Bukti harus berupa berkas.',
        ];
    }

    private function batasRentang(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $mulai = $this->date('tgl_mulai', 'Y-m-d');
        } catch (InvalidFormatException) {
            return; // Format tgl_mulai salah sudah dilaporkan aturannya sendiri.
        }

        if ($mulai !== null && $mulai->diffInDays(Carbon::parse($value)) >= self::MAKS_HARI) {
            $fail('Satu pengajuan paling banyak '.self::MAKS_HARI.' hari.');
        }
    }
}

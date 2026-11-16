<?php

namespace App\Http\Requests\Hrd;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi bentuk isian saja. Aturan bisnis (tanggal tidak di masa depan, karyawan tidak Nonaktif,
 * belum ada absensi, penempatan pada tanggal itu) diputuskan sp_input_ketidakhadiran di database.
 */
class StoreKetidakhadiranRequest extends FormRequest
{
    public const array STATUS = ['Izin', 'Sakit', 'Alpha'];

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
            'id_karyawan' => ['bail', 'required', 'integer', Rule::exists('tbl_karyawan', 'id')],
            'tgl' => ['bail', 'required', 'date_format:Y-m-d'],
            'status_kehadiran' => ['required', Rule::in(self::STATUS)],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':Attribute wajib diisi.',
            'integer' => ':Attribute tidak valid.',
            'exists' => ':Attribute yang dipilih tidak valid.',
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
            'id_karyawan' => 'karyawan',
            'tgl' => 'tanggal',
            'status_kehadiran' => 'status ketidakhadiran',
            'keterangan' => 'keterangan',
        ];
    }
}

<?php

namespace App\Http\Requests\TenagaKerja;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi bentuk isian pengajuan backup shift: jadwal milik sendiri yang ingin digantikan dan rekan pengganti.
 * Aturan bisnis (H-1, jadwal milik pengaju, jabatan sama, rekan tersedia, belum ada pengajuan berjalan)
 * diputuskan PertukaranShiftService dan dijaga trigger database.
 * Id pengaju tidak pernah dikirim dari form: selalu KaryawanAktif::id().
 */
class StorePengajuanTukarShiftRequest extends FormRequest
{
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
            'id_jadwal' => ['bail', 'required', 'integer', 'min:1'],
            'id_karyawan_rekan' => ['bail', 'required', 'integer', 'min:1'],
            'alasan' => ['required', 'string', 'min:10', 'max:500'],
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
            'min' => ':Attribute minimal :min karakter.',
            'id_jadwal.min' => 'Jadwal yang dipilih tidak valid.',
            'id_karyawan_rekan.min' => 'Rekan yang dipilih tidak valid.',
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
            'id_jadwal' => 'jadwal shift',
            'id_karyawan_rekan' => 'rekan pengganti',
            'alasan' => 'alasan',
        ];
    }
}

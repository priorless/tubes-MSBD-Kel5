<?php

namespace App\Http\Requests\TenagaKerja;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi bentuk isian pengajuan tukar shift. Aturan bisnis (H-1, klien dan jenis layanan sama,
 * jadwal ada, shift berbeda, belum ada pengajuan menunggu) diputuskan PertukaranShiftService.
 * tanggal_kerja = tanggal jadwal pengaju; tanggal_kerja_rekan = tanggal jadwal rekan (boleh berbeda,
 * bila kosong dianggap sama dengan tanggal_kerja).
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
            'tanggal_kerja' => ['bail', 'required', 'date_format:Y-m-d'],
            'tanggal_kerja_rekan' => ['bail', 'nullable', 'date_format:Y-m-d'],
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
            'id_karyawan_rekan.min' => 'Rekan yang dipilih tidak valid.',
            'date_format' => ':Attribute bukan tanggal yang valid.',
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
            'tanggal_kerja' => 'tanggal shift',
            'tanggal_kerja_rekan' => 'tanggal shift rekan',
            'id_karyawan_rekan' => 'rekan',
            'alasan' => 'alasan',
        ];
    }
}

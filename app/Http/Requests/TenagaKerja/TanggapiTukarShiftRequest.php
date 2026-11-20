<?php

namespace App\Http\Requests\TenagaKerja;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi bentuk tanggapan rekan atas pengajuan tukar shift. Kepemilikan pengajuan dan
 * kondisi jadwal diputuskan PertukaranShiftService.
 */
class TanggapiTukarShiftRequest extends FormRequest
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
            'tanggapan' => ['required', Rule::in(['terima', 'tolak'])],
            'catatan_rekan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tanggapan.required' => 'Pilih terima atau tolak.',
            'tanggapan.in' => 'Tanggapan tidak valid.',
            'catatan_rekan.max' => 'Catatan maksimal 500 karakter.',
        ];
    }

    public function menerima(): bool
    {
        return $this->validated('tanggapan') === 'terima';
    }
}

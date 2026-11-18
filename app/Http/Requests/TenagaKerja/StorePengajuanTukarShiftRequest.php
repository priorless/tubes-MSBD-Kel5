<?php

namespace App\Http\Requests\TenagaKerja;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi bentuk isian pengajuan tukar shift. Aturan bisnis (H-1, klien sama, jadwal ada,
 * shift berbeda, belum ada pengajuan menunggu) diputuskan PertukaranShiftService.
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
            'id_karyawan_rekan' => ['bail', 'required', 'integer', 'min:1'],
            'alasan' => ['required', 'string', 'min:10', 'max:500'],
            'konfirmasi_rekan' => ['accepted'],
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
            'konfirmasi_rekan.accepted' => 'Pastikan rekan sudah menyetujui pertukaran, lalu centang pernyataan persetujuan rekan.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tanggal_kerja' => 'tanggal shift',
            'id_karyawan_rekan' => 'rekan',
            'alasan' => 'alasan',
        ];
    }
}

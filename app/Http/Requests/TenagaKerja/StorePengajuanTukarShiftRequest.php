<?php

namespace App\Http\Requests\TenagaKerja;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi bentuk isian pengajuan tukar shift. Aturan bisnis (H-1, klien dan jenis layanan sama,
 * jadwal ada, shift berbeda, belum ada pengajuan menunggu) diputuskan PertukaranShiftService.
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
            'id_jadwal_pengaju' => ['bail', 'required', 'integer', 'min:1'],
            'id_jadwal_rekan' => ['bail', 'required', 'integer', 'min:1', 'different:id_jadwal_pengaju'],
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
            'id_jadwal_pengaju.min' => 'Jadwal Anda yang dipilih tidak valid.',
            'id_jadwal_rekan.min' => 'Jadwal rekan yang dipilih tidak valid.',
            'different' => 'Pilih dua jadwal yang berbeda.',
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
            'id_jadwal_pengaju' => 'jadwal Anda',
            'id_jadwal_rekan' => 'jadwal rekan',
            'alasan' => 'alasan',
        ];
    }
}

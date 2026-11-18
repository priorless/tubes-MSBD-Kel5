<?php

namespace App\Http\Requests\TenagaKerja;

use App\Http\Controllers\TenagaKerjaController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Unggahan koreksi (kedua) untuk laporan yang sama. Kepemilikan laporan, sesi aktif, periode,
 * dan sisa kesempatan unggah diputuskan LaporanAktivitasService.
 */
class KoreksiLaporanAktivitasRequest extends FormRequest
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
            'foto' => TenagaKerjaController::aturanFoto(),
            'keterangan' => ['nullable', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'foto.required' => 'Foto koreksi wajib diunggah.',
            'foto.*' => 'Foto harus berupa foto JPG, PNG, atau WEBP yang valid (maksimal '.intdiv((int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360), 1024).' MB).',
            'keterangan.min' => 'Keterangan laporan minimal :min karakter.',
            'keterangan.max' => 'Keterangan laporan maksimal :max karakter.',
        ];
    }
}

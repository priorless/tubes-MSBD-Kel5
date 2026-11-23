<?php

namespace App\Http\Requests\TenagaKerja;

use App\Http\Controllers\TenagaKerjaController;
use App\Services\LaporanAktivitasService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bentuk isian laporan aktivitas. Periode monitoring, sesi aktif, dan batas unggahan diputuskan
 * LaporanAktivitasService dengan waktu server — tidak pernah dikirim dari form.
 */
class StoreLaporanAktivitasRequest extends FormRequest
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
            'keterangan' => ['required', 'string', 'min:3', 'max:500'],
            'tahapan' => ['nullable', 'string', Rule::in(array_keys(LaporanAktivitasService::TAHAPAN_PERJALANAN))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'foto.required' => 'Foto bukti aktivitas wajib diunggah.',
            'foto.*' => 'Foto harus berupa foto JPG, PNG, atau WEBP yang valid (maksimal '.intdiv((int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360), 1024).' MB).',
            'keterangan.required' => 'Keterangan laporan wajib diisi.',
            'keterangan.min' => 'Keterangan laporan minimal :min karakter.',
            'keterangan.max' => 'Keterangan laporan maksimal :max karakter.',
            'tahapan.in' => 'Pilih tahapan perjalanan yang valid.',
        ];
    }
}

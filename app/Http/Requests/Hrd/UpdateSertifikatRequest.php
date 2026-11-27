<?php

namespace App\Http\Requests\Hrd;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * Ubah sertifikat: hanya tanggal terbit, tanggal kedaluwarsa, dan berkas (opsional). Aturan, pesan, dan nama
 * atribut sama persis dengan upload sertifikat (StoreSertifikatRequest). Karyawan & nama dokumen tidak diubah
 * agar identitas sertifikat tetap.
 */
class UpdateSertifikatRequest extends StoreSertifikatRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return Arr::only(parent::rules(), ['tgl_terbit', 'tgl_kadaluarsa', 'file_upload']);
    }
}

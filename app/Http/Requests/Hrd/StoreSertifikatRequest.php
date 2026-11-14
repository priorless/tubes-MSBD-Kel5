<?php

namespace App\Http\Requests\Hrd;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSertifikatRequest extends FormRequest
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
            'id_karyawan' => ['bail', 'required', 'integer', Rule::exists('tbl_karyawan', 'id')],
            'nama_dokumen' => ['bail', 'required', 'string', 'max:100'],
            'nomor_dokumen' => ['nullable', 'string', 'max:50'],
            'tgl_terbit' => ['bail', 'required', 'date'],
            'tgl_kadaluarsa' => ['bail', 'required', 'date', 'after:tgl_terbit'],
            'file_upload' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':Attribute wajib diisi.',
            'string' => ':Attribute harus berupa teks.',
            'integer' => ':Attribute tidak valid.',
            'max.string' => ':Attribute maksimal :max karakter.',
            'max.file' => ':Attribute maksimal :max KB.',
            'date' => ':Attribute bukan tanggal yang valid.',
            'after' => 'Tanggal kedaluwarsa harus setelah tanggal terbit.',
            'exists' => ':Attribute yang dipilih tidak valid.',
            'file' => ':Attribute harus berupa berkas.',
            'mimes' => ':Attribute harus berformat :values.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'id_karyawan' => 'karyawan',
            'nama_dokumen' => 'nama dokumen',
            'nomor_dokumen' => 'nomor dokumen',
            'tgl_terbit' => 'tanggal terbit',
            'tgl_kadaluarsa' => 'tanggal kedaluwarsa',
            'file_upload' => 'berkas dokumen',
        ];
    }
}

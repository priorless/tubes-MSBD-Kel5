<?php

namespace App\Http\Requests\Keuangan;

use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form kirim invoice via email. Nominal & PDF tidak pernah diambil dari form: controller membacanya dari
 * database berdasarkan id invoice di URL. id_invoice di form wajib sama dengan URL agar invoice tidak tertukar.
 * CC boleh beberapa alamat dipisah koma, titik koma, atau baris baru.
 */
class KirimInvoiceEmailRequest extends FormRequest
{
    public const int MAKS_CC = 10;

    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole(Role::Keuangan);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email_tujuan' => trim((string) $this->input('email_tujuan')),
            'daftar_cc' => array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $this->input('cc')) ?: []))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id_invoice' => ['bail', 'required', 'integer', 'in:'.(int) $this->route('id')],
            'email_tujuan' => ['required', 'email:rfc', 'max:100'],
            'cc' => ['nullable', 'string', 'max:1000'],
            'daftar_cc' => ['array', 'max:'.self::MAKS_CC],
            'daftar_cc.*' => ['email:rfc', 'max:100', 'distinct:ignore_case', 'different:email_tujuan'],
            'subjek' => ['required', 'string', 'max:200'],
            'pesan' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id_invoice.in' => 'Form email tidak sesuai dengan invoice yang dipilih. Muat ulang halaman lalu coba lagi.',
            'id_invoice.required' => 'Form email tidak sesuai dengan invoice yang dipilih.',
            'email_tujuan.required' => 'Email tujuan wajib diisi.',
            'email_tujuan.email' => 'Email tujuan tidak valid.',
            'daftar_cc.max' => 'CC maksimal :max alamat email.',
            'daftar_cc.*.email' => 'Alamat CC ":input" tidak valid.',
            'daftar_cc.*.distinct' => 'Alamat CC ":input" diisi lebih dari sekali.',
            'daftar_cc.*.different' => 'Alamat CC ":input" sama dengan email tujuan.',
            'subjek.required' => 'Subjek email wajib diisi.',
            'pesan.required' => 'Isi pesan wajib diisi.',
            'max' => ':Attribute maksimal :max karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email_tujuan' => 'Email tujuan', 'cc' => 'CC', 'subjek' => 'Subjek', 'pesan' => 'Isi pesan'];
    }
}

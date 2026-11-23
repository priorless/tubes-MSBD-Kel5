<?php

namespace App\Http\Requests\Keuangan;

use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Isian mode edit dokumen tagihan: hanya teks surat dan catatan manual. Nominal tidak termasuk dan
 * field lain yang dikirim diabaikan (controller hanya memakai validated()).
 * id_invoice wajib sama dengan invoice di URL agar dokumen tidak tertukar; versi mencegah saling menimpa.
 */
class UpdateInvoiceDokumenRequest extends FormRequest
{
    public const int MAKS_CATATAN = 30;

    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole(Role::Keuangan);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id_invoice' => ['bail', 'required', 'integer', 'in:'.(int) $this->route('id')],
            'versi' => ['required', 'integer', 'min:0'],
            'penerima' => ['nullable', 'string', 'max:150'],
            'hal' => ['nullable', 'string', 'max:200'],
            'paragraf_pembuka' => ['nullable', 'string', 'max:2000'],
            'paragraf_penutup' => ['nullable', 'string', 'max:2000'],
            'salam_penutup' => ['nullable', 'string', 'max:255'],
            'catatan' => ['nullable', 'array', 'max:'.self::MAKS_CATATAN],
            'catatan.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'catatan.*.isi' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id_invoice.in' => 'Dokumen yang dikirim tidak sesuai dengan invoice yang sedang diedit. Muat ulang halaman lalu coba lagi.',
            'id_invoice.required' => 'Dokumen yang dikirim tidak sesuai dengan invoice yang sedang diedit.',
            'versi.required' => 'Versi dokumen tidak ditemukan. Muat ulang halaman lalu coba lagi.',
            'max' => ':Attribute maksimal :max karakter.',
            'catatan.max' => 'Catatan tambahan maksimal :max baris.',
            'catatan.*.isi.max' => 'Setiap catatan tambahan maksimal :max karakter.',
            'catatan.*.id.distinct' => 'Catatan yang sama terkirim dua kali. Muat ulang halaman lalu coba lagi.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'penerima' => 'Penerima surat',
            'hal' => 'Perihal',
            'paragraf_pembuka' => 'Paragraf pembuka',
            'paragraf_penutup' => 'Paragraf penutup',
            'salam_penutup' => 'Kalimat salam',
        ];
    }
}

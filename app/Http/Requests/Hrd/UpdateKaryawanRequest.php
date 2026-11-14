<?php

namespace App\Http\Requests\Hrd;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Validasi format input ubah karyawan.
 *
 * NIP dan kategori tidak dapat diubah: NIP dipakai trigger sebagai username akun Lapangan,
 * dan kategori hanya divalidasi trigger saat INSERT. Status dikelola oleh stored procedure
 * (mis. sp_assign_penempatan), sehingga juga tidak diubah dari form ini.
 */
class UpdateKaryawanRequest extends FormRequest
{
    use PesanValidasiKaryawan;

    private ?string $kategoriTersimpan = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $idKaryawan = (int) $this->route('karyawan');
        $isInternal = $this->kategoriTersimpan() === 'Internal';

        return [
            'nama' => ['bail', 'required', 'string', 'max:100'],
            'id_jenis_layanan' => $isInternal
                ? ['exclude']
                : ['bail', 'filled', 'integer', Rule::exists('tbl_jenis_layanan', 'id')],
            'gaji_pokok' => $isInternal
                ? ['bail', 'filled', 'numeric', 'min:0', 'max:9999999999.99']
                : ['exclude'],
            'tunjangan' => $isInternal
                ? ['nullable', 'numeric', 'min:0', 'max:9999999999.99']
                : ['exclude'],
            'no_ktp' => ['bail', 'required', 'digits:16', Rule::unique('tbl_karyawan', 'no_ktp')->ignore($idKaryawan)],
            'tempat_lahir' => ['nullable', 'string', 'max:50'],
            'tgl_lahir' => ['bail', 'required', 'date', 'before:today'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'alamat' => ['nullable', 'string'],
            'no_telp' => ['bail', 'required', 'string', 'max:15', 'regex:/^\+?[0-9]{8,14}$/'],
            'email' => ['nullable', 'email', 'max:100', Rule::unique('tbl_karyawan', 'email')->ignore($idKaryawan)],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'tgl_masuk' => ['bail', 'required', 'date'],
        ];
    }

    private function kategoriTersimpan(): ?string
    {
        return $this->kategoriTersimpan ??= DB::table('tbl_karyawan')
            ->where('id', (int) $this->route('karyawan'))
            ->value('kategori');
    }
}

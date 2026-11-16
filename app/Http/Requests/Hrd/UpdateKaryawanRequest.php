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
 * dan kategori hanya divalidasi trigger saat INSERT.
 *
 * Status: Internal Aktif/Cuti/Nonaktif, Lapangan Tersedia/Cuti/Nonaktif (Ditempatkan hanya lewat
 * sp_assign_penempatan) dan tidak boleh diubah selama ada penempatan Aktif. Aturan yang sama
 * ditegakkan trigger trg_karyawan_validasi_status di database.
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
            'no_ktp' => ['bail', 'required', 'digits:16', Rule::unique('tbl_karyawan', 'no_ktp')->ignore($idKaryawan)],
            'tempat_lahir' => ['nullable', 'string', 'max:50'],
            'tgl_lahir' => ['bail', 'required', 'date', 'before:today'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'alamat' => ['nullable', 'string'],
            'no_telp' => ['bail', 'required', 'string', 'max:15', 'regex:/^\+?[0-9]{8,14}$/'],
            'email' => ['nullable', 'email', 'max:100', Rule::unique('tbl_karyawan', 'email')->ignore($idKaryawan)],
            'status' => self::punyaPenempatanAktif($idKaryawan)
                ? ['exclude']
                : ['sometimes', 'required', Rule::in(self::pilihanStatus($this->kategoriTersimpan(), $this->statusTersimpan()))],
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

    private function statusTersimpan(): ?string
    {
        return DB::table('tbl_karyawan')->where('id', (int) $this->route('karyawan'))->value('status');
    }

    /**
     * Pilihan status yang boleh dipilih HRD. Status saat ini selalu ikut agar form tidak mengubahnya tanpa sengaja.
     *
     * @return list<string>
     */
    public static function pilihanStatus(?string $kategori, ?string $statusSekarang = null): array
    {
        $pilihan = $kategori === 'Internal' ? ['Aktif', 'Cuti', 'Nonaktif'] : ['Tersedia', 'Cuti', 'Nonaktif'];

        if ($statusSekarang !== null && ! in_array($statusSekarang, $pilihan, true)) {
            array_unshift($pilihan, $statusSekarang);
        }

        return $pilihan;
    }

    public static function punyaPenempatanAktif(int $idKaryawan): bool
    {
        return DB::table('tbl_penempatan')->where('id_karyawan', $idKaryawan)->where('status', 'Aktif')->exists();
    }
}

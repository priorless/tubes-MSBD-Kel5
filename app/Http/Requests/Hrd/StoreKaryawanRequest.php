<?php

namespace App\Http\Requests\Hrd;

use App\Services\GajiPokokLapanganService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi format input karyawan baru.
 *
 * Form Tambah Karyawan HRD hanya mendaftarkan karyawan Lapangan: kategori otomatis "Lapangan" bila
 * tidak dikirim, dan nilai lain (termasuk "Internal") ditolak. Data karyawan Internal yang sudah ada
 * tidak tersentuh dan tetap dapat diubah lewat UpdateKaryawanRequest.
 *
 * Aturan bisnis (Lapangan wajib jenis layanan, status awal, akun login Lapangan) TIDAK diulang di sini:
 * semuanya ditegakkan oleh trigger trg_set_status_karyawan_baru dan trg_auto_akun_karyawan.
 */
class StoreKaryawanRequest extends FormRequest
{
    use PesanValidasiKaryawan {
        messages as pesanValidasiKaryawan;
    }

    public const string KATEGORI = 'Lapangan';

    /**
     * Pilihan kolom tbl_karyawan.jenis_jaminan_kesehatan (ENUM). Dipakai juga oleh UpdateKaryawanRequest dan form.
     *
     * @var list<string>
     */
    public const array PILIHAN_JAMINAN_KESEHATAN = ['BPJS', 'Asuransi Kesehatan Swasta'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('kategori')) {
            $this->merge(['kategori' => self::KATEGORI]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->pesanValidasiKaryawan(),
            'kategori.in' => 'Karyawan baru hanya dapat didaftarkan dengan kategori Lapangan.',
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nip' => ['bail', 'required', 'string', 'max:20', Rule::unique('tbl_karyawan', 'nip')],
            'nama' => ['bail', 'required', 'string', 'max:100'],
            'kategori' => ['required', Rule::in([self::KATEGORI])],
            'id_jenis_layanan' => ['nullable', 'integer', Rule::exists('tbl_jenis_layanan', 'id')],
            'no_ktp' => ['bail', 'required', 'digits:16', Rule::unique('tbl_karyawan', 'no_ktp')],
            'tempat_lahir' => ['nullable', 'string', 'max:50'],
            'tgl_lahir' => ['bail', 'required', 'date', 'before:today'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'status_pernikahan' => ['nullable', Rule::in(['Belum Menikah', 'Menikah'])],
            'nama_ibu_kandung' => ['nullable', 'string', 'max:100'],
            'jenis_jaminan_kesehatan' => ['nullable', Rule::in(self::PILIHAN_JAMINAN_KESEHATAN)],
            'alamat' => ['nullable', 'string'],
            'no_telp' => ['bail', 'required', 'string', 'max:15', 'regex:/^\+?[0-9]{8,14}$/'],
            'email' => ['nullable', 'email', 'max:100', Rule::unique('tbl_karyawan', 'email')],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'tgl_masuk' => ['bail', 'required', 'date'],
            // Gaji pokok Lapangan disimpan ke tbl_komponen_gaji (GajiPokokLapanganService), bukan tbl_karyawan.
            'gaji_pokok' => self::aturanGajiPokokLapangan(),
            // Isian akun tidak divalidasi sehingga tidak ikut tersimpan:
            // akun login karyawan Lapangan dibuat otomatis oleh trigger trg_auto_akun_karyawan.
        ];
    }

    /**
     * Gaji pokok sebulan karyawan Lapangan (opsional; kosong = payroll memakai gaji kontrak penempatan).
     * Diabaikan selama kolom tbl_komponen_gaji.gaji_pokok belum dimigrasi. Dipakai juga UpdateKaryawanRequest.
     *
     * @return list<string>
     */
    public static function aturanGajiPokokLapangan(): array
    {
        return app(GajiPokokLapanganService::class)->tersedia()
            ? ['bail', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99']
            : ['exclude'];
    }
}

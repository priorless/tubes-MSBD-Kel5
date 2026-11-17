<?php

namespace App\Http\Requests\Hrd;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi format input karyawan baru.
 *
 * Aturan bisnis (Internal wajib gaji pokok, Lapangan wajib jenis layanan, status awal,
 * akun login Lapangan) TIDAK diulang di sini: semuanya ditegakkan oleh trigger
 * trg_set_status_karyawan_baru dan trg_auto_akun_karyawan.
 */
class StoreKaryawanRequest extends FormRequest
{
    use PesanValidasiKaryawan;

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

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nip' => ['bail', 'required', 'string', 'max:20', Rule::unique('tbl_karyawan', 'nip')],
            'nama' => ['bail', 'required', 'string', 'max:100'],
            'kategori' => ['required', Rule::in(['Lapangan', 'Internal'])],
            'id_jenis_layanan' => ['nullable', 'integer', Rule::exists('tbl_jenis_layanan', 'id')],
            'gaji_pokok' => ['exclude_if:kategori,Lapangan', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
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

            // Akun login hanya untuk staf Internal; untuk Lapangan akun dibuat oleh trigger.
            'buat_akun' => ['exclude_if:kategori,Lapangan', 'boolean'],
            'username' => ['exclude_if:kategori,Lapangan', 'exclude_unless:buat_akun,1', 'bail', 'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('tbl_users', 'username')],
            'password' => ['exclude_if:kategori,Lapangan', 'exclude_unless:buat_akun,1', 'required', 'string', 'min:8', 'confirmed'],
            'id_role' => [
                'exclude_if:kategori,Lapangan', 'exclude_unless:buat_akun,1', 'required', 'integer',
                Rule::exists('tbl_roles', 'id')->whereNot('nama_role', 'Karyawan Lapangan'),
            ],
        ];
    }
}

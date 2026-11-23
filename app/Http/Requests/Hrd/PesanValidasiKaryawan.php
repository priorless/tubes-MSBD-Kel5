<?php

namespace App\Http\Requests\Hrd;

/**
 * Pesan dan nama atribut validasi berbahasa Indonesia untuk form karyawan.
 */
trait PesanValidasiKaryawan
{
    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':Attribute wajib diisi.',
            'filled' => ':Attribute tidak boleh dikosongkan.',
            'string' => ':Attribute harus berupa teks.',
            'integer' => ':Attribute tidak valid.',
            'numeric' => ':Attribute harus berupa angka.',
            'boolean' => ':Attribute tidak valid.',
            'min.numeric' => ':Attribute tidak boleh kurang dari :min.',
            'max.numeric' => ':Attribute terlalu besar.',
            'min.string' => ':Attribute minimal :min karakter.',
            'max.string' => ':Attribute maksimal :max karakter.',
            'max.file' => ':Attribute maksimal :max KB.',
            'digits' => ':Attribute harus :digits digit angka.',
            'date' => ':Attribute bukan tanggal yang valid.',
            'before' => ':Attribute harus sebelum hari ini.',
            'email' => ':Attribute harus alamat email yang valid.',
            'unique' => ':Attribute sudah terdaftar.',
            'exists' => ':Attribute yang dipilih tidak valid.',
            'in' => ':Attribute yang dipilih tidak valid.',
            'image' => ':Attribute harus berupa gambar.',
            'mimes' => ':Attribute harus berformat :values.',
            'username.regex' => 'Username hanya boleh berisi huruf, angka, titik, tanda hubung, dan garis bawah.',
            'confirmed' => 'Konfirmasi :attribute tidak cocok.',
            'no_telp.regex' => 'Nomor telepon hanya boleh berisi angka (8–14 digit), boleh diawali tanda +.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nip' => 'NIP',
            'nama' => 'nama',
            'kategori' => 'kategori',
            'status' => 'status',
            'id_jenis_layanan' => 'jenis layanan',
            'gaji_pokok' => 'gaji pokok',
            'no_ktp' => 'nomor KTP',
            'tempat_lahir' => 'tempat lahir',
            'tgl_lahir' => 'tanggal lahir',
            'jenis_kelamin' => 'jenis kelamin',
            'status_pernikahan' => 'status pernikahan',
            'nama_ibu_kandung' => 'nama ibu kandung',
            'jenis_jaminan_kesehatan' => 'jenis jaminan kesehatan',
            'alamat' => 'alamat',
            'no_telp' => 'nomor telepon',
            'email' => 'email',
            'foto' => 'foto',
            'tgl_masuk' => 'tanggal masuk',
            'buat_akun' => 'pilihan buat akun',
            'username' => 'username',
            'password' => 'password',
            'id_role' => 'role',
        ];
    }
}

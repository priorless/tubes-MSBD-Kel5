<?php

namespace App\Console\Commands;

use App\Services\DatabaseService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Pengisian data awal karyawan Lapangan saat modul HRD belum tersedia di lingkungan ini.
 *
 * Sesuai arsitektur proyek, PHP hanya menyimpan data karyawan. Akun login dibuat oleh
 * trigger trg_auto_akun_karyawan (username = NIP, password awal = SHA2(NIP)) dan diverifikasi
 * oleh LoginRequest. Aturan validasi mengikuti StoreKaryawanRequest milik modul HRD.
 */
#[Signature('sji:tambah-karyawan-lapangan
    {--nip= : NIP karyawan (juga menjadi username dan password awal)}
    {--nama= : Nama lengkap}
    {--layanan= : ID jenis layanan (tbl_jenis_layanan.id)}
    {--ktp= : Nomor KTP, 16 digit}
    {--tgl-lahir= : Tanggal lahir (YYYY-MM-DD)}
    {--jk= : Jenis kelamin, L atau P}
    {--telp= : Nomor telepon}
    {--tgl-masuk= : Tanggal masuk kerja (YYYY-MM-DD)}
    {--tempat-lahir= : Tempat lahir (opsional)}
    {--alamat= : Alamat (opsional)}
    {--email= : Email (opsional)}')]
#[Description('Menambah karyawan Lapangan ke tbl_karyawan; akun login dibuat otomatis oleh trigger database.')]
class TambahKaryawanLapangan extends Command
{
    public function handle(): int
    {
        $jenisLayanan = DB::table('tbl_jenis_layanan')->orderBy('id')->pluck('nama_layanan', 'id');

        $data = [
            'nip' => $this->isi('nip', 'NIP'),
            'nama' => $this->isi('nama', 'Nama lengkap'),
            'id_jenis_layanan' => $this->option('layanan') ?? $this->pilihLayanan($jenisLayanan->all()),
            'no_ktp' => $this->isi('ktp', 'Nomor KTP (16 digit)'),
            'tempat_lahir' => $this->isi('tempat-lahir', 'Tempat lahir (boleh kosong)'),
            'tgl_lahir' => $this->isi('tgl-lahir', 'Tanggal lahir (YYYY-MM-DD)'),
            'jenis_kelamin' => $this->isi('jk', 'Jenis kelamin (L/P)'),
            'alamat' => $this->isi('alamat', 'Alamat (boleh kosong)'),
            'no_telp' => $this->isi('telp', 'Nomor telepon'),
            'email' => $this->isi('email', 'Email (boleh kosong)'),
            'tgl_masuk' => $this->isi('tgl-masuk', 'Tanggal masuk (YYYY-MM-DD)'),
        ];

        $validator = Validator::make($data, [
            'nip' => ['bail', 'required', 'string', 'max:20', Rule::unique('tbl_karyawan', 'nip'), Rule::unique('tbl_users', 'username')],
            'nama' => ['bail', 'required', 'string', 'max:100'],
            'id_jenis_layanan' => ['required', 'integer', Rule::exists('tbl_jenis_layanan', 'id')],
            'no_ktp' => ['bail', 'required', 'digits:16', Rule::unique('tbl_karyawan', 'no_ktp')],
            'tempat_lahir' => ['nullable', 'string', 'max:50'],
            'tgl_lahir' => ['bail', 'required', 'date_format:Y-m-d', 'before:today'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'alamat' => ['nullable', 'string'],
            'no_telp' => ['bail', 'required', 'string', 'max:15', 'regex:/^\+?[0-9]{8,14}$/'],
            'email' => ['nullable', 'email', 'max:100', Rule::unique('tbl_karyawan', 'email')],
            'tgl_masuk' => ['bail', 'required', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $pesan) {
                $this->error($pesan);
            }

            return self::FAILURE;
        }

        try {
            $idKaryawan = DB::transaction(
                fn (): int => DB::table('tbl_karyawan')->insertGetId([...$validator->validated(), 'kategori' => 'Lapangan'])
            );
        } catch (QueryException $exception) {
            $this->error($this->pesanGagal($exception));

            return self::FAILURE;
        }

        $akun = DB::table('tbl_users as u')
            ->join('tbl_roles as r', 'r.id', '=', 'u.id_role')
            ->where('u.id_karyawan', $idKaryawan)
            ->first(['u.username', 'u.status', 'r.nama_role']);

        $this->info("Karyawan Lapangan tersimpan (tbl_karyawan.id = {$idKaryawan}).");

        if ($akun === null) {
            $this->warn('Akun login tidak dibuat oleh trigger trg_auto_akun_karyawan. Periksa trigger di database.');

            return self::FAILURE;
        }

        $this->table(['Username', 'Password awal', 'Role', 'Status'], [
            [$akun->username, '(sama dengan NIP)', $akun->nama_role, $akun->status],
        ]);
        $this->line('Login di /login. Segera ganti password setelah masuk (menu Ganti Password).');

        return self::SUCCESS;
    }

    /**
     * Ambil nilai dari opsi; jika tidak diberikan dan sesi interaktif, tanyakan.
     */
    private function isi(string $opsi, string $pertanyaan): ?string
    {
        $nilai = $this->option($opsi) ?? ($this->input->isInteractive() ? $this->ask($pertanyaan) : null);
        $nilai = is_string($nilai) ? trim($nilai) : null;

        return $nilai === '' ? null : $nilai;
    }

    /**
     * @param  array<int, string>  $jenisLayanan  id => nama_layanan
     */
    private function pilihLayanan(array $jenisLayanan): ?int
    {
        if (! $this->input->isInteractive() || $jenisLayanan === []) {
            return null;
        }

        $nama = $this->choice('Jenis layanan', array_values($jenisLayanan));
        $id = array_search($nama, $jenisLayanan, true);

        return $id === false ? null : (int) $id;
    }

    /**
     * Terjemahkan error database menjadi pesan yang bisa ditindaklanjuti.
     */
    private function pesanGagal(QueryException $exception): string
    {
        if (DatabaseService::isPesanBisnis($exception)) {
            return DatabaseService::pesanError($exception);
        }

        // 1048 = kolom NOT NULL diisi NULL. Terjadi bila trigger trg_auto_akun_karyawan masih
        // memakai SHA2(NEW.nip, 255): panjang 255 tidak valid sehingga SHA2 menghasilkan NULL.
        if ((int) ($exception->errorInfo[1] ?? 0) === 1048 && str_contains((string) ($exception->errorInfo[2] ?? ''), 'password')) {
            return 'Gagal: trigger trg_auto_akun_karyawan menghasilkan password NULL. '
                .'Trigger di database harus dibuat ulang dengan SHA2(NEW.nip, 256) sesuai sql/Komponen/01_komponen_orang1.sql. '
                .'Data karyawan TIDAK tersimpan.';
        }

        return 'Gagal menyimpan ke database: '.($exception->errorInfo[2] ?? $exception->getMessage());
    }
}

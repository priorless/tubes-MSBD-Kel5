<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Skema minimal (SQLite) untuk Template Jadwal Bulanan: tabel induk dibuat versi ringkas,
 * sedangkan tiga tabel template dibuat oleh file migration aslinya.
 */
trait CreatesTemplateJadwalTables
{
    protected function createTables(): void
    {
        Schema::create('tbl_klien', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_klien');
            $table->string('nama_perusahaan');
            $table->text('alamat')->nullable();
            $table->string('pic_nama')->nullable();
            $table->string('pic_jabatan')->nullable();
            $table->string('pic_telp')->nullable();
            $table->string('pic_email')->nullable();
            $table->string('status')->default('Aktif');
        });
        Schema::create('tbl_jenis_layanan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_layanan');
        });
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip');
            $table->string('nama');
            $table->integer('id_jenis_layanan')->nullable();
            $table->string('kategori')->default('Lapangan');
            $table->string('status')->default('Tersedia');
        });
        Schema::create('tbl_kontrak', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_penempatan');
            $table->string('status')->default('Aktif');
        });
        Schema::create('tbl_penempatan', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_karyawan');
            $table->integer('id_klien');
            $table->string('lokasi_kerja')->nullable();
            $table->time('jam_datang')->nullable();
            $table->time('jam_pulang')->nullable();
            $table->date('tgl_mulai');
            $table->date('tgl_selesai')->nullable();
            $table->string('status');
        });
        Schema::create('tbl_template_shift', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_klien');
            $table->string('nama_shift');
            $table->time('jam_masuk');
            $table->time('jam_pulang');
            $table->unsignedSmallInteger('durasi_menit');
            $table->string('status')->default('Aktif');
            $table->timestamps();
        });
        Schema::create('tbl_jadwal_kerja', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_penempatan');
            $table->date('tanggal_kerja');
            $table->string('status');
            $table->unsignedInteger('id_template_shift')->nullable();
            $table->string('nama_shift_snapshot')->nullable();
            $table->time('jam_masuk')->nullable();
            $table->time('jam_pulang')->nullable();
            $table->unsignedSmallInteger('durasi_menit')->nullable();
            $table->integer('dibuat_oleh')->nullable();
            $table->integer('diubah_oleh')->nullable();
            $table->timestamps();
            $table->unique(['id_penempatan', 'tanggal_kerja']);
        });
        Schema::create('tbl_jadwal_kerja_riwayat', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_jadwal_kerja');
            $table->string('aksi');
            $table->json('data_sebelum')->nullable();
            $table->json('data_sesudah');
            $table->integer('diubah_oleh')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        (require database_path('migrations/2026_11_16_173550_create_template_jadwal_tables.php'))->up();
    }

    protected function createClient(string $code): int
    {
        return (int) DB::table('tbl_klien')->insertGetId(['kode_klien' => $code, 'nama_perusahaan' => 'Klien '.$code]);
    }

    protected function createService(string $name): int
    {
        return (int) DB::table('tbl_jenis_layanan')->insertGetId(['nama_layanan' => $name]);
    }

    protected function createEmployee(string $nip, string $name, ?int $serviceId = null): int
    {
        return (int) DB::table('tbl_karyawan')->insertGetId(['nip' => $nip, 'nama' => $name, 'id_jenis_layanan' => $serviceId]);
    }

    protected function createPlacement(int $id, int $employeeId, int $clientId, string $start, ?string $end = null, string $status = 'Aktif'): void
    {
        DB::table('tbl_penempatan')->insert([
            'id' => $id,
            'id_karyawan' => $employeeId,
            'id_klien' => $clientId,
            'tgl_mulai' => $start,
            'tgl_selesai' => $end,
            'status' => $status,
        ]);
    }

    protected function createSchedule(int $placementId, string $date, string $status, ?string $start = null, ?string $end = null): int
    {
        return (int) DB::table('tbl_jadwal_kerja')->insertGetId([
            'id_penempatan' => $placementId,
            'tanggal_kerja' => $date,
            'status' => $status,
            'jam_masuk' => $start === null ? null : $start.':00',
            'jam_pulang' => $end === null ? null : $end.':00',
            'durasi_menit' => $start === null ? null : 720,
        ]);
    }
}

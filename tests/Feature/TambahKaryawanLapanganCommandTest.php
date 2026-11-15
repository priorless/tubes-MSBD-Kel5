<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Perintah sji:tambah-karyawan-lapangan. Tabel & trigger SQLite di sini meniru skema db_sji:
 * trigger membuat akun login (username = NIP, password = SHA-256 hex dari NIP).
 */
class TambahKaryawanLapanganCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, string>
     */
    private array $opsiValid = [
        '--nip' => 'SJI1001',
        '--nama' => 'Budi Santoso',
        '--layanan' => '1',
        '--ktp' => '3201010101010001',
        '--tgl-lahir' => '1995-05-17',
        '--jk' => 'L',
        '--telp' => '081234567890',
        '--tgl-masuk' => '2026-01-05',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tbl_roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nama_role', 50)->unique();
        });
        Schema::create('tbl_jenis_layanan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nama_layanan', 50);
        });
        Schema::create('tbl_karyawan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nip', 20)->unique();
            $table->string('nama', 100);
            $table->string('kategori')->default('Lapangan');
            $table->unsignedInteger('id_jenis_layanan')->nullable();
            $table->string('no_ktp', 16)->unique();
            $table->string('tempat_lahir', 50)->nullable();
            $table->date('tgl_lahir');
            $table->string('jenis_kelamin', 1);
            $table->text('alamat')->nullable();
            $table->string('no_telp', 15);
            $table->string('email', 100)->nullable()->unique();
            $table->date('tgl_masuk');
        });
        Schema::create('tbl_users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan')->nullable()->unique();
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->unsignedInteger('id_role');
            $table->string('status')->default('Aktif');
        });

        DB::table('tbl_roles')->insert(['id' => 4, 'nama_role' => 'Karyawan Lapangan']);
        DB::table('tbl_jenis_layanan')->insert(['id' => 1, 'nama_layanan' => 'Satpam']);

        // Tiruan trg_auto_akun_karyawan (SQLite tidak punya SHA2, jadi hash diisi penanda yang sama untuk diperiksa).
        DB::unprepared("
            CREATE TRIGGER trg_auto_akun_karyawan AFTER INSERT ON tbl_karyawan
            WHEN NEW.kategori = 'Lapangan'
            BEGIN
                INSERT INTO tbl_users (id_karyawan, username, password, id_role)
                VALUES (NEW.id, NEW.nip, 'sha256:' || NEW.nip, (SELECT id FROM tbl_roles WHERE nama_role = 'Karyawan Lapangan'));
            END
        ");
    }

    public function test_menyimpan_karyawan_lapangan_dan_akun_dibuat_trigger(): void
    {
        $this->artisan('sji:tambah-karyawan-lapangan', $this->opsiValid + ['--no-interaction' => true])
            ->expectsOutputToContain('Karyawan Lapangan tersimpan')
            ->assertSuccessful();

        $karyawan = DB::table('tbl_karyawan')->where('nip', 'SJI1001')->first();
        $this->assertSame('Lapangan', $karyawan->kategori);
        $this->assertSame(1, (int) $karyawan->id_jenis_layanan);

        $akun = DB::table('tbl_users')->where('id_karyawan', $karyawan->id)->first();
        $this->assertSame('SJI1001', $akun->username);
        $this->assertSame(4, (int) $akun->id_role);
    }

    public function test_data_tidak_valid_ditolak_tanpa_menyimpan_apa_pun(): void
    {
        $this->artisan('sji:tambah-karyawan-lapangan', [
            ...$this->opsiValid,
            '--ktp' => '123',
            '--jk' => 'X',
            '--layanan' => '99',
            '--no-interaction' => true,
        ])->assertFailed();

        $this->assertSame(0, DB::table('tbl_karyawan')->count());
        $this->assertSame(0, DB::table('tbl_users')->count());
    }

    public function test_nip_ganda_ditolak(): void
    {
        $this->artisan('sji:tambah-karyawan-lapangan', $this->opsiValid + ['--no-interaction' => true])->assertSuccessful();

        $this->artisan('sji:tambah-karyawan-lapangan', [
            ...$this->opsiValid,
            '--ktp' => '3201010101010002',
            '--no-interaction' => true,
        ])->assertFailed();

        $this->assertSame(1, DB::table('tbl_karyawan')->count());
    }

    public function test_tanpa_akun_dari_trigger_dilaporkan_gagal(): void
    {
        DB::unprepared('DROP TRIGGER trg_auto_akun_karyawan');

        $this->artisan('sji:tambah-karyawan-lapangan', $this->opsiValid + ['--no-interaction' => true])
            ->expectsOutputToContain('Akun login tidak dibuat')
            ->assertFailed();
    }
}

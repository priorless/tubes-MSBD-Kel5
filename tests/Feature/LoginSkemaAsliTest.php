<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Login pada tbl_users versi sql/schema_sji.sql (TANPA kolom nama & must_change_password),
 * yaitu kondisi db_sji yang sebenarnya dipakai proyek.
 */
class LoginSkemaAsliTest extends TestCase
{
    use RefreshDatabase;

    private Role $roleLapangan;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tbl_roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nama_role', 50)->unique();
            $table->string('deskripsi')->nullable();
        });

        Schema::create('tbl_users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan')->nullable()->unique();
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->unsignedInteger('id_role');
            $table->string('status')->default('Aktif');
            $table->timestamp('created_at')->useCurrent();
        });

        $this->roleLapangan = Role::factory()->named(Role::KaryawanLapangan)->create();
    }

    /**
     * Akun seperti buatan trigger trg_auto_akun_karyawan: username = NIP, password = SHA2(NIP, 256).
     */
    private function akunDariTrigger(string $nip, string $status = 'Aktif'): int
    {
        return DB::table('tbl_users')->insertGetId([
            'id_karyawan' => 7,
            'username' => $nip,
            'password' => hash('sha256', $nip),
            'id_role' => $this->roleLapangan->id,
            'status' => $status,
        ]);
    }

    public function test_skema_asli_memang_tanpa_kolom_opsional(): void
    {
        $this->assertFalse(User::punyaKolom('nama'));
        $this->assertFalse(User::punyaKolom('must_change_password'));
    }

    public function test_akun_lapangan_sha256_bisa_login_dan_password_diganti_ke_bcrypt(): void
    {
        $id = $this->akunDariTrigger('SJI0001');

        $this->post('/login', ['username' => 'SJI0001', 'password' => 'SJI0001'])
            ->assertRedirect(route('tenaga-kerja.absensi'));

        $this->assertAuthenticatedAs(User::find($id));

        $passwordBaru = DB::table('tbl_users')->where('id', $id)->value('password');
        $this->assertStringStartsWith('$2y$', $passwordBaru);
        $this->assertTrue(Hash::check('SJI0001', $passwordBaru));
    }

    public function test_password_salah_ditolak_dan_hash_tidak_berubah(): void
    {
        $id = $this->akunDariTrigger('SJI0002');

        $this->from('/login')
            ->post('/login', ['username' => 'SJI0002', 'password' => 'salah'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertSame(hash('sha256', 'SJI0002'), DB::table('tbl_users')->where('id', $id)->value('password'));
    }

    public function test_akun_nonaktif_tidak_bisa_login(): void
    {
        $this->akunDariTrigger('SJI0003', 'Nonaktif');

        $this->post('/login', ['username' => 'SJI0003', 'password' => 'SJI0003'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_ganti_password_berjalan_tanpa_kolom_must_change_password(): void
    {
        $id = $this->akunDariTrigger('SJI0004');
        $this->post('/login', ['username' => 'SJI0004', 'password' => 'SJI0004']);

        $this->put(route('password.change.update'), [
            'current_password' => 'SJI0004',
            'password' => 'RahasiaBaru123',
            'password_confirmation' => 'RahasiaBaru123',
        ])->assertRedirect(route('tenaga-kerja.absensi'));

        $this->assertTrue(Hash::check('RahasiaBaru123', DB::table('tbl_users')->where('id', $id)->value('password')));
    }

    public function test_logout_mengakhiri_sesi(): void
    {
        $this->akunDariTrigger('SJI0005');
        $this->post('/login', ['username' => 'SJI0005', 'password' => 'SJI0005']);

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}

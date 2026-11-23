<?php

namespace Tests\Feature\Keamanan;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Uji SQL injection terhadap MySQL db_sji (bukan SQLite) agar perilaku parser MySQL ikut teruji.
 * Login dan pencarian harus memperlakukan payload sebagai teks biasa: tidak login, tidak error 500,
 * tidak membocorkan semua data, dan tabel tetap utuh.
 */
class SqlInjectionTest extends TestCase
{
    use MemakaiDatabaseSji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function payload(): array
    {
        return [
            'OR 1=1 komentar #' => ["' OR 1=1 #"],
            'komentar admin' => ["admin'-- "],
            'OR string sama' => ["' OR '1'='1"],
            'kutip ganda' => ['" OR ""="'],
            'stacked DROP TABLE' => ["'; DROP TABLE tbl_users; -- "],
        ];
    }

    #[DataProvider('payload')]
    public function test_login_dengan_payload_injeksi_gagal_dan_tabel_tetap_ada(string $payload): void
    {
        $user = User::factory()->withRole(Role::Hrd)->create(['username' => 'admin']);
        $this->dataUji['user'][] = $user->id;
        $jumlahUser = DB::table('tbl_users')->count();

        $this->from(route('login'))
            ->post(route('login.store'), ['username' => $payload, 'password' => $payload])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertTrue(Schema::hasTable('tbl_users'));
        $this->assertSame($jumlahUser, DB::table('tbl_users')->count());
    }

    public function test_login_menolak_karakter_di_luar_pola_username_dengan_pesan_indonesia(): void
    {
        $this->from(route('login'))
            ->post(route('login.store'), ['username' => "admin' OR '1'='1", 'password' => 'apa saja'])
            ->assertSessionHasErrors(['username' => 'Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.']);

        $this->assertGuest();
    }

    public function test_nip_dan_username_biasa_tetap_lolos_validasi_login(): void
    {
        $karyawan = $this->buatKaryawan('Lapangan');
        $nip = (string) DB::table('tbl_karyawan')->where('id', $karyawan)->value('nip');

        foreach ([$nip, 'budi.santoso', 'hrd_01', 'SJI-2026-001'] as $username) {
            $this->from(route('login'))
                ->post(route('login.store'), ['username' => $username, 'password' => 'salah'])
                ->assertSessionHasErrors(['username' => 'Username atau password salah.']);
        }
    }

    #[DataProvider('payload')]
    public function test_pencarian_karyawan_klien_dan_absensi_aman_dari_payload(string $payload): void
    {
        $this->buatKaryawan('Lapangan', ['nama' => 'Rahasia Injeksi Karyawan']);
        $this->buatKlien(['nama_perusahaan' => 'PT Rahasia Injeksi']);
        $this->loginSebagai(Role::Hrd);

        $this->get(route('hrd.karyawan.index', ['q' => $payload]))->assertOk()->assertDontSeeText('Rahasia Injeksi Karyawan');
        $this->get(route('hrd.klien.index', ['q' => $payload]))->assertOk()->assertDontSeeText('PT Rahasia Injeksi');
        $this->get(route('hrd.absensi', ['q' => $payload]))->assertOk();
        $this->getJson(route('hrd.absensi.karyawan.cari', ['q' => $payload]))->assertOk()->assertExactJson([]);

        $this->loginSebagai(Role::Operasional);
        $this->get(route('operasional.klien.index', ['q' => $payload]))->assertOk()->assertDontSeeText('PT Rahasia Injeksi');

        $this->assertTrue(Schema::hasTable('tbl_users'));
    }
}

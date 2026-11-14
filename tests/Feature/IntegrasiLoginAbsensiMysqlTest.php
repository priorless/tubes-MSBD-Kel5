<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Uji ujung-ke-ujung login + absensi terhadap database MySQL proyek (db_sji) yang sebenarnya:
 * trigger, view, dan waktu server database ikut teruji.
 *
 * Hanya berjalan bila diaktifkan secara eksplisit, dan SEMUA perubahan di-rollback (DatabaseTransactions):
 *   TK_TES_MYSQL=1 DB_CONNECTION=mysql DB_DATABASE=db_sji vendor/bin/phpunit --filter=IntegrasiLoginAbsensiMysqlTest
 */
class IntegrasiLoginAbsensiMysqlTest extends TestCase
{
    use DatabaseTransactions;

    private const string Nip = 'TESTK4-0001';

    private int $idKaryawan;

    protected function setUp(): void
    {
        if (getenv('TK_TES_MYSQL') !== '1') {
            $this->markTestSkipped('Uji integrasi MySQL hanya berjalan dengan TK_TES_MYSQL=1.');
        }

        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Uji integrasi membutuhkan koneksi MySQL (db_sji).');
        }

        // Nilai uji coba sengaja diisi untuk membuktikan nilai ini TIDAK dipakai setelah login.
        config(['tenaga_kerja.dev_id_karyawan' => 999999]);

        // Karyawan Internal: trigger trg_set_status_karyawan_baru tetap berjalan, dan
        // trg_auto_akun_karyawan tidak membuat akun (khusus Lapangan) sehingga akun diisi di bawah.
        $this->idKaryawan = DB::table('tbl_karyawan')->insertGetId([
            'nip' => self::Nip,
            'nama' => 'Karyawan Uji Integrasi',
            'kategori' => 'Internal',
            'gaji_pokok' => 3500000,
            'no_ktp' => '9999999999990001',
            'tgl_lahir' => '1995-01-01',
            'jenis_kelamin' => 'L',
            'no_telp' => '081200000001',
            'tgl_masuk' => '2026-01-01',
        ]);

        // Format akun persis seperti hasil trg_auto_akun_karyawan: username = NIP, password = SHA2(NIP, 256).
        DB::table('tbl_users')->insert([
            'id_karyawan' => $this->idKaryawan,
            'username' => self::Nip,
            'password' => DB::raw("SHA2('".self::Nip."', 256)"),
            'id_role' => $this->idRole(Role::KaryawanLapangan),
        ]);
    }

    private function idRole(string $namaRole): int
    {
        return (int) DB::table('tbl_roles')->where('nama_role', $namaRole)->value('id');
    }

    private function absensiHariIni(): ?object
    {
        return DB::table('tbl_absensi')
            ->where('id_karyawan', $this->idKaryawan)
            ->whereRaw('tgl = CURDATE()')
            ->first();
    }

    public function test_login_lalu_check_in_check_out_dan_riwayat_tercatat_untuk_karyawan_yang_login(): void
    {
        // Login dengan password SHA-256 dari trigger → diterima dan langsung diganti bcrypt.
        $this->post('/login', ['username' => self::Nip, 'password' => self::Nip])
            ->assertRedirect(route('tenaga-kerja.absensi'));
        $this->assertTrue(Hash::check(self::Nip, DB::table('tbl_users')->where('username', self::Nip)->value('password')));

        $this->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertSee('Karyawan Uji Integrasi')
            ->assertDontSee('Mode uji coba');

        // Check-in: tersimpan di tbl_absensi untuk id_karyawan akun yang login, tanggal & jam dari server DB.
        $this->post(route('tenaga-kerja.absensi.check-in'))
            ->assertRedirect(route('tenaga-kerja.absensi'))
            ->assertSessionHas('sukses');
        $absen = $this->absensiHariIni();
        $this->assertNotNull($absen);
        $this->assertNotNull($absen->jam_masuk);
        $this->assertNull($absen->jam_keluar);

        // Check-in kedua ditolak oleh trigger trg_cegah_absen_ganda (SQLSTATE 45000).
        $this->post(route('tenaga-kerja.absensi.check-in'))
            ->assertSessionHas('gagal', 'Karyawan sudah absen pada tanggal ini');
        $this->assertSame(1, DB::table('tbl_absensi')->where('id_karyawan', $this->idKaryawan)->count());

        // Check-out pada tanggal yang sama.
        $this->post(route('tenaga-kerja.absensi.check-out'))
            ->assertRedirect(route('tenaga-kerja.absensi'))
            ->assertSessionHas('sukses');
        $this->assertNotNull($this->absensiHariIni()->jam_keluar);

        // Check-out kedua tidak menimpa jam keluar.
        $this->post(route('tenaga-kerja.absensi.check-out'))->assertSessionHas('gagal');

        // Riwayat dari v_rekap_absensi_pribadi, profil, dan slip gaji dapat dibuka.
        $this->get(route('tenaga-kerja.riwayat'))->assertOk()->assertSee('Rekap Kehadiran Bulanan')->assertDontSee('Belum ada catatan absensi');
        $this->get(route('tenaga-kerja.profil'))->assertOk()->assertSee(self::Nip);
        $this->get(route('tenaga-kerja.slip-gaji'))->assertOk();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get(route('tenaga-kerja.absensi'))->assertRedirect(route('login'));
    }

    public function test_akun_tanpa_id_karyawan_tidak_bisa_memakai_modul_tenaga_kerja(): void
    {
        DB::table('tbl_users')->insert([
            'id_karyawan' => null,
            'username' => 'TESTK4-TANPA',
            'password' => Hash::make('RahasiaUji123'),
            'id_role' => $this->idRole(Role::KaryawanLapangan),
        ]);

        $this->post('/login', ['username' => 'TESTK4-TANPA', 'password' => 'RahasiaUji123']);

        $this->get(route('tenaga-kerja.absensi'))
            ->assertStatus(401)
            ->assertSee('Akun Anda belum terhubung dengan data karyawan');

        $this->post(route('tenaga-kerja.absensi.check-in'))->assertStatus(401);
        $this->assertSame(0, DB::table('tbl_absensi')->where('id_karyawan', 999999)->count());
    }

    public function test_akun_bcrypt_role_lain_diarahkan_sesuai_role_dan_ditolak_dari_tenaga_kerja(): void
    {
        DB::table('tbl_users')->insert([
            'id_karyawan' => null,
            'username' => 'TESTK4-CEO',
            'password' => Hash::make('RahasiaUji123'),
            'id_role' => $this->idRole(Role::Ceo),
        ]);

        $this->post('/login', ['username' => 'TESTK4-CEO', 'password' => 'RahasiaUji123'])
            ->assertRedirect(route('ceo.dashboard'));

        $this->get(route('tenaga-kerja.absensi'))->assertForbidden();
    }
}

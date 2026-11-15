<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uji ujung-ke-ujung login + absensi foto terhadap database MySQL proyek (db_sji) yang sebenarnya:
 * trigger, view, jadwal penempatan, dan waktu server database ikut teruji.
 *
 * Hanya berjalan bila diaktifkan secara eksplisit, dan SEMUA perubahan di-rollback (DatabaseTransactions);
 * file foto ditulis ke disk palsu:
 *   TK_TES_MYSQL=1 DB_CONNECTION=mysql DB_DATABASE=db_sji vendor/bin/phpunit --filter=IntegrasiLoginAbsensiMysqlTest
 */
class IntegrasiLoginAbsensiMysqlTest extends TestCase
{
    use DatabaseTransactions;

    private const string Nip = 'TESTK4-0001';

    private const string PesanFotoWajib = 'Foto absensi wajib diunggah (JPG, PNG, atau WEBP, maksimal 15 MB).';

    private int $idKaryawan;

    private int $idPenempatan;

    protected function setUp(): void
    {
        if (getenv('TK_TES_MYSQL') !== '1') {
            $this->markTestSkipped('Uji integrasi MySQL hanya berjalan dengan TK_TES_MYSQL=1.');
        }

        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Uji integrasi membutuhkan koneksi MySQL (db_sji).');
        }

        Storage::fake('local');

        // Nilai uji coba sengaja diisi untuk membuktikan nilai ini TIDAK dipakai setelah login.
        config(['tenaga_kerja.dev_id_karyawan' => 999999]);

        // Karyawan Lapangan (Satpam): trg_auto_akun_karyawan membuat akunnya
        // dengan username = NIP dan password = SHA2(NIP, 256).
        $this->idKaryawan = DB::table('tbl_karyawan')->insertGetId([
            'nip' => self::Nip,
            'nama' => 'Karyawan Uji Integrasi',
            'kategori' => 'Lapangan',
            'id_jenis_layanan' => DB::table('tbl_jenis_layanan')->where('nama_layanan', 'Satpam')->value('id'),
            'no_ktp' => '9999999999990001',
            'tgl_lahir' => '1995-01-01',
            'jenis_kelamin' => 'L',
            'no_telp' => '081200000001',
            'status' => 'Ditempatkan',
            'tgl_masuk' => '2026-01-01',
        ]);

        $idKlien = DB::table('tbl_klien')->insertGetId([
            'kode_klien' => 'TSTK4',
            'nama_perusahaan' => 'PT Uji Absensi Foto',
            'alamat' => 'Jl. Uji No. 1',
            'pic_nama' => 'PIC Uji',
            'pic_telp' => '0610000000',
        ]);

        // Jadwal penempatan (modul Operasional) relatif terhadap jam server: datang 2 jam lalu, durasi 8 jam.
        $this->idPenempatan = DB::table('tbl_penempatan')->insertGetId([
            'id_karyawan' => $this->idKaryawan,
            'id_klien' => $idKlien,
            'lokasi_kerja' => 'Pos jaga utama',
            'jam_datang' => DB::raw('TIME(NOW() - INTERVAL 2 HOUR)'),
            'jam_pulang' => DB::raw('TIME(NOW() + INTERVAL 6 HOUR)'),
            'tgl_mulai' => DB::raw('CURDATE() - INTERVAL 30 DAY'),
            'status' => 'Aktif',
        ]);
    }

    private function foto(): UploadedFile
    {
        return UploadedFile::fake()->image('absensi.jpg', 640, 480);
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

    private function buatAkun(string $username, string $role): User
    {
        DB::table('tbl_users')->insert([
            'id_karyawan' => null,
            'username' => $username,
            'password' => Hash::make('RahasiaUji123'),
            'must_change_password' => false,
            'id_role' => $this->idRole($role),
        ]);

        return User::query()->where('username', $username)->firstOrFail();
    }

    public function test_login_lalu_check_in_check_out_berfoto_dan_riwayat_tercatat_untuk_karyawan_yang_login(): void
    {
        // Login dengan password SHA-256 dari trigger → diterima, diganti bcrypt, dan wajib ganti password.
        $this->post('/login', ['username' => self::Nip, 'password' => self::Nip])
            ->assertRedirect(route('password.change'));
        $this->assertTrue(Hash::check(self::Nip, DB::table('tbl_users')->where('username', self::Nip)->value('password')));
        DB::table('tbl_users')->where('username', self::Nip)->update(['must_change_password' => false]);
        auth()->user()?->refresh();

        $this->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertSee('Karyawan Uji Integrasi')
            ->assertSee('Pos jaga utama')
            ->assertSee('Dari penempatan')
            ->assertDontSee('Mode uji coba');

        // Tanpa foto: ditolak sebelum menyentuh database.
        $this->postJson(route('tenaga-kerja.absensi.check-in'))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'pesan' => self::PesanFotoWajib]);
        $this->assertNull($this->absensiHariIni());

        // Check-in berfoto: penempatan resmi & status terlambat dari trigger (jadwal penempatan 2 jam lalu).
        $this->postJson(route('tenaga-kerja.absensi.check-in'), ['foto' => $this->foto()])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'masuk', 'status_kehadiran' => 'Terlambat']);
        $absen = $this->absensiHariIni();
        $this->assertNotNull($absen);
        $this->assertNull($absen->jam_keluar);
        $this->assertSame($this->idPenempatan, (int) $absen->id_penempatan);
        $this->assertEqualsWithDelta(120, (int) $absen->menit_terlambat, 1);
        $this->assertStringStartsWith('absensi/'.substr((string) $absen->tgl, 0, 7).'/masuk-', $absen->foto_masuk);
        Storage::disk('local')->assertExists($absen->foto_masuk);
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('local')->get($absen->foto_masuk))['mime']);
        $this->assertFalse(Schema::hasColumn('tbl_absensi', 'lat_masuk'), 'Koordinat GPS tidak boleh disimpan.');

        // Check-in kedua ditolak trigger trg_cegah_absen_ganda; foto yang gagal tidak tertinggal.
        $this->post(route('tenaga-kerja.absensi.check-in'), ['foto' => $this->foto()])
            ->assertSessionHas('gagal', 'Karyawan sudah absen pada tanggal ini');
        $this->assertSame(1, DB::table('tbl_absensi')->where('id_karyawan', $this->idKaryawan)->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('absensi'));

        // Check-out langsung setelah check-in ditolak (minimal 30 menit).
        $this->post(route('tenaga-kerja.absensi.check-out'), ['foto' => $this->foto()])
            ->assertSessionHas('gagal', fn (string $pesan) => str_starts_with($pesan, 'Check-out ditolak: minimal 30 menit'));
        $this->assertCount(1, Storage::disk('local')->allFiles('absensi'));

        // Mundurkan check-in 9 jam (bisa jatuh ke kemarin = skenario shift malam) lalu check-out tanpa lokasi.
        DB::table('tbl_absensi')->where('id', $absen->id)->update([
            'tgl' => DB::raw('DATE(NOW() - INTERVAL 9 HOUR)'),
            'jam_masuk' => DB::raw('TIME(NOW() - INTERVAL 9 HOUR)'),
        ]);

        $this->postJson(route('tenaga-kerja.absensi.check-out'), ['foto' => $this->foto()])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'selesai'])
            ->assertJsonPath('foto.keluar', route('absensi.foto', ['absensi' => $absen->id, 'jenis' => 'keluar']));
        $selesai = DB::table('tbl_absensi')->find($absen->id);
        $this->assertNotNull($selesai->jam_keluar);
        Storage::disk('local')->assertExists($selesai->foto_keluar);
        $this->assertSame(0, (int) $selesai->jam_lembur); // check-out sebelum jadwal pulang penempatan (NOW + 6 jam)

        // Check-out kedua tidak menimpa jam keluar.
        $this->post(route('tenaga-kerja.absensi.check-out'), ['foto' => $this->foto()])->assertSessionHas('gagal');

        // Pemilik dapat membuka fotonya sendiri.
        $this->get(route('absensi.foto', ['absensi' => $absen->id, 'jenis' => 'masuk']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        // Riwayat, profil, dan slip gaji dapat dibuka.
        $this->get(route('tenaga-kerja.riwayat'))->assertOk()->assertSee('Rekap Kehadiran Bulanan')->assertSee('Detail Harian')
            ->assertSee('Foto Check-in')->assertSee('Pos jaga utama')
            ->assertDontSee('Belum ada catatan absensi');
        $this->get(route('tenaga-kerja.profil'))->assertOk()->assertSee(self::Nip);
        $this->get(route('tenaga-kerja.slip-gaji'))->assertOk();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get(route('tenaga-kerja.absensi'))->assertRedirect(route('login'));
    }

    public function test_akun_tanpa_id_karyawan_tidak_bisa_memakai_modul_tenaga_kerja(): void
    {
        $this->buatAkun('TESTK4-TANPA', Role::KaryawanLapangan);

        $this->post('/login', ['username' => 'TESTK4-TANPA', 'password' => 'RahasiaUji123']);

        $this->get(route('tenaga-kerja.absensi'))
            ->assertStatus(401)
            ->assertSee('Akun Anda belum terhubung dengan data karyawan');

        $this->post(route('tenaga-kerja.absensi.check-in'), ['foto' => $this->foto()])->assertStatus(401);
        $this->assertSame(0, DB::table('tbl_absensi')->where('id_karyawan', 999999)->count());
    }

    public function test_akun_bcrypt_role_lain_diarahkan_sesuai_role_dan_ditolak_dari_tenaga_kerja(): void
    {
        $this->buatAkun('TESTK4-CEO', Role::Ceo);

        $this->post('/login', ['username' => 'TESTK4-CEO', 'password' => 'RahasiaUji123'])
            ->assertRedirect(route('ceo.dashboard'));

        $this->get(route('tenaga-kerja.absensi'))->assertForbidden();
    }

    private function loginSebagaiKaryawan(): void
    {
        $karyawan = User::query()->where('id_karyawan', $this->idKaryawan)->firstOrFail();
        DB::table('tbl_users')->where('id', $karyawan->id)->update(['must_change_password' => false]);
        $this->actingAs($karyawan->refresh());
    }

    /** Geser check-in sejauh $menit ke belakang (bisa jatuh ke kemarin = shift malam). */
    private function mundurkanCheckIn(int $idAbsensi, int $menit): void
    {
        DB::table('tbl_absensi')->where('id', $idAbsensi)->update([
            'tgl' => DB::raw("DATE(NOW() - INTERVAL $menit MINUTE)"),
            'jam_masuk' => DB::raw("TIME(NOW() - INTERVAL $menit MINUTE)"),
        ]);
    }

    public function test_jadwal_dan_indikasi_lembur_dari_penempatan_yang_berlaku_pada_tanggal_absensi(): void
    {
        $this->assertFalse(Schema::hasColumn('tbl_jenis_layanan', 'jam_masuk'), 'Jadwal tidak boleh berasal dari jenis layanan.');
        $this->loginSebagaiKaryawan();
        $idKlien = (int) DB::table('tbl_penempatan')->where('id', $this->idPenempatan)->value('id_klien');

        // Penempatan lama (sudah selesai) dan penempatan yang baru mulai besok memiliki jadwal yang
        // akan membuat karyawan terlambat — keduanya tidak berlaku hari ini sehingga tidak boleh dipakai.
        foreach ([
            ['status' => 'Rotasi', 'tgl_mulai' => DB::raw('CURDATE() - INTERVAL 90 DAY'), 'tgl_selesai' => DB::raw('CURDATE() - INTERVAL 31 DAY')],
            ['status' => 'Aktif', 'tgl_mulai' => DB::raw('CURDATE() + INTERVAL 1 DAY'), 'tgl_selesai' => null],
        ] as $lain) {
            DB::table('tbl_penempatan')->insert($lain + [
                'id_karyawan' => $this->idKaryawan, 'id_klien' => $idKlien, 'lokasi_kerja' => 'Pos lain',
                'jam_datang' => DB::raw('TIME(NOW() - INTERVAL 5 HOUR)'), 'jam_pulang' => DB::raw('TIME(NOW() - INTERVAL 4 HOUR)'),
            ]);
        }

        // Penempatan yang berlaku: datang 25 menit lalu (masih dalam toleransi 30 menit).
        DB::table('tbl_penempatan')->where('id', $this->idPenempatan)->update([
            'jam_datang' => DB::raw('TIME(NOW() - INTERVAL 25 MINUTE)'),
            'jam_pulang' => DB::raw('TIME(NOW() + INTERVAL 6 HOUR)'),
        ]);

        $this->postJson(route('tenaga-kerja.absensi.check-in'), ['foto' => $this->foto()])
            ->assertOk()
            ->assertJson(['ok' => true, 'status_kehadiran' => 'Hadir']);
        $absen = $this->absensiHariIni();
        $this->assertSame($this->idPenempatan, (int) $absen->id_penempatan);
        $this->assertSame(0, (int) $absen->menit_terlambat);

        // Bekerja 9 jam; jadwal pulang 2,5 jam sebelum check-out → indikasi lembur 2 jam penuh.
        $this->mundurkanCheckIn($absen->id, 540);
        DB::table('tbl_penempatan')->where('id', $this->idPenempatan)->update(['jam_pulang' => DB::raw('TIME(NOW() - INTERVAL 150 MINUTE)')]);
        $this->postJson(route('tenaga-kerja.absensi.check-out'), ['foto' => $this->foto()])->assertOk()->assertJson(['ok' => true]);

        $selesai = DB::table('tbl_absensi')->find($absen->id);
        $this->assertSame(2, (int) $selesai->jam_lembur);
        $this->assertNotNull($selesai->jam_masuk, 'Kolom aktual tidak boleh diubah menjadi jadwal.');

        // Riwayat karyawan & laporan HRD menampilkan jadwal penempatan yang sama dan indikasi lembur.
        $jadwal = DB::table('tbl_penempatan')->where('id', $this->idPenempatan)
            ->selectRaw("CONCAT(TIME_FORMAT(jam_datang, '%H:%i'), '&ndash;', TIME_FORMAT(jam_pulang, '%H:%i')) AS teks")->value('teks');
        $tanggal = (string) $selesai->tgl;
        $this->get(route('tenaga-kerja.riwayat', ['tahun' => substr($tanggal, 0, 4), 'bulan' => (int) substr($tanggal, 5, 2)]))
            ->assertOk()->assertSee($jadwal, false)->assertSee('Indikasi Lembur')->assertSee('2 jam');

        $this->actingAs($this->buatAkun('TESTK4-HRD', Role::Hrd));
        $this->get(route('hrd.absensi', ['tanggal' => $tanggal]))
            ->assertOk()->assertSee($jadwal, false)->assertSee('Indikasi lembur 2 jam');
    }

    public function test_jadwal_penempatan_kosong_belum_ditentukan_tanpa_terlambat_dan_lembur_serta_laporan_hrd_dengan_akses_foto(): void
    {
        $this->loginSebagaiKaryawan();

        // Jadwal penempatan kosong → "Jadwal belum ditentukan" walaupun karyawan punya jenis layanan (Satpam).
        DB::table('tbl_penempatan')->where('id', $this->idPenempatan)->update(['jam_datang' => null, 'jam_pulang' => null]);
        $this->get(route('tenaga-kerja.absensi'))->assertOk()->assertSee('Jadwal belum ditentukan');

        $this->postJson(route('tenaga-kerja.absensi.check-in'), ['foto' => $this->foto()])
            ->assertOk()
            ->assertJson(['ok' => true, 'status_kehadiran' => 'Hadir']);
        $absen = $this->absensiHariIni();
        $this->assertSame(0, (int) $absen->menit_terlambat);
        $this->assertSame($this->idPenempatan, (int) $absen->id_penempatan);

        // 12 jam kerja tanpa jadwal pulang → tidak ada indikasi lembur (tidak ada jadwal bawaan 8 jam).
        $this->mundurkanCheckIn($absen->id, 720);
        $this->postJson(route('tenaga-kerja.absensi.check-out'), ['foto' => $this->foto()])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(0, (int) DB::table('tbl_absensi')->where('id', $absen->id)->value('jam_lembur'));
        $absen = DB::table('tbl_absensi')->find($absen->id);

        // HRD melihat lokasi penempatan resmi, penanda jadwal, dan foto.
        $this->actingAs($this->buatAkun('TESTK4-HRD', Role::Hrd));
        $urlFoto = route('absensi.foto', ['absensi' => $absen->id, 'jenis' => 'masuk']);

        $this->get(route('hrd.absensi', ['tanggal' => $absen->tgl, 'tanda' => 'tanpa_jadwal']))
            ->assertOk()
            ->assertSee('Karyawan Uji Integrasi')
            ->assertSee('Pos jaga utama')
            ->assertSee('Jadwal belum ditentukan')
            ->assertSee($urlFoto, false);
        $this->get($urlFoto)->assertOk();

        // Karyawan lain tidak boleh membuka foto ini.
        $idLain = DB::table('tbl_karyawan')->insertGetId([
            'nip' => 'TESTK4-0002', 'nama' => 'Karyawan Lain', 'kategori' => 'Lapangan',
            'id_jenis_layanan' => DB::table('tbl_jenis_layanan')->where('nama_layanan', 'Satpam')->value('id'),
            'no_ktp' => '9999999999990002', 'tgl_lahir' => '1995-01-01', 'jenis_kelamin' => 'P',
            'no_telp' => '081200000002', 'status' => 'Tersedia', 'tgl_masuk' => '2026-01-01',
        ]);
        DB::table('tbl_users')->where('id_karyawan', $idLain)->update(['must_change_password' => false]);
        $this->actingAs(User::query()->where('id_karyawan', $idLain)->firstOrFail());
        $this->get($urlFoto)->assertForbidden();
    }
}

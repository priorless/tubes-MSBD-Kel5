<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

/**
 * Modul HRD bergantung pada trigger, function, dan view MySQL, sehingga test ini
 * dijalankan pada database db_sji di dalam transaksi yang selalu di-rollback.
 */
class HrdModulTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'db_sji',
        ]);
        DB::purge('mysql');

        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('Database MySQL db_sji tidak tersedia.');
        }

        DB::beginTransaction();
        Storage::fake('public');

        // Semua route HRD wajib login sebagai role HRD; akun ini ikut di-rollback di tearDown().
        $this->actingAs(User::factory()->withRole(Role::Hrd)->create());
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_karyawan_lapangan_mendapat_akun_dari_trigger_bukan_dari_php(): void
    {
        $response = $this->post(route('hrd.karyawan.store'), $this->dataKaryawan([
            'kategori' => 'Lapangan',
            'id_jenis_layanan' => $this->idLayanan('Satpam'),
            'foto' => UploadedFile::fake()->image('foto.jpg'),
            // Isian akun harus diabaikan untuk Lapangan.
            'buat_akun' => '1',
            'username' => 'tidakbolehdipakai',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]));

        $response->assertRedirect(route('hrd.karyawan.index'));
        $response->assertSessionHas('success');

        $karyawan = DB::table('tbl_karyawan')->where('nip', 'TST-001')->first();
        $this->assertSame('Tersedia', $karyawan->status);
        $this->assertTrue(Storage::disk('public')->exists($karyawan->foto));

        $akun = DB::table('tbl_users')->where('id_karyawan', $karyawan->id)->get();
        $this->assertCount(1, $akun);
        $this->assertSame('TST-001', $akun[0]->username);
        $this->assertSame(hash('sha256', 'TST-001'), $akun[0]->password, 'Akun harus berasal dari trigger (SHA2), bukan bcrypt PHP.');
        $this->assertDatabaseMissing('tbl_users', ['username' => 'tidakbolehdipakai']);
    }

    public function test_signal_trigger_lapangan_tanpa_layanan_tampil_sebagai_notifikasi(): void
    {
        $response = $this->from(route('hrd.karyawan.create'))->post(route('hrd.karyawan.store'), $this->dataKaryawan([
            'kategori' => 'Lapangan',
            'foto' => UploadedFile::fake()->image('foto.jpg'),
        ]));

        $response->assertRedirect(route('hrd.karyawan.create'));
        $response->assertSessionHas('error', 'Operasi ditolak oleh aturan database: Karyawan Lapangan wajib dipilih jenis layanannya');
        $this->assertDatabaseMissing('tbl_karyawan', ['nip' => 'TST-001']);
        $this->assertEmpty(Storage::disk('public')->allFiles(), 'Foto harus dihapus bila insert ditolak.');
    }

    public function test_signal_trigger_internal_tanpa_gaji_tampil_sebagai_notifikasi(): void
    {
        $response = $this->post(route('hrd.karyawan.store'), $this->dataKaryawan(['kategori' => 'Internal']));

        $response->assertSessionHas('error', 'Operasi ditolak oleh aturan database: Karyawan Internal wajib diisi gaji_pokoknya');
        $this->assertDatabaseMissing('tbl_karyawan', ['nip' => 'TST-001']);
    }

    public function test_karyawan_internal_dengan_akun_staf_yang_dipilih_hrd(): void
    {
        $idRoleKeuangan = DB::table('tbl_roles')->where('nama_role', 'Keuangan')->value('id');

        $response = $this->post(route('hrd.karyawan.store'), $this->dataKaryawan([
            'kategori' => 'Internal',
            'gaji_pokok' => 6000000,
            'buat_akun' => '1',
            'id_role' => $idRoleKeuangan,
            'username' => 'staf.keuangan.tst',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]));

        $response->assertRedirect(route('hrd.karyawan.index'));

        $karyawan = DB::table('tbl_karyawan')->where('nip', 'TST-001')->first();
        $this->assertSame('Aktif', $karyawan->status);
        $this->assertNull($karyawan->id_jenis_layanan);

        $akun = DB::table('tbl_users')->where('id_karyawan', $karyawan->id)->first();
        $this->assertSame('staf.keuangan.tst', $akun->username);
        $this->assertSame($idRoleKeuangan, $akun->id_role);
        $this->assertTrue(Hash::check('rahasia123', $akun->password));
    }

    public function test_validasi_berbahasa_indonesia(): void
    {
        $response = $this->post(route('hrd.karyawan.store'), ['kategori' => 'Lapangan', 'no_ktp' => '123']);

        $response->assertSessionHasErrors([
            'nip' => 'NIP wajib diisi.',
            'no_ktp' => 'Nomor KTP harus 16 digit angka.',
            'tgl_lahir' => 'Tanggal lahir wajib diisi.',
        ]);
    }

    public function test_ubah_karyawan_internal_tidak_boleh_mengosongkan_gaji_dan_nip_tetap(): void
    {
        $id = $this->buatKaryawanLangsung(['kategori' => 'Internal', 'gaji_pokok' => 5000000]);
        $data = $this->dataKaryawan(['nama' => 'Nama Baru', 'nip' => 'GANTI-NIP', 'kategori' => 'Lapangan']);

        $this->put(route('hrd.karyawan.update', $id), [...$data, 'gaji_pokok' => ''])
            ->assertSessionHasErrors(['gaji_pokok' => 'Gaji pokok tidak boleh dikosongkan.']);

        $this->put(route('hrd.karyawan.update', $id), [...$data, 'gaji_pokok' => 5500000])
            ->assertRedirect(route('hrd.karyawan.index'));

        $karyawan = DB::table('tbl_karyawan')->find($id);
        $this->assertSame('Nama Baru', $karyawan->nama);
        $this->assertSame('TST-001', $karyawan->nip);
        $this->assertSame('Internal', $karyawan->kategori);
        $this->assertEquals(5500000, $karyawan->gaji_pokok);
    }

    public function test_halaman_sertifikat_memakai_fn_sisa_hari_dengan_badge_merah_di_bawah_30_hari(): void
    {
        $id = $this->buatKaryawanLangsung();
        DB::table('tbl_sertifikat')->insert([
            ['id_karyawan' => $id, 'nama_dokumen' => 'Dokumen Mendesak TST', 'tgl_terbit' => now()->subYear()->toDateString(), 'tgl_kadaluarsa' => now()->addDays(10)->toDateString()],
            ['id_karyawan' => $id, 'nama_dokumen' => 'Dokumen Aman TST', 'tgl_terbit' => now()->subYear()->toDateString(), 'tgl_kadaluarsa' => now()->addDays(90)->toDateString()],
        ]);

        $response = $this->get(route('hrd.sertifikat.index', ['q' => 'TST']));

        $response->assertOk();
        $response->assertSee('<span class="badge badge-danger">10 hari</span>', false);
        $response->assertSee('<span class="badge badge-success">90 hari</span>', false);
        $response->assertSeeInOrder(['Dokumen Mendesak TST', 'Dokumen Aman TST']);
    }

    public function test_pool_hanya_menampilkan_lapangan_tersedia_dari_view(): void
    {
        $this->buatKaryawanLangsung(['nip' => 'TST-POOL', 'nama' => 'Siap Tugas TST', 'no_ktp' => '1111111111111111']);
        $this->buatKaryawanLangsung(['nip' => 'TST-INT', 'nama' => 'Staf Kantor TST', 'no_ktp' => '2222222222222222', 'kategori' => 'Internal', 'gaji_pokok' => 5000000]);

        $response = $this->get(route('hrd.pool', ['q' => 'TST']));

        $response->assertOk();
        $response->assertSee('Siap Tugas TST');
        $response->assertDontSee('Staf Kantor TST');
    }

    public function test_hapus_karyawan_yang_punya_riwayat_ditolak_dengan_pesan(): void
    {
        $id = $this->buatKaryawanLangsung();
        DB::table('tbl_sertifikat')->insert(['id_karyawan' => $id, 'nama_dokumen' => 'KTA', 'tgl_terbit' => '2025-01-01', 'tgl_kadaluarsa' => '2027-01-01']);

        $this->delete(route('hrd.karyawan.destroy', $id))->assertSessionHas('error');

        $this->assertDatabaseHas('tbl_karyawan', ['id' => $id]);
        $this->assertDatabaseHas('tbl_users', ['id_karyawan' => $id]);
    }

    public function test_hapus_karyawan_tanpa_riwayat_ikut_menghapus_akun(): void
    {
        $id = $this->buatKaryawanLangsung();

        $this->delete(route('hrd.karyawan.destroy', $id))->assertSessionHas('success');

        $this->assertDatabaseMissing('tbl_karyawan', ['id' => $id]);
        $this->assertDatabaseMissing('tbl_users', ['id_karyawan' => $id]);
    }

    public function test_semua_halaman_hrd_dapat_dibuka(): void
    {
        $id = $this->buatKaryawanLangsung();

        foreach ([
            route('hrd.dashboard'),
            route('hrd.karyawan.index'),
            route('hrd.karyawan.create'),
            route('hrd.karyawan.edit', $id),
            route('hrd.pool'),
            route('hrd.sertifikat.index'),
            route('hrd.sertifikat.create'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /**
     * @param  array<string, mixed>  $timpa
     * @return array<string, mixed>
     */
    private function dataKaryawan(array $timpa = []): array
    {
        return [
            'nip' => 'TST-001',
            'nama' => 'Karyawan Uji',
            'no_ktp' => '9999999999999999',
            'tempat_lahir' => 'Medan',
            'tgl_lahir' => '1995-05-17',
            'jenis_kelamin' => 'L',
            'alamat' => 'Jl. Uji No. 1',
            'no_telp' => '081234567890',
            'tgl_masuk' => '2026-01-05',
            ...$timpa,
        ];
    }

    /**
     * @param  array<string, mixed>  $timpa
     */
    private function buatKaryawanLangsung(array $timpa = []): int
    {
        $data = [
            ...$this->dataKaryawan(),
            'kategori' => 'Lapangan',
            'id_jenis_layanan' => $this->idLayanan('Driver'),
            ...$timpa,
        ];

        return DB::table('tbl_karyawan')->insertGetId($data);
    }

    private function idLayanan(string $nama): int
    {
        return DB::table('tbl_jenis_layanan')->where('nama_layanan', $nama)->value('id');
    }
}
